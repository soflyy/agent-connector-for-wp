# Ability-Pack Directory Schema

Agent Connector for WP fetches a remote JSON **directory** of companion "ability
pack" plugins and matches it against the plugins installed on a site, so an admin
can see which of their plugins have an available ability pack.

- **Default URL:** `https://raw.githubusercontent.com/soflyy/agent-connector-for-wp-directory/main/directory.json`
  (a **placeholder** — there is no live endpoint yet; point it at the real one).
- **Override filter:** `agent_connector_for_wp_directory_url`
  ```php
  add_filter( 'agent_connector_for_wp_directory_url', fn() => 'https://example.com/directory.json' );
  ```
- **Caching:** fetched with `wp_remote_get` (5s timeout) and cached in the
  `agent_connector_for_wp_directory_cache` transient for 12 hours. On a fetch
  failure the last cached copy is reused (flagged "stale"); with no cache at all
  the UI shows a clean "directory unavailable" state. A manual **Refresh now**
  button busts the cache.

## Shape

Two accepted top-level forms; pick whichever you like:

1. A bare JSON **array** of entry objects, or
2. A JSON **object** with an `entries` array (room for future top-level metadata):

```json
{
  "entries": [ /* entry objects */ ]
}
```

The client normalizes both to the same internal list.

## Entry object

| Field               | Required | Type   | Notes |
| ------------------- | :------: | ------ | ----- |
| `host_plugin_slug`  | **yes**  | string | The host WP plugin this pack targets. Either a full plugin file (`woocommerce/woocommerce.php`) or a bare folder slug (`woocommerce`). Matching tolerates both forms. |
| `ability_pack_name` | **yes**  | string | Human-readable name of the ability pack. |
| `host_plugin_name`  | no       | string | Display name of the host plugin (the installed plugin's own name is preferred when present). |
| `ability_pack_slug` | no       | string | The companion plugin's own slug (`folder/file.php` or folder). When given, the client also reports whether the pack itself is already installed/active. |
| `source_url`        | no       | string | Where to get the pack (GitHub repo, wp.org page, etc.). Rendered as a link. |
| `description`       | no       | string | One-line description shown in the table. |
| `version`           | no       | string | Latest published version of the pack. |

Entries missing either required field are **silently ignored**. All values are
trimmed; non-string values are coerced to empty strings.

## Matching behavior

For each entry whose `host_plugin_slug` resolves to an **installed** plugin
(via `get_plugins()`), the UI shows a row with the installed plugin name, the
ability pack (linked to `source_url`), and a status:

- **Available to add** — host plugin installed, pack not installed.
- **Installed, inactive** — `ability_pack_slug` is installed but not active.
- **Installed & active** — `ability_pack_slug` is installed and active.

Slug matching is robust to folder-vs-full-path differences and to single-file
plugins (e.g. `hello.php` ⇄ `hello`).

## Companion-plugin targeting convention

This directory keys each pack by the **host WP plugin** it extends
(`host_plugin_slug`). That should line up with whatever convention the
ability-API side uses to declare which host plugin a companion targets — when
reconciling, treat `host_plugin_slug` as the join key.

See [`directory.json`](directory.json) for a working sample.
