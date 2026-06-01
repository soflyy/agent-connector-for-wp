# Agent Connector for WP

> ⚠️ **Dangerous by design.** This plugin grants root-equivalent operational capability — arbitrary shell, WP-CLI, PHP eval, and filesystem access — to authenticated administrators/super admins and the agents acting on their behalf. It is **not sandboxed**. On a `production` environment type it stays inactive until you explicitly tick a production override; on local/dev/staging the Enable toggle alone activates it. Only turn it on where you would be comfortable handing out a root shell.

Agent Connector for WP connects coding agents to a WordPress site over MCP and gives them real operational access to it — shell, WP-CLI, PHP eval, and the filesystem — through the WordPress Abilities API and a bundled [`wordpress/mcp-adapter`](https://github.com/WordPress/mcp-adapter).

This is a **monorepo**:

| Path | What it is |
| --- | --- |
| [`plugin/`](plugin/) | The WordPress plugin itself. See [`plugin/README.md`](plugin/README.md) for plugin docs, abilities, enabling, and the Connect page. |
| [`site/`](site/) | The one-page marketing/overview site (static HTML/CSS), published to GitHub Pages. |
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

# Activate it, then switch it on from Agent Connector for WP → Settings in
# wp-admin — it ships inert. See plugin/README.md for details.
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

## Site

[`.github/workflows/pages.yml`](.github/workflows/pages.yml) deploys `site/` to
GitHub Pages on push to `master`. Enable it once under **Settings → Pages →
Source: GitHub Actions**.

## License

GPL-2.0-or-later.
