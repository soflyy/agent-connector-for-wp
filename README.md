# Root for Agents

> ⚠️ **Dangerous by design.** This plugin grants root-equivalent operational capability — arbitrary shell, PHP eval, and filesystem access — to authenticated administrators/super admins and the agents acting on their behalf. It is **not sandboxed** and **not for production**. Install it only in trusted local/dev/staging environments where you would be comfortable handing out a root shell.

Root for Agents fills the execution gap in the WordPress MCP ecosystem. The existing stack — the [WordPress AI plugin](https://wordpress.org/plugins/ai/), the MCP Abilities API, and [`wordpress/mcp-adapter`](https://github.com/WordPress/mcp-adapter) — provides structured tools and abilities, but agents still lack unrestricted operational access.

This plugin registers additional WordPress **Abilities** and surfaces them over MCP. It **bundles** [`wordpress/mcp-adapter`](https://github.com/WordPress/mcp-adapter) via Composer (loaded with the [Jetpack Autoloader](https://github.com/Automattic/jetpack-autoloader)), so it works standalone — the separate "MCP Adapter" plugin does **not** need to be installed. If that plugin *is* also active, the Jetpack Autoloader deduplicates the shared library to a single, newest version to avoid conflicts.

## What it adds

| Ability | Purpose |
| --- | --- |
| `root-for-agents/shell-exec` | Run arbitrary shell commands (`proc_open`), capturing stdout/stderr/exit code, with cwd + timeout. |
| `root-for-agents/php-eval` | Evaluate arbitrary PHP in the loaded WordPress runtime; returns output, return value, and errors. |
| `root-for-agents/file-read` | Read any file (binary-safe via base64). |
| `root-for-agents/file-write` | Write any file, creating dirs; binary-safe; append mode. |
| `root-for-agents/file-delete` | Delete a file or directory (recursive optional). |
| `root-for-agents/file-list` | List a directory, optionally recursively. |
| `root-for-agents/env-inspect` | WP/PHP versions, paths, active plugins/theme, debug state, writable-ness, available CLI tools. |
| `root-for-agents/process-exec` | Longer-running command execution (proxies shell-exec in v1). |

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
git clone https://github.com/soflyy/root-for-agents.git
cd root-for-agents
composer install --no-dev
wp plugin activate root-for-agents
```

Release builds ship with `vendor/` committed, so a downloaded `.zip` needs no
`composer install` step — just activate.

## Connect an agent

Once enabled, go to **Root for Agents → Connect** in wp-admin and click
**Generate connection**. The plugin will:

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

## Enable (mandatory gates)

The plugin is inert until you explicitly opt in. Add to `wp-config.php`:

```php
define( 'ROOT_FOR_AGENTS_ENABLED', true );
```

It also refuses to run when `wp_get_environment_type()` is `production`, unless you additionally (and inadvisably) set:

```php
define( 'ROOT_FOR_AGENTS_ALLOW_PRODUCTION', true );
```

All abilities register when `ROOT_FOR_AGENTS_ENABLED` is true.

### Optional tunables

```php
define( 'ROOT_FOR_AGENTS_TIMEOUT_MS', 60000 );         // command/eval timeout
define( 'ROOT_FOR_AGENTS_MAX_OUTPUT_BYTES', 2097152 );  // per-stream cap (2 MiB)
define( 'ROOT_FOR_AGENTS_AUDIT_LOG', '/path/to/audit.log' );
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

## Philosophy

> Trusted agents in trusted environments should have root-equivalent operational capability.

Built for developers running autonomous coding agents against local or development WordPress installs, bridging the execution gap in the WordPress MCP ecosystem until native Abilities coverage is comprehensive enough.

## License

GPL-2.0-or-later.
