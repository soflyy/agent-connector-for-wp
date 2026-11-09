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
| `version`           | no       | string | Latest published version of the pack. Omit for install-only listings whose updates are managed elsewhere; normalized to an empty string, never offered as an update by PackUpdater, and no version label is displayed. |
| `download_url`      | no       | string | Direct URL to the plugin's installable `.zip`. Required for the one-click **Install** button and for host-managed updates. Only `https` URLs on an allowlisted host are installed (see below; filter `agent_connector_for_wp_pack_download_hosts`). |

`ability_pack_name` may also be supplied as `name`.

Entries missing either required field are **silently ignored**. All values are
trimmed; non-string values are coerced to empty strings.

## Matching behavior

For each entry whose `target_plugin` resolves to an **active installed** plugin
(via `get_plugins()`), the UI shows a row with the installed plugin name, the
ability pack (linked to `source_url`), and a status:

- **Available to add** — host plugin installed, pack not installed. Shows an
  **Install** button (downloads `download_url`, installs, and activates).
- **Installed, inactive** — `ability_pack_slug` is installed but not active. Shows
  an **Activate** button.
- **Installed & active** — `ability_pack_slug` is installed and active.

Install/activate require the `install_plugins`/`activate_plugins` capabilities and a
nonce; the download URL is re-resolved server-side from this manifest (never from
the browser). Multiple entries can target the same plugin; each gets its own row
and install/activate controls. Once a pack is installed, the host keeps it updated
through WordPress's normal plugin-update flow only when it carries the
`Agent Connector` marker header and its manifest entry has a nonempty `version`.
Versionless entries remain installable and never opt into these updates.

Slug matching is robust to folder-vs-full-path differences and to single-file
plugins (e.g. `hello.php` ⇄ `hello`). Folder matching uses `get_plugins()` rather
than assuming `<folder>/<folder>.php`: `elevated-product-search` resolves to
`elevated-product-search/plugin.php`, while `elevated-discount-rules` resolves to
`elevated-discount-rules/elevated-discount-rules.php`. Active detection and the
Enable/Disable actions use that resolved file.

## External plugins

`ability-packs/external.json` is a hand-maintained array of plugins developed
outside this repository that provide their own agent abilities. The manifest
step in `.github/workflows/ability-packs-release.yml` appends these entries to
the released generated packs using `jq`. External entries need no local source
directory or release tag. Generated packs without release tags are still omitted.

The two external listings target WooCommerce: **Elevated Product Search** (live,
typo-tolerant product search) and **Elevated Discount Rules** (bulk/tiered pricing,
buy X get Y and cart discounts). Both include their own agent abilities and omit
`version`; the Elevated Extensions library manages their updates. They do not
carry the `Agent Connector` header either, so PackUpdater ignores them even if a
version were supplied.

For example, this is an installable versionless entry:

```json
{
  "target_plugin": "woocommerce",
  "ability_pack_slug": "elevated-product-search",
  "ability_pack_name": "Elevated Product Search",
  "description": "Live, typo-tolerant WooCommerce product search with its own agent abilities.",
  "download_url": "https://elevatedextensions.com/latest/elevated-product-search.zip",
  "source_url": "https://elevatedextensions.com/extensions/elevated-product-search/"
}
```

Both the admin-post installer and the REST installer default to these download
hosts (including their subdomains): `github.com`, `objects.githubusercontent.com`,
`codeload.github.com`, and `elevatedextensions.com`. HTTP URLs, other hosts and
lookalike suffixes such as `elevatedextensions.com.example.org` are rejected.
Use `agent_connector_for_wp_pack_download_hosts` to override this list.

## Companion-plugin targeting convention

This manifest keys each pack by the **WP plugin it extends** — the `target_plugin`
field. That is the single join key shared with the ability-API side: a companion
"ability pack" declares the same value in its `Agent Connector Target:` plugin
header (see [registering-abilities.md](registering-abilities.md)). A manifest
entry's `target_plugin` corresponds to that header for generated companion packs.
External plugins do not need either marker header to appear in the directory;
their manifest entry supplies the target.

See [`directory.json`](directory.json) for a working sample.
