# Migration Mode Abilities for Agent Connector

Flip WordPress plugins **on/off per request** so a single URL can be rendered as
if only a chosen set of plugins ("profile") were active. Built so an agent can
migrate a site between page builders / plugin stacks (e.g. Elementor + EDD +
AffiliateWP → Oxygen + WooCommerce + Solid Affiliate) **on the live site, without
a staging clone** — viewing the same page first as the old builder, then as the
new one, and fixing the new build until it matches.

This is **not IP-based** (unlike Breakdance's Migration Mode, which only gates its
own rendering by `$_SERVER['REMOTE_ADDR']` and never disables other builders).
Here the active-plugin list itself is rewritten per request, gated by a secret
token.

## How it works

WordPress loads the active-plugin list in `wp_get_active_and_valid_plugins()`
(`wp-settings.php`), reading `get_option('active_plugins')` through the
**`option_active_plugins`** filter *before any plugin is included*. A normal
plugin runs too late to filter its own request, so the switching logic ships as a
**must-use plugin** (`wp-content/mu-plugins/migration-mode-switch.php`), which
loads earlier. No WordPress core modification is required.

- **Default request (no signal):** nothing is filtered — the full real stack
  loads. MCP editing of either builder works normally.
- **Request carrying a valid profile + token:** among the *managed* plugins (the
  union of every plugin named across all profiles), only those listed in the
  requested profile stay active; *protected* plugins always stay on; every other
  plugin is left untouched. No database writes — the change is in-memory, for that
  request only.

The profile + token can be supplied three ways (checked in this order):
`?acm_profile=…&acm_token=…` query string, `X-ACM-Profile` / `X-ACM-Token`
headers, or `acm_profile` / `acm_token` cookies. The first hit also sets sticky
cookies so the page's asset/AJAX sub-requests inherit the same profile.

### Safety

- A **secret token** gates the switch (`hash_equals`, constant-time). Without it
  the switch is completely inert, so a random visitor cannot disable your
  security/membership plugins. Rotate with `repair-mu`.
- `/wp-admin/`, `wp-login.php`, and cron are **never** filtered → no lock-out.
- **Protected** plugins (Agent Connector, this plugin, the default-abilities pack)
  are never disabled, so MCP keeps working under every profile.
- To fully disable everything: delete the mu-plugin file. This is a dev/staging
  migration tool, not a production access-control layer.

### Dependency closure (important)

A profile must list a plugin's **whole dependency closure**. If you keep plugin A
but drop plugin B that A (or another still-active plugin) requires, the request
will fatal — that's the host plugin's own dependency, not the switch
misbehaving. Build each "stack" profile to include every plugin it needs.

## MCP abilities

| Ability | Purpose |
| --- | --- |
| `migration-mode/get-status` | Current config: enabled, mu-plugin installed/version, profiles, managed/protected sets, home URL. Read first. |
| `migration-mode/list-plugins` | Every installed plugin with exact `file`, name, version, active state. Use the `file` values in profiles. |
| `migration-mode/set-profiles` | Define the profiles (replaces the set). Derives the managed set as the union of all profiles' plugins. |
| `migration-mode/get-preview-url` | Token-signed URL (+ header/cookie equivalents) to view a path under a profile. Hand the `url` to a browser/Playwright. |
| `migration-mode/repair-mu` | (Re)install/update the mu-plugin; optionally rotate the secret. |

Abilities are registered via the WordPress Abilities API and auto-exposed over MCP
by Agent Connector's mcp-adapter — no custom MCP server.

## Typical agent workflow

1. `list-plugins` → learn exact plugin files.
2. `set-profiles` → define e.g. `source` (only Elementor + add-ons) and `target`
   (only Oxygen + add-ons), each with its full dependency closure.
3. `get-preview-url profile=source path=/` → navigate Playwright there, screenshot
   the old homepage.
4. Rebuild the homepage in the target builder via that builder's MCP abilities.
5. `get-preview-url profile=target path=/` → screenshot the new homepage; diff
   against the source screenshot; fix gaps; repeat.
6. Append `&acm_debug=1` to any preview URL to get an `X-ACM-Active-Plugins`
   response header confirming exactly which plugins loaded.

## Files

```
migration-mode-abilities.php          bootstrap: autoload, activation → install mu-plugin, register abilities
mu/migration-mode-switch.php          the must-use per-request switch (copied into mu-plugins/)
src/Config.php                        shared option keys + helpers
src/MuInstaller.php                   installs/updates the mu-plugin (version-stamped, self-healing)
src/Plugin.php                        registers the ability category + abilities
src/Abilities/*.php                   the five abilities
```

Shared options (read by the mu-plugin, written by the abilities):
`acm_migration_enabled`, `acm_migration_secret`, `acm_migration_profiles`,
`acm_migration_managed`, `acm_migration_protected`.
