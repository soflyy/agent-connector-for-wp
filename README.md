# Agent Connector

Agent Connector connects coding agents to a WordPress site over MCP and gives them real operational access to it — the filesystem, plus shell, WP-CLI and PHP eval in the GitHub build — through the WordPress Abilities API and the [MCP Adapter](https://wordpress.org/plugins/mcp-adapter/) plugin, which it declares as a required plugin dependency.

A connected agent acts with the full capability of a super admin (and, with its built-in Universal Abilities switched on, can work with the server directly — in the GitHub build that includes shell commands, PHP, and WP-CLI). That is the whole point — you install it to give an agent that access. The plugin makes sure operators know what's live: a warning notice shows across wp-admin while it runs on a production environment, and opt-in protections (**Block on production environments**, **Domain lock**) live under Settings → Protection. Ability execution is always restricted to super admins.

This is a **monorepo**:

| Path | What it is |
| --- | --- |
| [`plugin/`](plugin/) | The WordPress plugin itself. See [`plugin/README.md`](plugin/README.md) for plugin docs, abilities, enabling, and the Connect page. |
| [`bin/`](bin/) | Developer scripts: `install.sh` symlinks `plugin/` into a WordPress install, and `build-plugin-zip.sh` builds the GitHub and wordpress.org zips. |

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

Merging to `master` automatically publishes a versioned release. The merged PR
title sets the bump (Conventional Commits; see [`CONTRIBUTING.md`](CONTRIBUTING.md)),
and [`.github/workflows/auto-release.yml`](.github/workflows/auto-release.yml):

1. syncs the version into the plugin and `readme.txt`, then tags it;
2. builds both zips with `bin/build-plugin-zip.sh`, each with `vendor/` bundled:
   - **GitHub build** (`agent-connector-for-wp.zip`): attached to the GitHub
     Release, and updates itself from GitHub;
   - **wordpress.org build** (`agent-connector.zip`): leaves out the
     self-updater and the GitHub-only abilities (shell, process, WP-CLI, PHP
     eval). It's uploaded as a workflow artifact;
3. commits the wordpress.org build to wordpress.org SVN, once the
   `WPORG_SVN_USERNAME` and `WPORG_SVN_PASSWORD` repository secrets are set.

To build the zips locally:

```bash
bin/build-plugin-zip.sh github
bin/build-plugin-zip.sh org
```

You can also push a `v*` tag manually to build both zips via
[`.github/workflows/release.yml`](.github/workflows/release.yml); it attaches
the GitHub build to the release but doesn't deploy to wordpress.org.
Downloaded release zips already include `vendor/`, so no `composer install` is
needed.

## License

GPL-2.0-or-later.
