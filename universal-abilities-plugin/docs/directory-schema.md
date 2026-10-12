# Ability-Pack Manifest Schema

Agent Connector for WP reads a published **ability-pack manifest** to learn which
of the plugins installed on a site have a companion "ability pack" available, then
lets the admin install/activate them and keeps them updated. By default the
manifest is the GitHub `pack-index` release asset the release CI generates.

- **Default URL:** `https://github.com/soflyy/agent-connector-for-wp/releases/download/pack-index/index.json`
- **Override filter:** `agent_connector_for_wp_pack_index_url`
  ```php
  add_filter( 'agent_connector_for_wp_pack_index_url', fn() => 'https://example.com/pack-index.json' );
  ```
- **Request:** `GET` (`wp_remote_get`, 5s timeout). The full manifest is fetched and
  filtered against the installed plugins locally.
- **Caching:** the manifest is cached in the `agent_connector_for_wp_directory_cache`
  transient for 12 hours. On a fetch failure the last cached copy is reused (flagged
  "stale"); with no cache at all the UI shows a clean "unavailable" state. A manual
  **Refresh now** button busts the cache.

## Response shape

A JSON **object** with an `entries` array (the installable packs). A bare array is
also accepted and treated as `entries`. Any other top-level keys are ignored.

```json
{
  "entries": [ /* entry objects (the generated packs) */ ]
}
```

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
nonce; the download URL is re-resolved server-side from this manifest (never from
the browser). Once a pack is installed, the host keeps it updated by injecting its
latest `version`/`download_url` into WordPress's normal plugin-update flow.

Slug matching is robust to folder-vs-full-path differences and to single-file
plugins (e.g. `hello.php` ⇄ `hello`).

## Companion-plugin targeting convention

This manifest keys each pack by the **WP plugin it extends** — the `target_plugin`
field. That is the single join key shared with the ability-API side: a companion
"ability pack" declares the same value in its `Agent Connector Target:` plugin
header (see [registering-abilities.md](registering-abilities.md)). A manifest
entry's `target_plugin` and a pack's `Agent Connector Target:` header must match
for the pack to surface against an installed plugin.

See [`directory.json`](directory.json) for a working sample.
