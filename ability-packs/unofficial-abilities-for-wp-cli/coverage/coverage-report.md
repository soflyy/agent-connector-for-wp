# Coverage report — Unofficial Abilities for WP-CLI

**Target:** WP-CLI 2.12.0 command surface, reimplemented in native PHP against
WordPress core (no `wp` binary, no shell, no `proc_open`/`mysqldump`).
**Abilities registered:** 213, all under the `wp-cli/` namespace.
**Standard of done:** an agent that would reach for `wp <command>` can call the
matching `wp-cli/<command>-<subcommand>` ability instead and get the same
real-world result. Every ability below was exercised through the MCP server
(`mcp-adapter-execute-ability`) and triangulated against the database/filesystem
with the real `wp` CLI and `$wpdb`.

## Why this pack exists

The built-in `agent-connector-for-wp/wp-cli` ability shells out to the `wp`
binary. On hosts with no WP-CLI installed, with `proc_open`/`shell_exec`
disabled, or where the web user can't spawn processes, that ability fails and
the agent is stuck. This pack is the fallback: same command vocabulary, pure-PHP
implementation, works anywhere WordPress runs. Ability names mirror WP-CLI
verbatim so the mapping is mechanical: `wp option get` → `wp-cli/option-get`,
`wp post create` → `wp-cli/post-create`, `wp search-replace` →
`wp-cli/db-search-replace`.

## Coverage by command family

Legend: ✅ covered · ⚠️ covered with a documented caveat · ❌ excluded (reason given)

### `wp option`
| WP-CLI | Ability | Status |
| --- | --- | --- |
| option get | wp-cli/option-get | ✅ |
| option add | wp-cli/option-add | ✅ |
| option update / set | wp-cli/option-update | ✅ |
| option delete | wp-cli/option-delete | ✅ |
| option list | wp-cli/option-list | ✅ |
| option pluck | wp-cli/option-pluck | ✅ |
| option patch | wp-cli/option-patch | ✅ |
| option get-autoload | wp-cli/option-get-autoload | ✅ |
| option set-autoload | wp-cli/option-set-autoload | ✅ |

### `wp post` / `wp post-meta` / `wp post-term`
| WP-CLI | Ability | Status |
| --- | --- | --- |
| post get | wp-cli/post-get | ✅ |
| post list | wp-cli/post-list | ✅ |
| post create | wp-cli/post-create | ✅ |
| post update / edit | wp-cli/post-update | ✅ |
| post delete | wp-cli/post-delete | ✅ |
| post exists | wp-cli/post-exists | ✅ |
| post generate | wp-cli/post-generate | ✅ |
| post meta get/list/add/update/delete | wp-cli/post-meta-* | ✅ |
| post meta pluck/patch | — | ❌ niche; use post-meta-get/update with a JSON value |
| post term list/set/add/remove | wp-cli/post-term-* | ✅ |

### `wp post-type` / `wp taxonomy` (introspection)
| WP-CLI | Ability | Status |
| --- | --- | --- |
| post-type list/get | wp-cli/post-type-list, wp-cli/post-type-get | ✅ |
| taxonomy list/get | wp-cli/taxonomy-list, wp-cli/taxonomy-get | ✅ |

### `wp user` / `wp user-meta`
| WP-CLI | Ability | Status |
| --- | --- | --- |
| user get/list/create/update/delete/exists | wp-cli/user-* | ✅ |
| user generate | wp-cli/user-generate | ✅ |
| user set-role/add-role/remove-role | wp-cli/user-set-role, -add-role, -remove-role | ✅ |
| user list-caps/add-cap/remove-cap | wp-cli/user-list-caps, -add-cap, -remove-cap | ✅ |
| user reset-password | wp-cli/user-reset-password | ✅ |
| user session list/destroy | wp-cli/user-session-list, -session-destroy | ✅ |
| user meta get/list/add/update/delete | wp-cli/user-meta-* | ✅ |
| user import-csv | — | ❌ niche bulk-CSV import; loop wp-cli/user-create instead |
| user application-password * | — | ❌ niche; managed in profile / via REST |
| user spam / unspam | — | ❌ multisite-only |

