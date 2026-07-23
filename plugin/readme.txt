=== Agent Connector for WP ===
Contributors: soflyy
Tags: mcp, ai, agents, abilities, automation
Requires at least: 7.0
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 1.24.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect AI agents to your WordPress site over MCP. Runs the WordPress MCP server and exposes abilities registered by your plugins, with audit logging.

== Description ==

Agent Connector for WP runs an MCP (Model Context Protocol) server for your site and exposes the WordPress **Abilities** that your plugins register — so AI agents like Claude Code, Cursor, and other MCP clients can work with your site through a structured, governed interface.

The plugin ships **no abilities of its own**. It is the secured gateway: every ability it exposes runs through a single chokepoint — a super-admin permission check, an audit log, and (when you turn it on) a domain lock — no matter which plugin registered it.

= What it does =

* Runs the WordPress MCP server (bundles the `wordpress/mcp-adapter` library, so no separate plugin is needed).
* Exposes abilities registered through the WordPress Abilities API (in core since WordPress 7.0) to connected MCP clients.
* Generates connection artifacts (application password + ready-to-paste client configs) from the Connection screen.
* Audit-logs MCP traffic to a dedicated table, with an "MCP Events" admin page to inspect it.
* Enforces a super-admin gate on every ability execution — this is not configurable.
* Optional protections: block the MCP server on production environments, and a domain lock that disables abilities if the database is copied to another domain.

= Security model =

Agents connected through this plugin act with the capability of the super admin whose application password they hold. Treat MCP credentials like an SSH key. A site-wide warning is shown while the plugin runs on a production environment until you explicitly dismiss it, and ability execution is always restricted to super admins.

= Registering your own abilities =

Other plugins can register abilities with a single function call — see the developer documentation in the plugin's `docs/registering-abilities.md` and the public API in `src/api.php`.

= Optional companion plugin =

Built-in operational abilities (shell, PHP evaluation, filesystem, WP-CLI, admin login links) are intentionally not part of this plugin. They live in the separate, optional **Universal Abilities** plugin, available from [wpagentconnector.com/universal-abilities](https://wpagentconnector.com/universal-abilities).

= Source code =

The admin screen is a React application; its built assets ship with the plugin. The full, human-readable source (including the admin app and build tooling) is developed in the open at [github.com/soflyy/agent-connector-for-wp](https://github.com/soflyy/agent-connector-for-wp).

== Frequently Asked Questions ==

= Does this plugin call any external services? =

No. The plugin makes no requests to external services. The MCP server it runs only responds to requests made to your own site's REST API by clients you configured.

= Does it work without other plugins? =

Yes. The MCP adapter library is bundled. Abilities come from the plugins you install: any plugin that registers WordPress Abilities is exposed automatically (subject to the super-admin gate).

= Who can execute abilities? =

Only super admins, authenticated with an application password over HTTPS. The permission gate is enforced on every ability, including abilities registered by third-party plugins, and cannot be turned off.

== Changelog ==

= 1.24.1 =
* See the full changelog at https://github.com/soflyy/agent-connector-for-wp/releases
