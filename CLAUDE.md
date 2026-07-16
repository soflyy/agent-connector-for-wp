# Notes for agents working in this repo

Read [`CONTRIBUTING.md`](CONTRIBUTING.md) before opening a PR.

## Monorepo layout

| Path | What it is | Docs |
| --- | --- | --- |
| `plugin/` | The main WordPress plugin (PHP, Composer). Runs the MCP server; ships no abilities of its own. | [`plugin/README.md`](plugin/README.md) |
| `universal-abilities-plugin/` | The Default Abilities companion plugin: the powerful built-in abilities (shell, PHP eval, filesystem, WP-CLI, admin login). Installed in one click from the main plugin's Connection screen. | [`universal-abilities-plugin/README.md`](universal-abilities-plugin/README.md) |
| `abilities-generator/` | The `wp-mcp-generator` skill + scripts. | [`abilities-generator/README.md`](abilities-generator/README.md) |
| `ability-packs/` | Generated ability-pack plugins (the generator's output). | — |
| `bin/` | Dev scripts (`install.sh` symlinks `plugin/` + `universal-abilities-plugin/` into a WP install). | — |

Each subproject has its own README/CONTRIBUTING. The PR-title release automation
below applies **only** to `plugin/`; `universal-abilities-plugin/` is published by
its own workflow (`.github/workflows/default-abilities-release.yml`) to the
stable `universal-abilities-plugin` release on any change under that directory.

## PR / commit titles drive releases

Merging a PR to `master` (when it touches `plugin/**`) automatically publishes a
new GitHub Release, and the **PR title** decides the version bump (Conventional
Commits):

- breaking change (`type!:`, or `BREAKING CHANGE` in the body) → **major**
- `fix: …` → **patch**
- anything else (`feat:`, unprefixed, `chore:`, `docs:`, …) → **minor** (the default)

Use `fix:` only for real bug fixes; everything else becomes a minor/feature
release. Title PRs accordingly.

## Don't touch the version by hand

The release bot owns the version in `plugin/agent-connector-for-wp.php`
(`Version:` + `AGENT_CONNECTOR_FOR_WP_VERSION`). Leave it alone — it bumps on
release with `[skip ci]`.

## Don't `wp plugin install --force` over the symlink

The live plugin is a symlink to `plugin/`; a forced install deletes the link
target (your working-tree source). To test a built zip, inspect it or extract it
under a different slug.
