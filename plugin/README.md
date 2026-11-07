# Agent Connector

Agent Connector fills the execution gap in the WordPress MCP ecosystem. The existing stack — the WordPress **Abilities API** (in core as of 7.0) and [`wordpress/mcp-adapter`](https://github.com/WordPress/mcp-adapter) — provides structured tools and abilities, but agents still lack unrestricted operational access.

This plugin runs an MCP **server** for the site and exposes the WordPress **Abilities** that *other* plugins register, plus its own optional [Universal Abilities](#what-it-adds) (off by default). The server itself comes from the canonical [MCP Adapter](https://github.com/WordPress/mcp-adapter) plugin, which must be installed alongside this one: it used to be bundled here, but the adapter project has [deprecated bundling](https://github.com/WordPress/mcp-adapter/pull/288) because two copies of the library on one site conflict. It is declared with the `Requires Plugins: mcp-adapter` header, so WordPress will not activate this plugin without it and offers to install it for you from wordpress.org. Every ability it exposes runs through one chokepoint — a super-admin permission check, the audit log, and (when you turn it on) the domain lock — no matter which plugin registered it.

## What it adds

Nothing, on its own, until you choose to. The plugin is the secured MCP
gateway; abilities come from:

- **Universal Abilities** — built-in abilities, **off by default**. Turn them on
  with the **Universal Abilities** switch under Settings.
- **Other plugins** — anything registered through the Abilities API, including
  your own via the [public registration API](#register-your-own-abilities).

The Universal Abilities (★ = GitHub build only; the wordpress.org build leaves
these out, see [Updates](#updates)):

| Ability | Purpose |
| --- | --- |
| `agent-connector-for-wp/shell-exec` ★ | Run arbitrary shell commands (`proc_open`), capturing stdout/stderr/exit code, with cwd + timeout. |
| `agent-connector-for-wp/wp-cli` ★ | Run a WP-CLI command against this install (everything after `wp`); auto-adds `--allow-root` when running as root. |
| `agent-connector-for-wp/php-eval` ★ | Evaluate arbitrary PHP in the loaded WordPress runtime; returns output, return value, and errors. |
| `agent-connector-for-wp/file-read` | Read any file (binary-safe via base64). |
| `agent-connector-for-wp/file-write` ★ | Write any file, creating dirs; binary-safe; append mode. ★ PHP files only inside the sandbox folder (`wp-content/agent-connector-for-wp-sandbox/`), where they load on every request, with crash recovery. |
| `agent-connector-for-wp/file-delete` | Delete a file or directory (recursive optional). |
| `agent-connector-for-wp/file-list` | List a directory, optionally recursively. |
| `agent-connector-for-wp/env-inspect` | WP/PHP versions, paths, active plugins/theme, debug state, writable-ness, and (GitHub build) available CLI tools. |
| `agent-connector-for-wp/process-exec` ★ | Longer-running command execution (proxies shell-exec in v1). |
| `agent-connector-for-wp/search-media` | Search the media library. |
| `agent-connector-for-wp/plugins-list` | Installed plugins: version, active, available update, auto-updates. |
| `agent-connector-for-wp/plugins-search` | Search the wordpress.org plugin directory. |
| `agent-connector-for-wp/plugin-install` | Install a plugin from wordpress.org by slug (optionally a version), optionally activating it. wordpress.org only. |
| `agent-connector-for-wp/plugin-activate` / `plugin-deactivate` | Activate or deactivate a plugin (network-wide on multisite). |
| `agent-connector-for-wp/plugin-update` | Update one plugin, or `all`. |
| `agent-connector-for-wp/plugin-delete` | Deactivate, uninstall and delete a plugin. |
| `agent-connector-for-wp/plugin-auto-updates` | Turn a plugin's auto-updates on or off. |
| `agent-connector-for-wp/create-admin-login-link` | Mint a one-time, short-lived URL that logs a browser into wp-admin as the requesting super admin (for browser-driving agents that hold only an application password). |

The plugin abilities refuse to deactivate, update or delete Agent Connector
itself or MCP Adapter, since the agent's connection depends on them.

The goal: give agents effectively **SSH-equivalent** operational access through the existing WordPress MCP stack. A connected agent — with Universal Abilities switched on — can do anything a super admin can, so treat access to the MCP server (the application password) like an SSH key. See [Protection](#protection) for the safeguards.

## Requirements

- WordPress 7.0+ (ships the Abilities API in core)
- PHP 8.1+
- WP-CLI available on the server (recommended)

- The [MCP Adapter](https://wordpress.org/plugins/mcp-adapter/) plugin (0.6.0 or
  newer), installed and active. It is declared as a plugin dependency, so
  WordPress prompts you to install it when you activate this plugin.

## Install

Install the MCP Adapter plugin first, then this plugin:

```bash
# The MCP server this plugin exposes abilities through.
wp plugin install mcp-adapter --activate

cd wp-content/plugins
git clone https://github.com/soflyy/agent-connector-for-wp.git
cd agent-connector-for-wp
composer install --no-dev
wp plugin activate agent-connector-for-wp
```

WordPress enforces the dependency both ways: it will not activate this plugin
while MCP Adapter is missing or inactive, and it will not let you deactivate
MCP Adapter while this plugin is running.

Dependencies (`vendor/`) are not committed to the repository — `composer install`
fetches them (only this plugin's autoloader and its GitHub update checker; the
adapter is no longer part of `vendor/`). If you download a packaged release
`.zip` (built by CI, with `vendor/` already bundled), skip the `composer install`
step and just activate.

## Updates

The plugin is built twice from the same version (`bin/build-plugin-zip.sh`):

- **GitHub build** — the zip attached to each GitHub Release. It updates itself
  from GitHub Releases through a bundled update checker, so new versions appear
  on the normal Plugins screen and via auto-updates. Update checks are
  best-effort and never block WordPress: a slow or unreachable GitHub simply
  means "no update offered" that cycle.
- **wordpress.org build** — the same plugin without what wordpress.org doesn't
  allow: the update checker, the shell / process / WP-CLI / PHP-eval abilities
  (everything under `src/GitHub/`), `file-write` and the sandbox loader, and any code between `// @github-build-start` and `// @github-build-end`
  markers. Its slug (folder) is `agent-connector`, since wordpress.org doesn't
  allow "wp" in slugs; the GitHub build keeps `agent-connector-for-wp`.
  wordpress.org delivers its updates. Its directory listing comes from
  [`readme.txt`](readme.txt), and the directory's banner, icon and
  screenshots from `.wordpress-org/` at the repository root. The release
  workflow commits each release to wordpress.org SVN once the
  `WPORG_SVN_USERNAME` and `WPORG_SVN_PASSWORD` repository secrets are set;
  until then it skips that step.

MCP Adapter is a wordpress.org plugin, so WordPress updates it like any other.

## Connect an agent

The plugin is on as soon as you activate it. Open **Agent Connector →
Connection** in wp-admin, pick your agent, and follow its steps. There are two
ways to connect:

- **OAuth sign-in**, for agents that support it (Claude, ChatGPT, Gemini,
  Cursor, VS Code, Codex and other remote MCP clients): add this site's MCP
  server URL to the agent, then approve the connection on this site's consent
  page. Needs HTTPS (or a local site). See and revoke these connections under
  **Settings → Connections**.
- **Application password**, for command-line and desktop agents: the plugin
  mints a WordPress application password for your account and gives you a
  ready-to-paste command, config snippet or prompt. These run
  [`@automattic/mcp-wordpress-remote`](https://www.npmjs.com/package/@automattic/mcp-wordpress-remote),
  a small stdio MCP proxy the agent starts locally via `npx` (so the client
  needs Node.js). It authenticates with the application password, passed as
  `WP_API_PASSWORD` alongside `WP_API_URL` and `WP_API_USERNAME`.

The application password is shown only once, embedded in those snippets, so
copy it right away. Treat it like an SSH key; revoke it from **Users → Profile →
Application Passwords** when you're done.

## Settings

Everything is configured on one screen — **Agent Connector → Settings** in
wp-admin. There is no enabling constant.

- **Enable Agent Connector** — the master toggle, **on by default**. When on, the
  plugin runs an MCP server for this site and exposes the abilities other plugins
  registered ("third-party abilities"). Activating the plugin turns this on and
  pins the domain lock to the current host automatically.
- **Universal Abilities** — the built-in abilities listed
  [above](#what-it-adds), **off by default**. Turning the switch on asks for
  confirmation first. Leave it off to expose only abilities from other plugins.
  Sites that had the old standalone Universal Abilities plugin active get it
  deactivated automatically, with this switch turned on in its place.

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

### Logging

Both logs are **off by default** — nothing is recorded unless you expressly opt
in:

- **Log MCP events** (Settings → Debug) — every MCP request (including raw
  JSON-RPC bodies) into a database table, shown on the plugin's Log screen.
- **Audit log file** — one JSON line per ability execution (ability, user, IP,
  input summary). There is no setting and no default path: **defining the
  `AGENT_CONNECTOR_FOR_WP_AUDIT_LOG` constant is the opt-in** — leave it
  undefined and nothing is ever written. Point it outside the web root; nothing
  in the plugin reads this file, it's an on-disk trail for operators.

### Optional tunables

```php
define( 'AGENT_CONNECTOR_FOR_WP_TIMEOUT_MS', 60000 );         // command/eval timeout
define( 'AGENT_CONNECTOR_FOR_WP_MAX_OUTPUT_BYTES', 2097152 );  // per-stream cap (2 MiB)
define( 'AGENT_CONNECTOR_FOR_WP_AUDIT_LOG', '/var/log/ac4wp-audit.log' ); // defining this enables the audit log
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

Full guide, schema conventions, audit-redaction contract, and companion plugin
conventions: **[`docs/registering-abilities.md`](docs/registering-abilities.md)**.

## Philosophy

> An agent you've connected should have the same operational reach as the admin who connected it.

Built for people running autonomous coding agents against their WordPress installs, bridging the execution gap in the WordPress MCP ecosystem until native Abilities coverage is comprehensive enough. The plugin's job is to make that reach clear — through the audit log, the super-admin gate, and the on-page warning — not to second-guess an operator who deliberately installed it.

## License

GPL-2.0-or-later.
