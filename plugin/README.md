# Agent Connector for WP

Agent Connector for WP fills the execution gap in the WordPress MCP ecosystem. The existing stack — the WordPress **Abilities API** (in core as of 7.0) and [`wordpress/mcp-adapter`](https://github.com/WordPress/mcp-adapter) — provides structured tools and abilities, but agents still lack unrestricted operational access.

This plugin runs an MCP **server** for the site and exposes the WordPress **Abilities** that *other* plugins register — it ships **no abilities of its own**. It **bundles** [`wordpress/mcp-adapter`](https://github.com/WordPress/mcp-adapter) via Composer (loaded with the [Jetpack Autoloader](https://github.com/Automattic/jetpack-autoloader)), so it works standalone — the separate "MCP Adapter" plugin does **not** need to be installed. If that plugin *is* also active, the Jetpack Autoloader deduplicates the shared library to a single, newest version to avoid conflicts. Every ability it exposes runs through one chokepoint — a super-admin permission check, the audit log, and (when you turn it on) the domain lock — no matter which plugin registered it.

## What it adds

Nothing, on its own — and that is the point. The plugin is the secured MCP
gateway; abilities come from companion plugins:

- **[Universal Abilities](../universal-abilities-plugin/README.md)** — a separate,
  optional companion plugin (installable in one click from the Settings screen)
  that contributes the powerful built-in abilities below. Not installed by
  default; installing it is the opt-in.
- **Ability packs** — generated plugins that expose a specific plugin's
  functionality (WooCommerce, Contact Form 7, …) to agents.
- **Your own** abilities, via the [public registration API](#register-your-own-abilities).

The abilities the **Universal Abilities** pack contributes:

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

The goal: give agents effectively **SSH-equivalent** operational access through the existing WordPress MCP stack. A connected agent — and the Universal Abilities pack once installed — can do anything a super admin can, so treat access to the MCP server (the application password) like an SSH key. See [Protection](#protection) for the safeguards.

## Requirements

- WordPress 7.0+ (ships the Abilities API in core)
- PHP 8.1+
- WP-CLI available on the server (recommended)

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

## Updates

The plugin updates itself from **GitHub Releases** (it is intentionally not on
wordpress.org), through a bundled update checker — new versions appear on the
normal Plugins screen and via auto-updates. The optional
[Universal Abilities](../universal-abilities-plugin/README.md) pack and any
installed ability packs are updated by **this** plugin too, from a published
manifest, through the same Plugins-screen flow — so every update is managed in
one place, and companion plugins ship no update code of their own. Update checks
are best-effort and never block WordPress: a slow or unreachable GitHub simply
means "no update offered" that cycle.

## Connect an agent

The plugin is on as soon as you activate it. Open **Agent Connector →
Connection** in wp-admin, pick your agent, and click **Generate connection**. The
plugin will:

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

## Settings

Everything is configured on one screen — **Agent Connector → Settings** in
wp-admin. There is no enabling constant.

- **Enable Agent Connector** — the master toggle, **on by default**. When on, the
  plugin runs an MCP server for this site and exposes the abilities other plugins
  registered ("third-party abilities"). Activating the plugin turns this on and
  pins the domain lock to the current host automatically.
- **Universal Abilities** — the powerful abilities (shell, PHP eval, filesystem,
  WP-CLI, env-inspect, admin-login) live in the separate
  **[Universal Abilities](../universal-abilities-plugin/README.md)** plugin, **off
  by default**. If it isn't installed, the Settings screen offers a one-click
  **Install** button. Leave it off — or uninstalled — to expose only abilities
  from other plugins.

## Protection

The plugin works everywhere out of the box; the safeguards below are **optional
and off by default**, grouped under Settings → Protection. What's *not* optional:
ability execution is always restricted to super admins (enforced on every
MCP-exposed ability, no matter which plugin registered it) — there is no setting
to loosen that.

- **Production warning notice** — while the plugin runs on a `production`
  environment (which is also the default when `wp_get_environment_type()` was
  never configured), a red notice appears across wp-admin explaining that
  connected agents have super-admin-level access. It's informational, not a
  block — dismiss it with **I understand** (site-wide) or the **Disable
  production warning** toggle.
- **Block on production environments** — opt in to make the plugin refuse to run
  while the environment type is `production`. Off by default, so a production site
  works but shows the warning above.
- **Domain lock** — the host is pinned when you activate the plugin (or click
  **Reconnect to this domain**), but enforcement is **off** until you enable it.
  When on, every ability is blocked if the site's domain no longer matches the
  pinned host — so a database cloned to another domain (e.g. staging pushed to
  production, carrying its application passwords) can't silently grant access
  there. The error tells the agent an administrator must reconnect from
  **Agent Connector → Settings → Protection**.

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

## Register your own abilities

Third-party plugins can add abilities that are exposed over this plugin's MCP
server and **automatically protected by its super-admin auth and audit log (plus
the domain lock when it's enabled)** — the companion plugin writes no permission
or security code. Use the public API:

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

Full guide, schema conventions, audit-redaction contract, and the companion
"ability pack" plugin convention: **[`docs/registering-abilities.md`](docs/registering-abilities.md)**.
A runnable reference pack lives in [`examples/acfw-ability-pack-hello/`](examples/acfw-ability-pack-hello/).

## Philosophy

> An agent you've connected should have the same operational reach as the admin who connected it.

Built for people running autonomous coding agents against their WordPress installs, bridging the execution gap in the WordPress MCP ecosystem until native Abilities coverage is comprehensive enough. The plugin's job is to make that reach clear — through the audit log, the super-admin gate, and the on-page warning — not to second-guess an operator who deliberately installed it.

## License

GPL-2.0-or-later.
