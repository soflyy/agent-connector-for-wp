# Agent Connector for WP

> ⚠️ **Dangerous by design.** This plugin grants root-equivalent operational capability — arbitrary shell, PHP eval, and filesystem access — to authenticated administrators/super admins and the agents acting on their behalf. It is **not sandboxed**. On a `production` environment type it stays inactive until you explicitly tick a production override; everywhere else the Enable toggle is enough. Only turn it on where you would be comfortable handing out a root shell.

Agent Connector for WP fills the execution gap in the WordPress MCP ecosystem. The existing stack — the [WordPress AI plugin](https://wordpress.org/plugins/ai/), the MCP Abilities API, and [`wordpress/mcp-adapter`](https://github.com/WordPress/mcp-adapter) — provides structured tools and abilities, but agents still lack unrestricted operational access.

This plugin registers additional WordPress **Abilities** and surfaces them over MCP. It **bundles** [`wordpress/mcp-adapter`](https://github.com/WordPress/mcp-adapter) via Composer (loaded with the [Jetpack Autoloader](https://github.com/Automattic/jetpack-autoloader)), so it works standalone — the separate "MCP Adapter" plugin does **not** need to be installed. If that plugin *is* also active, the Jetpack Autoloader deduplicates the shared library to a single, newest version to avoid conflicts.

## What it adds

| Ability | Purpose |
| --- | --- |
| `agent-connector-for-wp/shell-exec` | Run arbitrary shell commands (`proc_open`), capturing stdout/stderr/exit code, with cwd + timeout. |
| `agent-connector-for-wp/wp-cli` | Run a WP-CLI command against this install (everything after `wp`); auto-adds `--allow-root` when running as root. |
| `agent-connector-for-wp/php-eval` | Evaluate arbitrary PHP in the loaded WordPress runtime; returns output, return value, and errors. |
| `agent-connector-for-wp/file-read` | Read any file (binary-safe via base64). |
| `agent-connector-for-wp/file-write` | Write any file, creating dirs; binary-safe; append mode. |
| `agent-connector-for-wp/file-delete` | Delete a file or directory (recursive optional). |
| `agent-connector-for-wp/file-list` | List a directory, optionally recursively. |
| `agent-connector-for-wp/env-inspect` | WP/PHP versions, paths, active plugins/theme, debug state, writable-ness, available CLI tools. |
| `agent-connector-for-wp/process-exec` | Longer-running command execution (proxies shell-exec in v1). |
| `agent-connector-for-wp/create-admin-login-link` | Mint a one-time, short-lived URL that logs a browser into wp-admin as the requesting super admin (for browser-driving agents that hold only an application password). |

The goal: give trusted agents in development environments effectively **SSH-equivalent** operational access through the existing WordPress MCP stack.

## Requirements

- WordPress 7.0+
- The WordPress AI plugin (provides the Abilities API)
- PHP 8.1+
- WP-CLI available on the server (recommended)
- A local / development / staging environment

> `wordpress/mcp-adapter` is bundled — you do not need to install it separately.

## Install

Clone into your plugins directory, install dependencies, and activate:

```bash
cd wp-content/plugins
git clone https://github.com/soflyy/agent-connector-for-wp.git
cd agent-connector-for-wp
composer install --no-dev
wp plugin activate agent-connector-for-wp
```

Dependencies (`vendor/`) are not committed to the repository — `composer install`
fetches them. If you download a packaged release `.zip` (built by CI, with
`vendor/` already bundled), skip the `composer install` step and just activate.

## Connect an agent

Once enabled, scroll to the **Connect an agent** section of **Agent Connector for
WP → Connection** in wp-admin and click **Generate connection**. The plugin will:

1. mint a fresh WordPress application password for your account,
2. compute this site's MCP server URL, and
3. give you three ready-to-paste artifacts:
   - a **natural-language prompt** to drop into Claude (or any coding agent),
     which tells it to configure and connect to the MCP server itself;
   - a **`claude mcp add` CLI command** for Claude Code; and
   - an **`mcpServers` JSON** block for client config files (e.g. `.mcp.json`).

All three drive [`@automattic/mcp-wordpress-remote`](https://www.npmjs.com/package/@automattic/mcp-wordpress-remote)
— a small stdio MCP proxy the agent runs locally via `npx` (so the client needs
Node.js). The proxy connects to this site's MCP endpoint and authenticates with
the application password (passed as the `WP_API_PASSWORD` environment variable,
alongside `WP_API_URL` and `WP_API_USERNAME`).

The application password is shown only once, embedded in those artifacts — copy
it immediately. Treat it like an SSH key; revoke it from **Users → Profile →
Application Passwords** when you're done.

## Enable

Everything is configured on one screen — **Agent Connector for WP → Connection**
in wp-admin (always available, even while the plugin is off). There is no
enabling constant.

- **Enable Agent Connector** — the master toggle. When on, the plugin runs an MCP
  server for this site and exposes the abilities other plugins registered
  ("third-party abilities" — always active while enabled). Enabling also locks the
  plugin to the current domain.
- **Built-in abilities** — a separate opt-in, **off by default**. When on, the
  plugin also exposes its *own* powerful abilities (shell, PHP eval, filesystem,
  WP-CLI, env-inspect, admin-login). Leave it off to expose only third-party
  abilities.
- **Production override** — required only when `wp_get_environment_type()` reports
  `production` (also the default when the environment type was never configured).
  On `local` / `development` / `staging` the master toggle alone activates the
  plugin; on `production` you must additionally tick the override, which carries
  the danger warning. This makes it hard to accidentally expose the plugin on a
  live site.

### Domain lock

When enabled (or when you click **Reconnect to this domain**), the plugin records
the site's declared home host. If the site is later cloned or moved to a
different domain, every ability is blocked and returns an error telling the agent
that an administrator must reconnect from **Agent Connector for WP → Settings** —
so a copied database (and the application passwords in it) can't silently grant
access on another site.

### Optional tunables

```php
define( 'AGENT_CONNECTOR_FOR_WP_TIMEOUT_MS', 60000 );         // command/eval timeout
define( 'AGENT_CONNECTOR_FOR_WP_MAX_OUTPUT_BYTES', 2097152 );  // per-stream cap (2 MiB)
define( 'AGENT_CONNECTOR_FOR_WP_AUDIT_LOG', '/path/to/audit.log' );
```

## Examples

`shell-exec` input:

```json
{ "command": "wp plugin list", "cwd": "/var/www/html" }
```

`php-eval` input:

```json
{ "code": "return get_plugins();" }
```

`file-read` input:

```json
{ "path": "wp-content/debug.log" }
```

`wp-cli` input:

```json
{ "command": "plugin list --status=active --format=json" }
```

## Philosophy

> Trusted agents in trusted environments should have root-equivalent operational capability.

Built for developers running autonomous coding agents against local or development WordPress installs, bridging the execution gap in the WordPress MCP ecosystem until native Abilities coverage is comprehensive enough.

## License

GPL-2.0-or-later.