### `wp role` / `wp cap`
| WP-CLI | Ability | Status |
| --- | --- | --- |
| role list/get/create/delete/exists/reset | wp-cli/role-* | ✅ (role-reset only restores built-in default roles) |
| cap list/add/remove | wp-cli/cap-* | ✅ |

### `wp term` / `wp term-meta`
| WP-CLI | Ability | Status |
| --- | --- | --- |
| term get/list/create/update/delete/exists | wp-cli/term-* | ✅ |
| term recount | wp-cli/term-recount | ✅ |
| term generate | wp-cli/term-generate | ✅ |
| term meta get/list/add/update/delete | wp-cli/term-meta-* | ✅ |

### `wp comment` / `wp comment-meta`
| WP-CLI | Ability | Status |
| --- | --- | --- |
| comment get/list/create/update/delete/exists | wp-cli/comment-* | ✅ |
| comment generate | wp-cli/comment-generate | ✅ |
| comment approve/unapprove/spam/unspam/trash/untrash/status | wp-cli/comment-* | ✅ |
| comment count/recount | wp-cli/comment-count, -recount | ✅ |
| comment meta get/list/add/update/delete | wp-cli/comment-meta-* | ✅ |

### `wp plugin`
| WP-CLI | Ability | Status |
| --- | --- | --- |
| plugin list/get/status/path | wp-cli/plugin-list, -get, -status, -path | ✅ |
| plugin is-active/is-installed | wp-cli/plugin-is-active, -is-installed | ✅ |
| plugin activate/deactivate/toggle | wp-cli/plugin-activate, -deactivate, -toggle | ✅ |
| plugin install/update/delete | wp-cli/plugin-install, -update, -delete | ⚠️ install/update need network to wordpress.org (verified live) |
| plugin auto-updates status/enable/disable | wp-cli/plugin-auto-updates-* | ✅ |
| plugin verify-checksums | — | ❌ niche; use core-verify-checksums for core |

### `wp theme` / `wp theme mod`
| WP-CLI | Ability | Status |
| --- | --- | --- |
| theme list/get/status/path | wp-cli/theme-list, -get, -status, -path | ✅ |
| theme is-active/is-installed | wp-cli/theme-is-active, -is-installed | ✅ |
| theme activate | wp-cli/theme-activate | ✅ |
| theme install/update/delete | wp-cli/theme-install, -update, -delete | ⚠️ install/update need network (verified live); delete refuses the active theme |
| theme mod get/get-all/set/remove | wp-cli/theme-mod-* | ✅ |
| theme auto-updates status/enable/disable | wp-cli/theme-auto-updates-* | ✅ |
| theme enable/disable | — | ❌ multisite-only |

### `wp language core`
| WP-CLI | Ability | Status |
| --- | --- | --- |
| language core list | wp-cli/language-core-list, wp-cli/language-list | ✅ |
| language core install | wp-cli/language-core-install | ⚠️ needs network (verified live) |
| language core activate | wp-cli/language-core-activate | ✅ |
| language core uninstall | wp-cli/language-core-uninstall | ✅ |
| language core update | wp-cli/language-core-update | ⚠️ needs network |
| language plugin/theme * | — | ❌ niche; plugin/theme translation packs not covered |

### `wp menu`
| WP-CLI | Ability | Status |
| --- | --- | --- |
| menu list/create/delete | wp-cli/menu-list, -create, -delete | ✅ |
| menu item list/add-post/add-term/add-custom/delete | wp-cli/menu-item-* | ✅ |
| menu location list/assign/remove | wp-cli/menu-location-* | ✅ |
| menu item update | — | ❌ niche; delete + re-add the item |

### `wp widget` / `wp sidebar`
| WP-CLI | Ability | Status |
| --- | --- | --- |
| sidebar list | wp-cli/sidebar-list | ⚠️ lists sidebars the active theme registers at runtime (a block theme registers none) |
| widget list | wp-cli/widget-list | ✅ |
| widget add/update/delete/move/deactivate/reset | wp-cli/widget-* | ⚠️ add/move require a runtime-registered classic sidebar (block themes use the block editor, not classic widgets) |

