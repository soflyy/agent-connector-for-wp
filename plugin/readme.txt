=== Agent Connector ===
Contributors: soflyy
Tags: mcp, ai, agents, abilities, api
Requires at least: 7.0
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 1.20.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Run a secured MCP server that exposes the WordPress Abilities other plugins register to AI agents, with admin auth, a domain lock, and audit logging.

== Description ==

Agent Connector runs a Model Context Protocol (MCP) **server** for your site and exposes the WordPress **Abilities** that *other* plugins register — it ships **no abilities of its own**. Point any MCP-capable AI agent at your site and it can discover and call those abilities through a single, governed endpoint.

It bundles wordpress/mcp-adapter (loaded via the Jetpack Autoloader), so it works standalone — the separate MCP Adapter plugin is not required. Every ability exposed over the server is wrapped with the same protections, no matter which plugin registered it:

* **Administrator-only authentication.** The plugin forces its own permission check (`manage_options` + super admin) onto every MCP-exposed ability, overriding whatever the registering plugin supplied. A third-party ability that ships a permissive (or missing) permission callback cannot bypass this gate.
* **Domain lock.** When you enable the plugin it records the site's home host. If the database is later cloned to another domain, abilities refuse to run until an administrator reconnects — so a copied database (and the application passwords inside it) can't silently grant access elsewhere.
* **Audit logging.** Every invocation is recorded (user, ability, a redactable input summary, status, and duration) to an append-only log.
* **Optional MCP event log.** An opt-in Debug setting records each MCP request — including raw JSON-RPC bodies — to a database table and a dedicated "MCP Events" admin screen. Off by default, because bodies can contain sensitive data.

The plugin is inert until you switch it on from its Connection screen — a stray activation never exposes anything.

== Companion ability packs ==

Agent Connector is a gateway; the abilities themselves come from other plugins. Any plugin can register an ability through the public helper `agent_connector_for_wp_register_ability()` and have it automatically authenticated, domain-locked, and audited. See the bundled `docs/registering-abilities.md` and the `examples/` folder for a minimal reference pack.

== Intended use ==

Agent Connector gives trusted agents programmatic access to your site's abilities. Install it where you intend authenticated administrators to let agents act on their behalf, and only expose ability packs you trust. On a `production` environment type the plugin stays inactive until you additionally confirm a production override.

== Requirements ==

* WordPress 7.0+
* PHP 8.1+
* The WordPress Abilities API (provides `wp_register_ability()`)
* At least one plugin that registers abilities, for the server to expose

wordpress/mcp-adapter is bundled; you do not need to install it separately.

== Enabling the plugin ==

Everything is configured on one screen: **Agent Connector > Connection** in wp-admin (always available, even while the plugin is off). There is no enabling constant.

* **Enable Agent Connector** — the master toggle. When on, the plugin runs the MCP server and exposes the abilities other plugins registered. Enabling also locks the plugin to the current domain (see Domain Lock).
* **Production override** — required only when `wp_get_environment_type()` reports `production` (also the default when the environment type is never configured). On `local`/`development`/`staging` the master toggle alone activates the plugin.

== Domain lock ==

When you enable the plugin (or click **Reconnect to this domain**), it records the site's declared home host. If the site is later cloned or moved to a different domain, abilities are blocked and return an error telling the calling agent that an administrator must visit **Agent Connector > Connection** and click **Reconnect to this domain**.

== Connecting an agent ==

On the **Connection** screen, once the plugin is enabled, click **Generate connection**. The plugin mints a fresh WordPress application password, computes this site's MCP server URL, and hands you three copy-paste artifacts: a natural-language prompt for any coding agent, a `claude mcp add` command, and an `mcpServers` JSON block. All three drive the @automattic/mcp-wordpress-remote proxy, which the agent runs locally via npx (Node.js required) and which authenticates using the application password (shown only once). Revoke it from Users > Profile > Application Passwords when finished.

== Optional configuration ==

`define( 'AGENT_CONNECTOR_FOR_WP_AUDIT_LOG', '/path/to/audit.log' );  // default: wp-content/agent-connector-for-wp-audit.log`

== Frequently Asked Questions ==

= Does this plugin add any abilities by itself? =

No. It exposes and governs abilities that other plugins register through the WordPress Abilities API. Install (or write) an ability pack to give agents something to call.

= Who can call the exposed abilities? =

Only a request authenticated as an administrator / super admin, using a WordPress application password. The check is forced onto every MCP-exposed ability regardless of how it was registered.

= What happens if I move the site to a new domain? =

Abilities are blocked until an administrator reconnects from the Connection screen. This protects application passwords carried in a cloned database.

== Changelog ==

= 1.13.0 =
* The plugin now ships no abilities of its own — it is purely the secured MCP gateway. Companion plugins register abilities through the public API and are automatically authenticated, domain-locked, and audited.

= 0.1.0 =
* Initial release: secured MCP server exposing registered WordPress Abilities, administrator-only authentication forced onto every exposed ability, domain lock, audit logging, and an opt-in MCP event log.
* Explicit opt-in enable model: off until enabled via the Connection screen (no enabling constant). On a production environment type, a second explicit override is required.
* Single "Agent Connector > Connection" admin screen for enabling, the domain lock, and generating the connection (application password + ready-to-paste prompt, CLI command, and mcpServers JSON for the @automattic/mcp-wordpress-remote proxy).
