=== Agent Connector for WP ===
Contributors: soflyy
Tags: mcp, ai, agents, abilities, automation
Requires at least: 7.0
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.33.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect AI agents to your site over MCP. Exposes the abilities your plugins register, restricted to admins, with optional logs and protections.

== Description ==

Agent Connector runs a [Model Context Protocol](https://modelcontextprotocol.io/) (MCP) server for your site, so AI agents such as Claude, ChatGPT, Cursor, Codex, Gemini and VS Code Copilot can discover and use what your site can do.

It exposes the WordPress **Abilities** that your plugins register through the Abilities API (in core since WordPress 7.0). Agent Connector ships no abilities of its own: it is the gateway, and every ability that goes through it gets the same protections, whichever plugin registered it.

= Connect in a few clicks =

The **Connect** screen walks you through connecting your agent of choice, with step-by-step instructions for each one:

* **OAuth sign-in** for agents that support it (Claude, ChatGPT, Gemini and other remote connectors): paste your site's MCP URL into the agent, then approve the connection on your site. Requires HTTPS (or a local site).
* **Application passwords** for command-line and desktop agents: Agent Connector generates a WordPress application password and gives you a ready-to-paste command, config snippet or prompt.

= Built-in safeguards =

* **Administrators only.** Every ability exposed over MCP can only be run by a super admin. This check is enforced on every ability, overriding whatever the registering plugin supplied, and cannot be turned off.
* **Production warning.** On production sites, a notice explains what connected agents can do until an administrator dismisses it.
* **Optional protections.** Block Agent Connector on production environments, or lock it to the current domain so a copied database can't grant access on another site.
* **Optional logs.** Record MCP requests to a log you can browse in wp-admin, and write an audit trail of every ability call to a file.
* **Connections.** See and revoke the agents connected through OAuth.

= For developers =

Register abilities with the WordPress Abilities API and mark them public for MCP, or use the `agent_connector_for_wp_register_ability()` helper, which adds Agent Connector's admin check and audit logging for you. See the developer documentation in the [GitHub repository](https://github.com/soflyy/agent-connector-for-wp/blob/master/plugin/docs/registering-abilities.md).

Agent Connector bundles the [WordPress MCP Adapter](https://github.com/WordPress/mcp-adapter), so you don't need to install it separately.

= Source code =

The admin screens are built with React from the `admin/src` directory included in this plugin (`npm ci && npm run build` in `admin/`). The full development repository is at [github.com/soflyy/agent-connector-for-wp](https://github.com/soflyy/agent-connector-for-wp).

== External services ==

Agent Connector itself does not send data to any external service, and makes no outgoing requests.

The AI agents you connect are third-party services that you choose and configure yourself. Once connected, an agent sends requests to your site's MCP endpoint and receives the results of the abilities it runs. Please review your agent provider's terms and privacy policy, for example:

* Anthropic (Claude): [terms](https://www.anthropic.com/legal/consumer-terms), [privacy policy](https://www.anthropic.com/legal/privacy)
* OpenAI (ChatGPT, Codex): [terms](https://openai.com/policies/terms-of-use/), [privacy policy](https://openai.com/policies/privacy-policy/)
* Google (Gemini): [terms](https://policies.google.com/terms), [privacy policy](https://policies.google.com/privacy)

Some connection methods described on the Connect screen run the `@automattic/mcp-wordpress-remote` proxy on your own computer (via `npx`), which then connects to your site.

== Installation ==

1. Install and activate the plugin from **Plugins → Add New**.
2. Open **Agent Connector** in the wp-admin menu.
3. Pick your agent on the **Connect** screen and follow the steps.

== Frequently Asked Questions ==

= Does this plugin add any abilities by itself? =

No. It exposes the abilities that other plugins register through the WordPress Abilities API. The **Abilities** screen lists every ability registered on your site and whether it's exposed to agents.

= Who can use the exposed abilities? =

Only a super admin. Agents act as the administrator who connected them, and every ability checks that the user is a super admin, whichever plugin registered it.

= What happens if I move the site to a new domain? =

If you turned on the domain lock, abilities are blocked until an administrator reconnects from **Agent Connector → Settings → Protection**. This protects application passwords carried in a copied database.

= Can I turn it off without deactivating the plugin? =

Yes. Use the **Enable Agent Connector** toggle under **Agent Connector → Settings**.

== Changelog ==

Release notes for every version are on [GitHub](https://github.com/soflyy/agent-connector-for-wp/releases).
