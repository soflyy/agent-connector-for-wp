=== Agent Connector for WP ===
Contributors: soflyy
Tags: mcp, ai, agents, wp-cli, abilities, development
Requires at least: 7.0
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 1.4.0
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
* **Admin login link** (`agent-connector-for-wp/create-admin-login-link`) — mint a one-time, short-lived URL that logs a browser into wp-admin as the requesting super admin, so a browser-driving agent (e.g. Playwright) that only holds an application password can still use the admin UI.

The goal: give trusted agents in local/development environments the same operational capabilities as a human administrator — effectively SSH-equivalent access — through the existing WordPress MCP stack.

== ⚠️ Danger / Intended Use ==

This plugin is **dangerous by design**. It is:

* developer-focused
* intentionally unrestricted
* **NOT** sandboxed
* blocked on production environments unless you explicitly tick the production override
* **NOT** enterprise security software

It assumes the environment is trusted and that authenticated administrators intentionally trust the agents acting on their behalf. Do not install it anywhere you would not hand out a root shell.

== Requirements ==

* WordPress 7.0+
* The WordPress AI plugin (provides the Abilities API)
* PHP 8.1+
* WP-CLI available on the server (recommended)
* A local / development / staging environment

wordpress/mcp-adapter is bundled with this plugin; you do not need to install it separately.

== Enabling the Plugin ==

Everything is configured on one screen: **Agent Connector for WP > Connection** in wp-admin (always available, even while the plugin is off). There is no enabling constant.

* **Enable Agent Connector** — the master toggle. When on, the plugin runs an MCP server for this site and exposes the abilities other plugins registered ("third-party abilities", always active while enabled). Enabling also locks the plugin to the current domain (see Domain Lock).
* **Built-in abilities** — a separate opt-in, **off by default**. When on, the plugin also exposes its own powerful abilities (shell, PHP eval, filesystem, WP-CLI, env-inspect, admin-login). Leave it off if you only want third-party abilities exposed.
* **Production override** — required only when `wp_get_environment_type()` reports `production` (also the default when the environment type is never configured). On `local`/`development`/`staging` the master toggle alone activates the plugin; on `production` you must additionally tick the override, which carries the danger warning.

== Domain Lock ==

When you enable the plugin (or click **Reconnect to this domain**), it records the site's declared home host. If the site is later cloned or moved to a different domain, the built-in abilities are blocked and return an error telling the calling agent that an administrator must visit **Agent Connector for WP > Connection** and click **Reconnect to this domain**. This stops a copied database — and the application passwords inside it — from silently granting access on a different site.

== Connecting an Agent ==

On the same **Connection** screen, once the plugin is enabled, click **Generate connection**. The plugin mints a fresh WordPress application password, computes this site's MCP server URL, and hands you three copy-paste artifacts: a natural-language prompt for any coding agent, a `claude mcp add` CLI command, and an `mcpServers` JSON block. All three drive the @automattic/mcp-wordpress-remote proxy, which the agent runs locally via npx (Node.js required) and which authenticates using the application password (shown only once). Revoke it from Users > Profile > Application Passwords when finished.

== Optional Configuration ==

`define( 'AGENT_CONNECTOR_FOR_WP_TIMEOUT_MS', 60000 );          // default command/eval timeout`
`define( 'AGENT_CONNECTOR_FOR_WP_MAX_OUTPUT_BYTES', 2097152 );   // per-stream output cap (2 MiB)`
`define( 'AGENT_CONNECTOR_FOR_WP_AUDIT_LOG', '/path/to/audit.log' );  // default: wp-content/agent-connector-for-wp-audit.log`

== Security Model ==

Intentionally high-trust. It does NOT implement sandboxing, granular ACLs, approval workflows, restricted shells, or command whitelisting. It DOES enforce:

* administrator/super-admin checks (`manage_options` + `is_super_admin()`) on every built-in ability
* explicit opt-in: off until enabled on the Connection screen, and the powerful built-in abilities are a further opt-in that is off by default
* the production gate: on a production environment type, the plugin stays inactive until the operator also ticks the production override
* the domain lock (built-in abilities refuse to run on a domain the plugin was not enabled on)
* timeout enforcement and output caps
* append-only audit logging of every invocation (user, ability, input summary, status, duration)

== Changelog ==

= 0.1.0 =
* Initial release: shell-exec, wp-cli, php-eval, file read/write/delete/list, env-inspect, process-exec, create-admin-login-link abilities; audit logging.
* Explicit opt-in enable model: off until enabled via the Settings screen (no enabling constant). On a production environment type, a second explicit override is required before abilities activate.
* Domain lock: abilities are blocked, with an agent-actionable error, if the site is moved to a domain the plugin was not enabled on; reconnect from the Settings screen.
* One-time admin login links so a browser-driving agent holding only an application password can reach wp-admin.
* Bundles wordpress/mcp-adapter via the Jetpack Autoloader so the plugin works standalone.
* Splits abilities into third-party (registered by other plugins, exposed over MCP whenever enabled) and built-in (this plugin's own, off by default behind a separate toggle).
* Single "Agent Connector for WP > Connection" admin screen for enabling, the built-in-abilities toggle, the domain lock, and generating the connection (application password + ready-to-paste prompt, Claude Code CLI command, and mcpServers JSON for the @automattic/mcp-wordpress-remote proxy).
