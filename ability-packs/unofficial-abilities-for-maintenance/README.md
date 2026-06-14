# Unofficial Abilities for Maintenance

A companion **ability pack** for [Agent Connector for WP](../agent-connector-for-wp) that gives an
agent full control of the **[Maintenance](https://wordpress.org/plugins/maintenance/)** plugin
(WP Maintenance by WebFactory Ltd) over MCP, via the WordPress Abilities API.

You write **no** auth/permission/domain-lock/audit code — Agent Connector injects all of it for
every ability registered here.

## What an agent can do

Everything a human admin can do on the free Maintenance settings page:

- **Toggle maintenance mode** — `maintenance/enable`, `maintenance/disable`, `maintenance/get-status`
- **Edit page text** — `maintenance/update-content` (title, headline, HTML description, footer,
  "show some love" credit, front-end login toggle)
- **Style the page** — `maintenance/update-design` (background/font/login colors, font family +
  subset, background blur, logo sizing, image slots) with `maintenance/list-fonts` to discover
  valid fonts
- **Set a logo/background from a URL** — `maintenance/set-image-from-url` (sideloads into the
  media library and assigns the slot)
- **Advanced/SEO** — `maintenance/update-advanced` (Google Analytics ID, 503 response, custom CSS)
- **Exclude pages** — `maintenance/update-excluded-pages` (delta add/remove/clear) with
  `maintenance/list-excludable-pages`
- **Inspect** — `maintenance/get-settings`, `maintenance/describe-settings`

Every update ability takes a **delta** — pass only the fields you want to change; everything else
is preserved.

## How it works

The Maintenance plugin keeps all of its configuration in a single option, `maintenance_options`,
and its only write path (the admin form handler) is POST-only and rebuilds the whole array, dropping
any key it doesn't know. So the pack uses **load-modify-save**: it reads the current option through
the plugin's own loader (`mtnc_get_plugin_options()`), overlays the caller's delta, applies the
**exact** per-field sanitization the plugin's save handler uses, writes the merged array back, and
clears caches via `MTNC::mtnc_clear_cache()` — preserving every other key, including PRO-only ones.
All of that lives in `src/Adapter/MaintenanceStore.php`.

## Layout

```
unofficial-abilities-for-maintenance.php   main file — headers + bootstrap
bootstrap.php                              ACFW_Pack registry (don't edit)
abilities/
  status.php                               category + get-status / enable / disable
  settings.php                             get-settings / update-content / update-advanced / describe-settings
  design.php                               update-design / list-fonts / set-image-from-url
  excluded-pages.php                       list-excludable-pages / update-excluded-pages
src/Adapter/MaintenanceStore.php           load-modify-save helpers, sanitizers, read views, fonts
coverage/
  inventory.json                           structured understanding of the target
  coverage-report.md                       coverage deliverable
```

## Verification

All 12 abilities were exercised through the MCP server and triangulated against the database and the
live rendered page (incl. HTTP 503 status, login-form suppression, excluded-page bypass). See
`coverage/coverage-report.md`. Note the sandbox `FS_METHOD='direct'` requirement documented there —
it's a quirk of the stock plugin's front-end font loading, not of this pack.
