# Unofficial Abilities for WP-CLI

A companion **ability pack** for [Agent Connector for WP](../../plugin) that
reimplements the **WP-CLI command surface in native PHP** and exposes it over
MCP. It's the fallback for when an agent reaches for `wp <command>` but the host
has no WP-CLI: no `wp` binary, `proc_open`/`shell_exec` disabled, or a runtime
that can't spawn processes.

## Why

Agent Connector already ships `agent-connector-for-wp/wp-cli`, but that ability
**shells out to the `wp` binary**. When WP-CLI isn't installed or the host
forbids process execution, it fails and the agent has no recovery path. This
pack provides the same command vocabulary implemented entirely with WordPress
core functions and `$wpdb`, so it works anywhere WordPress itself runs.

## Ergonomics: it mirrors WP-CLI 1:1

Ability names map mechanically to WP-CLI commands, so an agent fluent in WP-CLI
needs no translation:

| You would type | Call the ability |
| --- | --- |
| `wp option get siteurl` | `wp-cli/option-get` `{ "name": "siteurl" }` |
| `wp post create --post_title=Hi` | `wp-cli/post-create` `{ "post_title": "Hi" }` |
| `wp user set-role 5 editor` | `wp-cli/user-set-role` `{ "user": 5, "role": "editor" }` |
| `wp search-replace old new` | `wp-cli/db-search-replace` `{ "old": "old", "new": "new" }` |
| `wp plugin activate akismet` | `wp-cli/plugin-activate` `{ "plugin": "akismet" }` |
| `wp db export` | `wp-cli/db-export` `{}` |

Arguments use WP-CLI's own names. Inputs/outputs are JSON (values come back
already unserialized), not formatted CLI text.

## What's covered

**213 abilities** spanning: `option`, `post` (+meta/term), `post-type`,
`user` (+meta/roles/caps/sessions), `role`/`cap`, `term` (+meta), `taxonomy`,
`comment` (+meta), `plugin`, `theme` (+mods), `language core`, `menu`,
`widget`/`sidebar`, `media`, `cache`, `transient`, `db` (+`search-replace` and a
native SQL `export`), `core`, `cron`, `rewrite`, `config` (+salt rotation),
`maintenance-mode`, `site`, and WXR `export`/`import`.

See [`coverage/coverage-report.md`](coverage/coverage-report.md) for the full
command-by-command map, the documented caveats, and the intentional exclusions
(multisite, bootstrap commands like `core download/install`, developer tooling
like `scaffold`/`i18n`, and `eval`/`shell` which the host already provides via
`agent-connector-for-wp/php-eval`).

## Safety

You write no auth — Agent Connector injects the admin/super-admin permission
check, domain lock, and audit log for every ability. Beyond that, this pack adds
its own guards on the dangerous verbs:

- `wp-cli/site-empty` requires `yes:true`.
- `wp-cli/db-search-replace` defaults to `dry_run:true`.
- `wp-cli/maintenance-mode-activate` requires `confirm:true` — activating
  maintenance returns HTTP 503 to the MCP channel itself, so you can't deactivate
  it over MCP until it clears (see the coverage report's "Known limitation").
- `wp-cli/db-query`, `db-search-replace`, and `config-shuffle-salts` redact or
  truncate their audit-log summaries.

## Install

It's a normal ability pack: it activates alongside Agent Connector for WP and
registers its abilities on the Abilities API. Drop the folder in
`wp-content/plugins/`, activate, and the abilities appear over MCP automatically.

## Notes

- Object-cache abilities reflect the active backend: with the default
  (non-persistent) cache, values don't survive across requests.
- File-path inputs (`media-import`, `db-export` `file_path`) are server paths as
  WordPress sees them (its `ABSPATH`), which may differ from your shell's mount.
- `plugin/theme/language` install/update and `core`/checksum abilities need
  outbound network access to WordPress.org.
