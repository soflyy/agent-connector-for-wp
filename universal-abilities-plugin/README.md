# Universal Abilities for Agent Connector

The **Default Abilities** pack for
[Agent Connector for WP](../plugin/README.md). It contributes Agent Connector's
powerful built-in abilities — the ones that give a connected agent the same
low-level operational reach a super admin has over the server, over MCP:

| Ability | What it does |
| --- | --- |
| `agent-connector-for-wp/shell-exec` | Run an arbitrary shell command. |
| `agent-connector-for-wp/process-exec` | Run a program with an argv array (no shell). |
| `agent-connector-for-wp/wp-cli` | Run a WP-CLI command. |
| `agent-connector-for-wp/php-eval` | Evaluate PHP inside the live WordPress runtime. |
| `agent-connector-for-wp/file-read` · `-write` · `-list` · `-delete` | Filesystem operations. |
| `agent-connector-for-wp/env-inspect` | Inspect server / environment details. |
| `agent-connector-for-wp/search-media` | Search the WordPress media library (read-only). |
| `agent-connector-for-wp/create-admin-login-link` | Mint a one-time admin login link. |

Anyone who can authenticate to this site's MCP server (i.e. holds an application
password) can use these to run code and read/write files as the web server, so
treat that password like an SSH key. Only super admins can execute them, and
every call is audit-logged.

## Why it's a separate plugin

The main Agent Connector for WP plugin ships **no abilities of its own**. It runs
the MCP server, governs every ability (auth + domain lock + audit), and exposes
the abilities other plugins register. These powerful built-ins live here so
they're opt-in *at the plugin level*: a site only has them if this plugin is
installed.

## Install & enable

1. Install + activate Agent Connector for WP (this plugin **requires** it).
2. In **wp-admin → Agent Connector for WP → Connection**, under *Built-in
   abilities*, click **Install Default Abilities** (one-click install of this
   plugin) — or install it manually.
3. Tick **Expose the built-in abilities over MCP** and save.

## How it integrates

This plugin owns nothing about auth or MCP plumbing — it reuses the main plugin's
infrastructure:

- Abilities are registered with their own permission callback (super-admin) and
  wrapped by the main plugin's `AuditLogger` (domain lock + audit). The main
  plugin's `Governance` layer also enforces auth on every MCP-exposed ability.
- The **Built-in abilities** status row, the opt-in toggle, its save logic, and
  the connection-screen warnings are rendered onto the main Connection screen
  through hooks the main plugin exposes:

  | Hook | Purpose |
  | --- | --- |
  | `agent_connector_for_wp_render_status_rows` (action) | The Status table row. |
  | `agent_connector_for_wp_render_settings_rows` (action) | The warning + opt-in toggle. |
  | `agent_connector_for_wp_settings_saved` (action) | Persist the toggle on save. |
  | `agent_connector_for_wp_connect_heads_up` (filter) | Strengthen the connection warning. |
  | `agent_connector_for_wp_render_connect_notices` (action) | The super-admin requirement notice. |

  When this plugin is **not** active, the main plugin renders a one-click install
  prompt in place of the toggle.

## Development

The plugin is plain PSR-4 PHP under `src/` (namespace
`AgentConnectorForWp\DefaultAbilities`) with no Composer dependencies. For local
work, symlink it into your WordPress install alongside the main plugin (see
[`../bin/install.sh`](../bin/install.sh)) and activate both.