### `wp media`
| WP-CLI | Ability | Status |
| --- | --- | --- |
| media import | wp-cli/media-import | ✅ (remote URLs need network; local paths use the WP container path) |
| media regenerate | wp-cli/media-regenerate | ✅ |
| (list) | wp-cli/media-list | ✅ (convenience; not a WP-CLI subcommand) |
| (delete) | wp-cli/media-delete | ✅ (convenience; `wp post delete` for attachments) |
| media fix-orientation | — | ❌ niche |

### `wp cache` / `wp transient`
| WP-CLI | Ability | Status |
| --- | --- | --- |
| cache get/set/add/replace/delete/incr/decr/flush/flush-group/type/supports | wp-cli/cache-* | ✅ (default object cache is per-request/non-persistent; values don't survive across calls unless an external cache is installed) |
| transient get/set/delete/type | wp-cli/transient-* | ✅ |
| transient delete --all / --expired | wp-cli/transient-delete-all, -delete-expired | ✅ |

### `wp db` / `wp search-replace`
| WP-CLI | Ability | Status |
| --- | --- | --- |
| db query | wp-cli/db-query | ✅ |
| db tables | wp-cli/db-tables | ✅ |
| db size | wp-cli/db-size | ✅ |
| db columns | wp-cli/db-columns | ✅ |
| db optimize/repair/check | wp-cli/db-optimize, -repair, -check | ✅ |
| db prefix | wp-cli/db-prefix | ✅ |
| db search | wp-cli/db-search | ✅ |
| db export | wp-cli/db-export | ✅ **native-PHP SQL dump** (DROP+CREATE+INSERT); round-trip verified by re-import |
| search-replace | wp-cli/db-search-replace | ✅ serialization-safe; **defaults to dry_run=true** |
| db import | — | ⚠️ run statements via wp-cli/db-query, or restore a dump file via host shell. A pure-PHP arbitrary-SQL-file importer (statement splitting) is fragile; intentionally not shipped as an ability — see Exclusions |
| db cli / db reset / db drop / db create | — | ❌ interactive REPL / drop-or-create the whole schema: out of scope for a running-site fallback |

### `wp core`
| WP-CLI | Ability | Status |
| --- | --- | --- |
| core version | wp-cli/core-version | ✅ |
| core check-update | wp-cli/core-check-update | ⚠️ needs network |
| core is-installed | wp-cli/core-is-installed | ✅ |
| core update-db | wp-cli/core-update-db | ✅ |
| core verify-checksums | wp-cli/core-verify-checksums | ⚠️ needs network (verified live) |
| (environment info) | wp-cli/core-check-extensions | ✅ (PHP/extension/version report) |
| core download/install | — | ❌ bootstrap commands — they install WordPress *before* it runs; impossible from inside a running WP |
| core update | — | ❌ replacing core files live is high-risk and unverifiable in-sandbox; use the host or a real `wp core update` |
| core multisite-* | — | ❌ multisite out of scope |

### `wp cron`
| WP-CLI | Ability | Status |
| --- | --- | --- |
| cron event list/run/schedule/delete/unschedule | wp-cli/cron-event-* | ✅ |
| cron schedule list | wp-cli/cron-schedule-list | ✅ |
| cron test | wp-cli/cron-test | ✅ |

### `wp rewrite`
| WP-CLI | Ability | Status |
| --- | --- | --- |
| rewrite flush/list/structure | wp-cli/rewrite-flush, -list, -structure | ✅ |

### `wp config`
| WP-CLI | Ability | Status |
| --- | --- | --- |
| config get/has/list/path | wp-cli/config-get, -has, -list, -path | ✅ (secrets redacted in list/get-all) |
| config set/delete | wp-cli/config-set, -delete | ✅ (edits wp-config.php; sanity-checks the file still loads) |
| config shuffle-salts | wp-cli/config-shuffle-salts | ✅ |
| config create / edit | — | ❌ bootstrap / interactive editor; out of scope |

### `wp maintenance-mode`
| WP-CLI | Ability | Status |
| --- | --- | --- |
| maintenance-mode status | wp-cli/maintenance-mode-status | ✅ |
| maintenance-mode activate | wp-cli/maintenance-mode-activate | ⚠️ requires `confirm:true` — see "Known limitation" below |
| maintenance-mode deactivate | wp-cli/maintenance-mode-deactivate | ⚠️ unreachable over MCP *while* maintenance is active (HTTP 503) — see below |

### `wp site` (single-site subset)
| WP-CLI | Ability | Status |
| --- | --- | --- |
| site url (get/set) | wp-cli/site-url | ✅ |
| site empty | wp-cli/site-empty | ✅ requires `yes:true`; leaves the site genuinely empty (default category only) |
| (site info) | wp-cli/site-info | ✅ convenience summary |
| site list/create/delete/archive/... | — | ❌ multisite out of scope |

### `wp export` / `wp import`
| WP-CLI | Ability | Status |
| --- | --- | --- |
| export | wp-cli/export | ✅ WXR (inline or file) |
| import | wp-cli/import | ⚠️ pragmatic native WXR importer (posts, terms, meta, basic comments; does not fetch remote media or create authors) |

## Excluded command families (with reasons)

These WP-CLI families are intentionally **not** reimplemented:

- **`wp eval` / `wp eval-file` / `wp shell`** — already provided by the host:
  `agent-connector-for-wp/php-eval` runs arbitrary PHP in the live runtime.
- **`wp cli ...`** (info, update, has-command, cmd-dump, …) — these introspect
  the WP-CLI tool itself. There is no WP-CLI here to introspect; this pack *is*
  the replacement. Environment info is available via `wp-cli/core-version` and
  `wp-cli/core-check-extensions`.
- **`wp network` / `wp super-admin` / multisite subcommands of `site`, `core`,
  `theme`, `user`** — multisite is out of scope for this pack.
- **`wp package`** — manages WP-CLI's own Composer packages; not applicable.
- **`wp scaffold`** — developer code generator (themes/plugins/tests); a
  build-time tool, not a site-management operation.
- **`wp i18n`** (make-pot, make-json, …) — developer translation tooling.
- **`wp embed`** (oEmbed cache/providers/handlers) — niche; the oEmbed cache is
  reachable via the post-meta and option abilities if needed.
- **`wp core download/install/update`, `wp config create`, `wp db reset/drop/create`**
  — bootstrap/teardown operations that act on WordPress *before it runs* or
  destroy the whole schema; they cannot run reliably from inside the running
  install they would replace. Use the host or a real `wp` for these.
- **`wp db import` (arbitrary SQL file)** — splitting an SQL file into statements
  in PHP is fragile (delimiters, routines, multi-line values). Run individual
  statements via `wp-cli/db-query`, or restore a `wp-cli/db-export` dump from the
  host shell. `wp-cli/db-export` (the higher-value direction) *is* shipped.

## Known limitation: maintenance mode over MCP

`wp maintenance-mode activate` writes `ABSPATH/.maintenance`, after which
WordPress returns **HTTP 503 to every request, including the REST endpoint the
MCP server runs on**. Consequence: once active, you cannot call
`wp-cli/maintenance-mode-deactivate` (or any ability) until WordPress
auto-clears the flag (~10 minutes) or the file is removed from the filesystem
(e.g. `agent-connector-for-wp/file-delete`). This is inherent to doing
maintenance mode over HTTP — it is exactly the case WP-CLI handles by running
outside the web server. To prevent accidental self-lockout, `activate` requires
an explicit `confirm:true` and returns a warning describing the recovery path.

## Verification summary

All 213 abilities were registered and load without errors (`wp_get_abilities()`
reports 213 under the `wp-cli/` namespace). Coverage was verified end-to-end
through the MCP server by three QA passes (content; users/roles/plugins/themes;
data/system) plus a dedicated pass over the destructive/network abilities
(`config-set`/`config-delete`/`config-shuffle-salts`, `db-search-replace`
apply, `db-export` round-trip, `export`/`import`, `media-import`/`regenerate`,
`plugin-install`, `theme-install`, `language-core-install`, `core-verify-checksums`,
`site-empty`). Bugs found and fixed during verification: `option-set-autoload`
(now uses `wp_set_option_autoload`), `language-core-install` (missing admin
includes), `site-empty` (no longer re-seeds the sample post/page), plus the
`maintenance-mode-activate` confirm guard and `term-get` accepting an integer
`term`. No ability ships known-broken.
