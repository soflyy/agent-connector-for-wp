# Coverage report — Maintenance (maintenance v4.21)

This report accounts for every surface and task in `inventory.json`: each maps to a
registered ability or an explicit exclusion with a reason.

## Status summary

- Surfaces: 11 total — 6 covered, 5 excluded (with reasons), 0 open
- Tasks: 7 total — 7 covered, 0 open
- Abilities registered: 12 — all passing acceptance? **yes**
- Discovery saturation: **yes** — the plugin has a single data store (`maintenance_options`)
  and one admin form; UI-walking + code re-reads + DB diffing surfaced nothing new after the
  first pass. No REST routes, CPTs, taxonomies, shortcodes, blocks, or cron.

## Abilities

| Ability | Kind | Implementation |
|---------|------|----------------|
| `maintenance/get-status` | read | reads `mtnc_get_plugin_options()` |
| `maintenance/enable` | action | load-modify-save (`state=1`) |
| `maintenance/disable` | action | load-modify-save (`state=0`) |
| `maintenance/get-settings` | read | reads plugin loader, grouped view |
| `maintenance/update-content` | action (delta) | load-modify-save |
| `maintenance/update-design` | action (delta) | load-modify-save + hex/font/attachment validation |
| `maintenance/update-advanced` | action (delta) | load-modify-save |
| `maintenance/set-image-from-url` | action | `media_sideload_image()` → assign slot |
| `maintenance/update-excluded-pages` | action (delta add/remove/clear) | resolves post types, load-modify-save |
| `maintenance/list-excludable-pages` | read/introspection | mirrors the plugin's exclude UI query |
| `maintenance/list-fonts` | introspection | reads the plugin's Bunny `fonts.json` + standard stacks |
| `maintenance/describe-settings` | introspection | static schema of every setting |

All writes go through one adapter (`src/Adapter/MaintenanceStore.php`) that reads the current
option via the plugin's own loader, overlays only the caller's delta, applies the **exact**
per-field sanitization the plugin's save handler uses (`sanitize_text_field` / `wp_kses_post` /
bool / int), writes the whole merged array back, and clears caches via `MTNC::mtnc_clear_cache()`.
This preserves every other key — including PRO-only keys the plugin's own form would silently drop.

## Task coverage

| Task (verb) | Ability(ies) | Implementation | Acceptance |
|-------------|--------------|----------------|-----------|
| enable/disable maintenance | `maintenance/enable`, `maintenance/disable`, `maintenance/get-status` | load-modify-save | ✅ MCP + DB + front-end (logged-out sees maintenance page when ON / real site when OFF) |
| edit page text | `maintenance/update-content` | load-modify-save (delta) | ✅ MCP + DB + front-end (title, heading, HTML description, footer, credit link, login-form suppressed when `is_login=false`) |
| style the page | `maintenance/update-design`, `maintenance/list-fonts` | load-modify-save (delta) | ✅ MCP + DB + front-end (bg/font/login colors, `blur(8px)`, Roboto Slab font link, logo width) |
| configure advanced/SEO | `maintenance/update-advanced` | load-modify-save (delta) | ✅ MCP + DB + front-end (HTTP **503** returned; analytics correctly suppressed while 503 on; custom CSS emitted) |
| exclude pages | `maintenance/update-excluded-pages`, `maintenance/list-excludable-pages` | resolve post types + load-modify-save | ✅ MCP + DB + front-end (excluded page serves HTTP 200 normally while home stays behind maintenance; remove re-blocks it) |
| upload/assign images | `maintenance/set-image-from-url`, `maintenance/update-design` | media sideload / attachment-id | ✅ MCP + DB + front-end (logo `<img>` rendered from new attachment) |
| preview the page | `maintenance/get-status` / `get-settings` expose `preview_url` | reads `home_url('?maintenance-preview')` | ✅ URL returned; preview path renders the same template |

> Acceptance was performed by calling each ability through the MCP server
> (`mcp-adapter-execute-ability`) exactly as an agent would, then confirming the result from
> independent vantage points: the `maintenance_options` row (`wp eval`/`wp option get`) and the
> live rendered page / HTTP status (`curl` as a logged-out visitor). Bad inputs were also
> checked (invalid font family and invalid hex color are rejected with actionable errors).

## Surface coverage

| Surface (kind:id) | Mapped to | Covered? | Reason if excluded |
|-------------------|-----------|----------|--------------------|
| setting:settings-save-handler | all `update-*` + enable/disable | ✅ | The pack writes the same option the form writes. |
| admin_page:admin-page-maintenance | get/update abilities | ✅ | Every free control on the page is an ability. |
| hook:front-template-include | enable/disable + exclude-pages | ✅ | Driven by `state` + `exclude_pages`. |
| hook:http-503 | `update-advanced` (`503_enabled`) | ✅ | Verified the 503 status is sent. |
| other:front-login | `update-content` (`is_login`) | ✅ | Toggle covered; role gate is PRO (excluded below). |
| other:admin-bar-status | `get-status` | ✅ | Reflects the same `state`. |
| ajax_action:ajax-dismiss-dialog | — | ❌ excluded | Admin-UI bookkeeping (dismiss a promo dialog); writes `maintenance_meta`, no user-facing config. |
| ajax_action:ajax-dismiss-notice | — | ❌ excluded | Same — dismiss the welcome pointer. |
| other:admin-action-install-wpfssl | — | ❌ excluded | Downloads/installs a *different* third-party plugin from .org; out of scope for controlling Maintenance, needs external network. Use core WP plugin tooling instead. |
| other:admin-action-install-wpcaptcha | — | ❌ excluded | Same — installs Advanced Google reCAPTCHA. |
| other:admin-action-install-weglot | — | ❌ excluded | Same — installs Weglot. |

### Excluded settings (PRO / non-persisted), with reasons

- `expiry_date_start`, `expiry_date_end`, `expiry_time_start`, `expiry_time_end`, `is_down`
  (scheduled/countdown window) and `roles_array` (front-login role gate): **PRO-only**. The free
  template *reads* them, but the free plugin ships no UI and its save handler never writes them, so
  a free admin cannot set them — out of scope for "what a human admin can do with this (free) plugin."
- `weglot` checkbox: rendered in the free admin form but the free save handler does **not** persist
  it (multilingual support is actually driven by whether the Weglot plugin is active). No-op to expose.
- Themes metabox: every theme is PRO and only links to an external preview/upsell; nothing to set.
- `default_settings`: internal bookkeeping flag; the pack sets it to `false` on every write, exactly
  as the plugin's own save does.

## Known boundaries

- **Stock-plugin sandbox quirk (not the pack):** the Maintenance plugin's front-end template
  (`load/index.php`) throws a fatal (`property_exists(NULL, ...)` in `mtnc_get_bunny_font`) when
  WordPress' `WP_Filesystem` cannot read the bundled `includes/fonts/fonts.json` via the `direct`
  method — which happens here because the plugin files are owned by a different user than the web
  server. Setting `FS_METHOD='direct'` in `wp-config.php` resolves it (and is left in place so the
  page renders). This affects the plugin's own rendering and its font reader, independent of the
  ability pack; the pack's `list-fonts` simply surfaces whatever that reader returns.
- Multisite: out of scope.
- The three "install another plugin" admin actions are intentionally excluded (see table).
