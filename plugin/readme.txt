=== Agent Connector for WP ===
Contributors: soflyy
Tags: mcp, ai, agents, wp-cli, abilities, development
Requires at least: 7.0
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Give agents unrestricted execution capability on WordPress: shell, PHP eval, filesystem, and process execution via the WordPress MCP stack. Dev only.

== Description ==

Agent Connector for WP fills the execution gap in the WordPress MCP ecosystem. The existing stack (the WordPress AI plugin, the MCP Abilities API, and wordpress/mcp-adapter) provides structured tools and abilities, but agents still lack unrestricted operational access.

This plugin registers additional WordPress **Abilities** and exposes them over MCP. It bundles wordpress/mcp-adapter (loaded via the Jetpack Autoloader), so it works standalone — the separate MCP Adapter plugin is not required. It adds:

* **Shell execution** (`agent-connector-for-wp/shell-exec`) — run arbitrary commands via proc_open(), capturing stdout/stderr/exit code with a working directory and timeout.
* **WP-CLI execution** (`agent-connector-for-wp/wp-cli`) — run a WP-CLI command (everything after `wp`) against this install; runs from the WordPress root and auto-adds --allow-root when running as root.
* **PHP runtime execution** (`agent-connector-for-wp/php-eval`) — evaluate arbitrary PHP inside the loaded WordPress runtime; returns printed output, the returned value, and any error.
* **Filesystem access** (`agent-connector-for-wp/file-read`, `file-write`, `file-delete`, `file-list`) — read/write/delete arbitrary files (binary-safe via base64) and list directories recursively.
* **Environment inspection** (`agent-connector-for-wp/env-inspect`) — versions, paths, active plugins/theme, debug flags, writable-ness, and available CLI tooling.
* **Process execution** (`agent-connector-for-wp/process-exec`) — longer-running command execution (proxies shell-exec in v1).

The goal: give trusted agents in local/development environments the same operational capabilities as a human administrator — effectively SSH-equivalent access — through the existing WordPress MCP stack.

== ⚠️ Danger / Intended Use ==

This plugin is **dangerous by design**. It is:

* developer-focused
* intentionally unrestricted
* **NOT** sandboxed
* **NOT** intended for production
* **NOT** enterprise security software

It assumes the environment is trusted and that authenticated administrators intentionally trust the agents acting on their behalf. Do not install it anywhere you would not hand out a root shell.

== Requirements ==

* WordPress 7.0+
* The WordPress AI plugin (provides the Abilities API)
* PHP 8.1+
* WP-CLI available on the server (recommended)
* A local / development / staging environment

wordpress/mcp-adapter is bundled with this plugin; you do not need to install it separately.

== Mandatory Environment Gates ==

The plugin refuses to initialize unless explicitly enabled. Add to `wp-config.php`:

`define( 'AGENT_CONNECTOR_FOR_WP_ENABLED', true );`

Additionally, the plugin will not run when `wp_get_environment_type()` is `production` unless you also define:

`define( 'AGENT_CONNECTOR_FOR_WP_ALLOW_PRODUCTION', true );  // not recommended`

All abilities are registered when `AGENT_CONNECTOR_FOR_WP_ENABLED` is true.

== Connecting an Agent ==

Go to **Agent Connector for WP > Connect** in wp-admin and click **Generate connection**. The plugin mints a fresh WordPress application password, computes this site's MCP server URL, and hands you three copy-paste artifacts: a natural-language prompt for any coding agent, a `claude mcp add` CLI command, and an `mcpServers` JSON block. All three drive the @automattic/mcp-wordpress-remote proxy, which the agent runs locally via npx (Node.js required) and which authenticates using the application password (shown only once). Revoke it from Users > Profile > Application Passwords when finished.

== Optional Configuration ==

`define( 'AGENT_CONNECTOR_FOR_WP_TIMEOUT_MS', 60000 );          // default command/eval timeout`
`define( 'AGENT_CONNECTOR_FOR_WP_MAX_OUTPUT_BYTES', 2097152 );   // per-stream output cap (2 MiB)`
`define( 'AGENT_CONNECTOR_FOR_WP_AUDIT_LOG', '/path/to/audit.log' );  // default: wp-content/agent-connector-for-wp-audit.log`

== Security Model ==

Intentionally high-trust. It does NOT implement sandboxing, granular ACLs, approval workflows, restricted shells, or command whitelisting. It DOES enforce:

* administrator/super-admin checks (`manage_options` + `is_super_admin()`) on every ability
* the mandatory environment gates above
* the production guard
* timeout enforcement and output caps
* append-only audit logging of every invocation (user, ability, input summary, status, duration)

== Changelog ==

= 0.1.0 =
* Initial release: shell-exec, wp-cli, php-eval, file read/write/delete/list, env-inspect, process-exec abilities; environment gates; audit logging.
* Bundles wordpress/mcp-adapter via the Jetpack Autoloader so the plugin works standalone.
* Adds a "Agent Connector for WP > Connect" admin page that generates an application password and ready-to-paste connection instructions (agent prompt, Claude Code CLI command, and mcpServers JSON) for the @automattic/mcp-wordpress-remote proxy.
