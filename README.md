# Agent Connector

Agent Connector connects coding agents to a WordPress site over MCP and gives them real operational access to it — shell, WP-CLI, PHP eval, and the filesystem — through the WordPress Abilities API and the [MCP Adapter](https://wordpress.org/plugins/mcp-adapter/) plugin, which it declares as a required plugin dependency.

A connected agent acts with the full capability of a super admin (and, with its built-in Universal Abilities switched on, can work with the server directly — in the GitHub build that includes shell commands, PHP, and WP-CLI). That is the whole point — you install it to give an agent that access. The plugin makes sure operators know what's live: a warning notice shows across wp-admin while it runs on a production environment, and opt-in protections (**Block on production environments**, **Domain lock**) live under Settings → Protection. Ability execution is always restricted to super admins.

This is a **monorepo**:

| Path | What it is |
| --- | --- |
| [`plugin/`](plugin/) | The WordPress plugin itself. See [`plugin/README.md`](plugin/README.md) for plugin docs, abilities, enabling, and the Connect page. |
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

# The MCP server comes from the separate MCP Adapter plugin, which this
# plugin declares as a dependency.
wp plugin install mcp-adapter --activate

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

## License

GPL-2.0-or-later.
