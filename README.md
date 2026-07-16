# Agent Connector for WP

Agent Connector for WP connects coding agents to a WordPress site over MCP and gives them real operational access to it — shell, WP-CLI, PHP eval, and the filesystem — through the WordPress Abilities API and a bundled [`wordpress/mcp-adapter`](https://github.com/WordPress/mcp-adapter).

A connected agent acts with the full capability of a super admin (and, with the [Universal Abilities](universal-abilities-plugin/README.md) pack, can run shell commands, PHP, and WP-CLI). That is the whole point — you install it to give an agent that access. The plugin makes sure operators know what's live: a warning notice shows across wp-admin while it runs on a production environment, and opt-in protections (**Block on production environments**, **Domain lock**) live under Settings → Protection. Ability execution is always restricted to super admins.

This is a **monorepo**:

| Path | What it is |
| --- | --- |
| [`plugin/`](plugin/) | The WordPress plugin itself. See [`plugin/README.md`](plugin/README.md) for plugin docs, abilities, enabling, and the Connect page. |
| [`abilities-generator/`](abilities-generator/) | The `wp-mcp-generator` Claude Code skill + scripts that turn any WordPress plugin into an MCP-driveable ability pack. See [`abilities-generator/README.md`](abilities-generator/README.md). |
| [`ability-packs/`](ability-packs/) | The generated ability-pack plugins (one directory each), produced by the abilities generator. |
| [`bin/`](bin/) | Developer scripts — notably `install.sh`, which symlinks `plugin/` into a WordPress install. |

## Local development

Clone the repo anywhere, install the plugin's dependencies, then symlink the plugin into your WordPress install instead of copying it — edit in the repo, WordPress sees the changes live:

```bash
git clone https://github.com/soflyy/agent-connector-for-wp.git
cd agent-connector-for-wp

# Install the plugin's PHP dependencies (vendor/ is gitignored).
( cd plugin && composer install --no-dev )

# Symlink plugin/ into your wp-content/plugins as agent-connector-for-wp.
bin/install.sh /path/to/wp-content

# Activate it — it's on by default and runs immediately. Manage it from
# Agent Connector → Settings in wp-admin. See plugin/README.md for details.
wp plugin activate agent-connector-for-wp
```

`bin/install.sh` takes the path to your `wp-content` directory and creates
`wp-content/plugins/agent-connector-for-wp` as a symlink to `plugin/`. It only
ever replaces an existing symlink, never a real directory.

## Releases

Merging to `master` automatically publishes a versioned GitHub Release. The
merged PR title sets the bump (Conventional Commits), and
[`.github/workflows/auto-release.yml`](.github/workflows/auto-release.yml) syncs
the version into the plugin, tags it, `composer install --no-dev`s inside
`plugin/`, bundles `vendor/`, and attaches a ready-to-install
`agent-connector-for-wp.zip`. See [`CONTRIBUTING.md`](CONTRIBUTING.md) for how to
title PRs.

You can also push a `v*` tag manually to build a one-off zip via
[`.github/workflows/release.yml`](.github/workflows/release.yml). Downloaded
release zips already include `vendor/` — no `composer install` needed.

## Ability pack releases

The generated packs in [`ability-packs/`](ability-packs/) are released
independently of the main plugin by
[`.github/workflows/ability-packs-release.yml`](.github/workflows/ability-packs-release.yml).
On any push to `master` that changes a pack, the workflow auto-increments that
pack's version (patch by default, from its own latest tag), stamps it into the
built zip, and publishes a per-pack GitHub Release tagged
`<pack-slug>-vX.Y.Z` with a ready-to-install `<pack-slug>.zip`. Pack versions are
**not** hand-managed — the main-file header stays `0.0.0-dev` and CI fills in the
real number. Trigger a manual/forced build (or a minor/major bump) from
**Actions → Release ability packs → Run workflow**.

Every run also regenerates a single `index.json` manifest of all available packs
(slug, version, target plugin, download URL) and publishes it as the asset of a
stable `pack-index` release:
`…/releases/download/pack-index/index.json`. The host plugin's pack updater reads
this one manifest — nothing enumerates the releases list, so it scales to a very
large number of packs.

## License

GPL-2.0-or-later.
