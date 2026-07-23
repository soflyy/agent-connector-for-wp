# Agent Connector

Agent Connector runs a Model Context Protocol (MCP) **server** for your WordPress
site and exposes the WordPress **Abilities** that *other* plugins register — it
ships **no abilities of its own**. Point any MCP-capable AI agent at your site and
it can discover and call those abilities through one governed endpoint.

It **bundles** [`wordpress/mcp-adapter`](https://github.com/WordPress/mcp-adapter)
via Composer (loaded with the [Jetpack Autoloader](https://github.com/Automattic/jetpack-autoloader)),
so it works standalone — the separate "MCP Adapter" plugin does **not** need to be
installed. If that plugin *is* also active, the Jetpack Autoloader deduplicates the
shared library to a single, newest version to avoid conflicts.

## What it does

Nothing exposes anything on its own — and that is the point. The plugin is the
secured MCP gateway; abilities come from companion plugins (or your own code). Every
ability reachable through the server is wrapped with the same protections, no matter
which plugin registered it:

- **Administrator-only authentication** — the plugin forces its own permission check
  (`manage_options` + super admin) onto every MCP-exposed ability, overriding whatever
  the registering plugin supplied. A permissive (or missing) permission callback cannot
  bypass the gate.
- **Domain lock** — enabling records the site's home host; if the database is later
  cloned to another domain, abilities refuse to run until an administrator reconnects.
- **Audit logging** — every invocation is recorded (user, ability, a redactable input
  summary, status, duration) to an append-only log.
- **Optional MCP event log** — an opt-in Debug setting records each MCP request
  (including raw JSON-RPC bodies) to a database table and an "MCP Events" admin screen.
  Off by default.

The plugin is inert until you switch it on from its Connection screen.

## Requirements

- WordPress 7.0+
- PHP 8.1+
- The WordPress Abilities API (provides `wp_register_ability()`)
- At least one plugin that registers abilities, for the server to expose

> `wordpress/mcp-adapter` is bundled — you do not need to install it separately.

## Install

Clone into your plugins directory, install dependencies, and activate:

```bash
cd wp-content/plugins
git clone https://github.com/soflyy/agent-connector-for-wp.git
cd agent-connector-for-wp
composer install --no-dev
wp plugin activate agent-connector
```

Dependencies (`vendor/`) are not committed to the repository — `composer install`
fetches them.

## Connect an agent

Once enabled, open the **Connect** section of **Agent Connector → Connection** in
wp-admin and click **Generate connection**. The plugin will:

1. mint a fresh WordPress application password for your account,
2. compute this site's MCP server URL, and
3. give you three ready-to-paste artifacts:
   - a **natural-language prompt** to drop into any coding agent,
   - a **`claude mcp add` CLI command**, and
   - an **`mcpServers` JSON** block for client config files.

All three drive [`@automattic/mcp-wordpress-remote`](https://www.npmjs.com/package/@automattic/mcp-wordpress-remote)
— a small stdio MCP proxy the agent runs locally via `npx` (so the client needs
Node.js). The proxy connects to this site's MCP endpoint and authenticates with the
application password.

The application password is shown only once — copy it immediately. Revoke it from
**Users → Profile → Application Passwords** when you're done.

## Enable

Everything is configured on one screen — **Agent Connector → Connection** in wp-admin
(always available, even while the plugin is off). There is no enabling constant.

- **Enable Agent Connector** — the master toggle. When on, the plugin runs the MCP
  server and exposes the abilities other plugins registered. Enabling also locks the
  plugin to the current domain.
- **Production override** — required only when `wp_get_environment_type()` reports
  `production` (also the default when the environment type was never configured). On
  `local` / `development` / `staging` the master toggle alone activates the plugin.

### Domain lock

When enabled (or when you click **Reconnect to this domain**), the plugin records the
site's declared home host. If the site is later cloned or moved to a different domain,
every ability is blocked and returns an error telling the agent that an administrator
must reconnect from the Connection screen — so a copied database (and the application
passwords in it) can't silently grant access on another site.

### Optional tunables

```php
define( 'AGENT_CONNECTOR_FOR_WP_AUDIT_LOG', '/path/to/audit.log' );
```

## Register your own abilities

Any plugin can add abilities that are exposed over this plugin's MCP server and
**automatically protected by its auth, domain lock, and audit log** — the companion
plugin writes no permission or security code. Use the public API:

```php
add_action( 'wp_abilities_api_init', function () {
    if ( ! function_exists( 'agent_connector_for_wp_register_ability' ) ) {
        return;
    }
    agent_connector_for_wp_register_ability( 'my-plugin/do-thing', array(
        'label'            => 'Do Thing',
        'description'      => 'Does the thing.',
        'category'         => 'my-plugin', // register it on wp_abilities_api_categories_init
        'input_schema'     => array( 'type' => 'object', 'properties' => array(), 'additionalProperties' => false ),
        'output_schema'    => array( 'type' => 'object', 'properties' => array( 'message' => array( 'type' => 'string' ) ) ),
        'execute_callback' => fn( array $input ) => array( 'message' => 'done' ),
    ) );
} );
```

Full guide, schema conventions, and the audit-redaction contract:
**[`docs/registering-abilities.md`](docs/registering-abilities.md)**. A runnable
reference pack lives in [`examples/acfw-ability-pack-hello/`](examples/acfw-ability-pack-hello/).

## License

GPL-2.0-or-later.
