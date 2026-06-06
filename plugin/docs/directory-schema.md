# Ability-Pack Directory Schema

Agent Connector for WP asks a remote **match endpoint** which of the plugins
installed on a site have a companion "ability pack" available, then lets the admin
install/activate them and keeps them updated. By default that endpoint is the
[agent-ready-plugins-tracker](../../agent-ready-plugins-tracker/) app, which serves
the data from the GitHub `pack-index` manifest the release CI generates.

- **Default URL:** `https://agent-ready-plugins-tracker-git-master-future-layer.vercel.app/api/ability-packs/match`
  (the endpoint must be **publicly reachable** — disable Vercel Deployment
  Protection for it, or point the filter below at a public domain).
- **Override filter:** `agent_connector_for_wp_directory_url`
  ```php
  add_filter( 'agent_connector_for_wp_directory_url', fn() => 'https://example.com/api/ability-packs/match' );
  ```
- **Request:** `POST` (`wp_remote_post`, 5s timeout) with a JSON body listing the
  site's installed plugins so the endpoint can return only relevant packs:
  ```json
  {
    "site_url": "https://example.com",
    "plugins": [
      { "slug": "contact-form-7", "file": "contact-form-7/wp-contact-form-7.php", "active": true }
    ]
  }
  ```
- **Caching:** the response is cached in the
  `agent_connector_for_wp_directory_cache` transient for 12 hours, fingerprinted by
  the installed-plugin set (installing/removing a plugin invalidates it). On a fetch
  failure the last cached copy is reused (flagged "stale"); with no cache at all the
  UI shows a clean "directory unavailable" state. A manual **Refresh now** button
  busts the cache.

## Response shape

A JSON **object** with an `entries` array (a bare array is also accepted):

```json
{
  "entries": [ /* entry objects */ ]
}
```

The client normalizes both to the same internal list.

## Entry object

| Field                | Required | Type   | Notes |
| -------------------- | :------: | ------ | ----- |
| `target_plugin`      | **yes**  | string | The WP plugin this pack extends — the **join key**. Either a full plugin file (`woocommerce/woocommerce.php`) or a bare folder slug (`woocommerce`). Matching tolerates both forms. This is the same value the pack declares in its `Agent Connector Target:` header. |
| `ability_pack_name`  | **yes**  | string | Human-readable name of the ability pack. |
| `target_plugin_name` | no       | string | Display name of the target plugin (the installed plugin's own name is preferred when present). |
| `ability_pack_slug` | no       | string | The companion plugin's own slug (`folder/file.php` or folder). When given, the client also reports whether the pack itself is already installed/active. |
| `source_url`        | no       | string | Where to get the pack (GitHub repo, wp.org page, etc.). Rendered as a link. |
| `description`       | no       | string | One-line description shown in the table. |
| `version`           | no       | string | Latest published version of the pack. |
| `download_url`      | no       | string | Direct URL to the pack's installable `.zip` (a GitHub release asset). Required for the one-click **Install** button and for host-managed updates. Only `https` URLs on an allowlisted host (GitHub by default; filter `agent_connector_for_wp_pack_download_hosts`) are installed. |

`ability_pack_name` may also be supplied as `name`.

Entries missing either required field are **silently ignored**. All values are
trimmed; non-string values are coerced to empty strings.

## Matching behavior

For each entry whose `target_plugin` resolves to an **installed** plugin
(via `get_plugins()`), the UI shows a row with the installed plugin name, the
ability pack (linked to `source_url`), and a status:

- **Available to add** — host plugin installed, pack not installed. Shows an
  **Install** button (downloads `download_url`, installs, and activates).
- **Installed, inactive** — `ability_pack_slug` is installed but not active. Shows
  an **Activate** button.
- **Installed & active** — `ability_pack_slug` is installed and active.

Install/activate require the `install_plugins`/`activate_plugins` capabilities and a
nonce; the download URL is re-resolved server-side from this directory (never from
the browser). Once a pack is installed, the host keeps it updated by injecting its
latest `version`/`download_url` into WordPress's normal plugin-update flow.

Slug matching is robust to folder-vs-full-path differences and to single-file
plugins (e.g. `hello.php` ⇄ `hello`).

## Companion-plugin targeting convention

This directory keys each pack by the **WP plugin it extends** — the `target_plugin`
field. That is the single join key shared with the ability-API side: a companion
"ability pack" declares the same value in its `Agent Connector Target:` plugin
header (see [registering-abilities.md](registering-abilities.md)). A directory
entry's `target_plugin` and a pack's `Agent Connector Target:` header must match
for the pack to surface against an installed plugin.

See [`directory.json`](directory.json) for a working sample.
