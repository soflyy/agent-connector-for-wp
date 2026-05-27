# Root for Agents

> ⚠️ **Dangerous by design.** This plugin grants root-equivalent operational capability — arbitrary shell, PHP eval, and filesystem access — to authenticated administrators and the agents acting on their behalf. It is **not sandboxed** and **not for production**. Install it only in trusted local/dev/staging environments where you would be comfortable handing out a root shell.

Root for Agents fills the execution gap in the WordPress MCP ecosystem. The existing stack — the [WordPress AI plugin](https://wordpress.org/plugins/ai/), the MCP Abilities API, and [`wordpress/mcp-adapter`](https://github.com/WordPress/mcp-adapter) — provides structured tools and abilities, but agents still lack unrestricted operational access.

This plugin registers additional WordPress **Abilities** only. It does **not** run its own MCP server — the installed `mcp-adapter` surfaces these abilities automatically.

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
- `wordpress/mcp-adapter`
- PHP 8.1+
- WP-CLI available on the server (recommended)
- A local / development / staging environment

## Install

Clone into your plugins directory and activate:

```bash
cd wp-content/plugins
git clone https://github.com/soflyy/root-for-agents.git
wp plugin activate root-for-agents
```

## Enable (mandatory gates)

The plugin is inert until you explicitly opt in. Add to `wp-config.php`:

```php
define( 'ROOT_FOR_AGENTS_ENABLED', true );
define( 'ROOT_FOR_AGENTS_ALLOW_SHELL', true ); // shell-exec + process-exec
define( 'ROOT_FOR_AGENTS_ALLOW_EVAL', true );  // php-eval
```

It also refuses to run when `wp_get_environment_type()` is `production`, unless you additionally (and inadvisably) set:

```php
define( 'ROOT_FOR_AGENTS_ALLOW_PRODUCTION', true );
```

Abilities whose gate is off are not registered at all. `file-*` and `env-inspect` need only `ROOT_FOR_AGENTS_ENABLED`.

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

## Security model

Intentionally high-trust. **No** sandboxing, ACLs, approval workflows, restricted shells, or command whitelisting. It **does** enforce:

- `manage_options` capability checks on every ability
- the mandatory environment gates + production guard
- timeout enforcement and per-stream output caps
- append-only audit logging of every invocation (user, ability, input summary, status, duration) — default `wp-content/root-for-agents-audit.log`

## Roadmap

Streaming execution, async jobs, terminal sessions, git integration, rollback snapshots, process supervision, container introspection, DB query tools, log streaming, remote REPL, and an agent activity timeline.

## Philosophy

> Trusted agents in trusted environments should have root-equivalent operational capability.

Built for developers running autonomous coding agents against local or development WordPress installs, bridging the execution gap in the WordPress MCP ecosystem until native Abilities coverage is comprehensive enough.

## License

GPL-2.0-or-later.
