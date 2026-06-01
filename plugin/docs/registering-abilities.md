# Registering abilities with Agent Connector for WP

This guide is for **third-party plugin authors** (and code generators) who want to
add a WordPress *ability* that is exposed over the Agent Connector for WP MCP
server and automatically protected by Agent Connector's security.

> **You write no auth.** When you register through the API below, Agent Connector
> for WP injects, for every ability:
>
> - the **permission check** — administrator **and** super-admin only;
> - the **domain lock** — calls are blocked if the site's locked domain no longer
>   matches (prevents a moved/cloned site from acting on a stale identity);
> - the **audit log** — every invocation is recorded.
>
> You do **not** supply `permission_callback`. If you pass one, it is **ignored
> and overridden**. Just describe the ability and its behavior.

---

## The function

```php
agent_connector_for_wp_register_ability( string $name, array $args ): void
```

A short alias is also available and identical:

```php
acfw_register_ability( string $name, array $args ): void
```

Both are plain global functions — no `use` statement, no class references. They
are a thin, stable wrapper over core `wp_register_ability()`.

Call them on the **`wp_abilities_api_init`** action (the same place you would
call `wp_register_ability()`). Always guard with `function_exists()` so your
plugin degrades gracefully when Agent Connector for WP is inactive:

```php
add_action( 'wp_abilities_api_init', function () {
    if ( ! function_exists( 'agent_connector_for_wp_register_ability' ) ) {
        return; // Agent Connector for WP not active — register nothing.
    }
    agent_connector_for_wp_register_ability( 'my-plugin/do-thing', array( /* ... */ ) );
} );
```

### `$name`

Your ability's name, in the form `vendor/ability`. Use **your own** vendor
namespace (your plugin slug), e.g. `my-plugin/do-thing`. Lowercase letters,
digits, and dashes only, with exactly one `/`. Do **not** use the
`agent-connector-for-wp/` namespace — that is reserved for the host plugin's
built-in abilities.

### `$args`

| Key                  | Type       | Required | Notes |
| -------------------- | ---------- | :------: | ----- |
| `label`              | string     | yes | Short human-readable name. |
| `description`        | string     | yes | What the ability does. The agent reads this to decide when to call it — be precise. |
| `category`           | string     | yes | A **registered** ability-category slug (see below). |
| `input_schema`       | array      | recommended | JSON Schema for the input object. |
| `output_schema`      | array      | recommended | JSON Schema for the result object. |
| `execute_callback`   | callable   | yes | `fn(array $input): array|WP_Error` — your logic. |
| `summarize`          | callable   | optional | `fn(array $input): array` — a **redacted** summary written to the audit log. See [Audit redaction](#audit-redaction). |
| `annotations`        | array      | optional | Behavior hints: `readonly`, `destructive`, `idempotent` (booleans). Shorthand for `meta.annotations`. |
| `meta`               | array      | optional | Extra ability meta. Any `mcp.public` / `show_in_rest` you set is overridden — see below. |
| `permission_callback`| callable   | — | **Ignored.** Auth is owned by Agent Connector for WP. |

Agent Connector forces `meta.mcp.public = true` and `meta.show_in_rest = true`
so the MCP adapter surfaces the ability. You never set those yourself.

---

## Registering a category

Every ability belongs to a category, which must be registered **before** the
ability, on the `wp_abilities_api_categories_init` action:

```php
add_action( 'wp_abilities_api_categories_init', function () {
    if ( ! function_exists( 'wp_register_ability_category' ) ) {
        return;
    }
    wp_register_ability_category( 'my-plugin', array(
        'label'       => 'My Plugin',
        'description' => 'Abilities contributed by My Plugin.',
    ) );
} );
```

You may reuse an existing category slug (including `agent-connector-for-wp`) or
define your own. Defining your own is recommended so your abilities group
cleanly.

---

## Input / output JSON-schema conventions

- The **input schema** should be a JSON Schema `object`:
  - `type: 'object'`,
  - a `properties` map (each property typed, with a `description`),
  - a `required` array for mandatory fields,
  - `additionalProperties: false` to reject unexpected keys.
- The **output schema** should likewise be an `object` describing the array your
  `execute_callback` returns. The Abilities API validates your return value
  against it, so keep it in sync with what you actually return.
- Your `execute_callback` receives the validated `$input` as an associative
  array and must return either an **associative array** matching `output_schema`,
  or a `WP_Error` on failure.

```php
'input_schema' => array(
    'type'                 => 'object',
    'properties'           => array(
        'path' => array( 'type' => 'string', 'description' => 'File to read.' ),
    ),
    'required'             => array( 'path' ),
    'additionalProperties' => false,
),
'output_schema' => array(
    'type'       => 'object',
    'properties' => array(
        'contents' => array( 'type' => 'string' ),
        'size'     => array( 'type' => 'integer' ),
    ),
),
```

---

## Audit redaction

Every invocation is written to the Agent Connector audit log. By default the
log records the ability name, status, duration, user, and IP — but **none of
your input**, so nothing leaks by accident.

If you want a (safe) summary of the input in the log, provide a `summarize`
callback. It receives the same `$input` and returns a small associative array.
**Redact secrets and truncate large values** — whatever you return is written
verbatim:

```php
'summarize' => function ( array $input ): array {
    return array(
        // Safe to log:
        'path'    => isset( $input['path'] ) ? (string) $input['path'] : '',
        // Truncate big blobs:
        'command' => isset( $input['command'] ) ? mb_substr( (string) $input['command'], 0, 500 ) : '',
        // Do NOT include tokens, passwords, file contents, etc.
    );
},
```

---

## Complete minimal companion plugin

A full, copy-pasteable single-file plugin that registers one ability. The same
file ships as a reference under
[`examples/acfw-ability-pack-hello/`](../examples/acfw-ability-pack-hello/acfw-ability-pack-hello.php).

```php
<?php
/**
 * Plugin Name:       My Ability Pack
 * Description:        Adds the my-plugin/do-thing ability to Agent Connector for WP.
 * Version:           1.0.0
 * Requires Plugins:  agent-connector-for-wp
 * Requires PHP:      8.1
 * License:           GPL-2.0-or-later
 *
 * Agent Connector: Ability Pack
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_categories_init', function () {
    if ( ! function_exists( 'wp_register_ability_category' ) ) {
        return;
    }
    wp_register_ability_category( 'my-plugin', array(
        'label'       => 'My Plugin',
        'description' => 'Abilities contributed by My Plugin.',
    ) );
} );

add_action( 'wp_abilities_api_init', function () {
    if ( ! function_exists( 'agent_connector_for_wp_register_ability' ) ) {
        return; // Host plugin inactive — register nothing.
    }

    agent_connector_for_wp_register_ability( 'my-plugin/do-thing', array(
        'label'         => 'Do Thing',
        'description'   => 'Does the thing and returns a result.',
        'category'      => 'my-plugin',
        'input_schema'  => array(
            'type'                 => 'object',
            'properties'           => array(
                'name' => array( 'type' => 'string', 'description' => 'What to act on.' ),
            ),
            'required'             => array( 'name' ),
            'additionalProperties' => false,
        ),
        'output_schema' => array(
            'type'       => 'object',
            'properties' => array( 'message' => array( 'type' => 'string' ) ),
        ),
        'annotations'   => array( 'readonly' => true, 'idempotent' => true ),

        // Your logic. Return an array (matching output_schema) or a WP_Error.
        'execute_callback' => function ( array $input ) {
            $name = isset( $input['name'] ) ? (string) $input['name'] : '';
            if ( '' === $name ) {
                return new WP_Error( 'my_plugin_bad_input', 'name is required', array( 'status' => 400 ) );
            }
            return array( 'message' => "Did the thing for {$name}." );
        },

        // Optional: redacted audit-log summary.
        'summarize' => function ( array $input ): array {
            return array( 'name' => isset( $input['name'] ) ? (string) $input['name'] : '' );
        },
    ) );
} );
```

That's the whole plugin. **No** permission callback, **no** domain-lock check,
**no** logging — Agent Connector for WP provides all three.

---

## Companion "ability pack" plugin convention

A companion plugin that exists to add abilities to a specific host MCP plugin is
an **ability pack**. A remote directory feature discovers ability packs by their
plugin header, so follow this convention exactly:

1. **Slug / folder.** Name the plugin
   `agent-connector-for-wp-ability-pack-<target>`, e.g.
   `agent-connector-for-wp-ability-pack-woocommerce`. The folder, main file, and
   `Plugin Name` should agree.

2. **Declare dependency, identity, and target.** Three header lines:

   - `Requires Plugins: agent-connector-for-wp` — core WordPress dependency
     header, so WordPress won't activate your pack without Agent Connector. (This
     is also how a pack declares it plugs into Agent Connector — there is no
     separate "host" header.)
   - `Agent Connector: Ability Pack` — the marker that identifies the file as an
     ability pack at all.
   - `Agent Connector Target: woocommerce/woocommerce.php` — **the WP plugin this
     pack extends.** This is the canonical join key: the published ability-pack
     directory keys each entry on the same value (its `target_plugin` field), so
     the two must match for the site to surface your pack against an installed
     plugin. Use the plugin file (`woocommerce/woocommerce.php`) or a bare folder
     slug (`woocommerce`) — both are matched. Omit it only for a pack that
     doesn't extend a specific plugin (it just won't appear in the directory).

   ```php
   /**
    * Plugin Name:       Agent Connector for WP Ability Pack: WooCommerce
    * Requires Plugins:  agent-connector-for-wp
    *
    * Agent Connector: Ability Pack
    * Agent Connector Target: woocommerce/woocommerce.php
    */
   ```

3. **Namespace your abilities** under your pack's own vendor prefix
   (`woo/...`, `my-plugin/...`) — never `agent-connector-for-wp/...`.

4. **Register on the standard hooks** (`wp_abilities_api_categories_init` then
   `wp_abilities_api_init`) and **guard with `function_exists()`** so the pack is
   inert when the host is absent.

5. **Don't bundle** wordpress/mcp-adapter or the Abilities API — the host plugin
   provides them.

Following this convention means: your pack activates only alongside Agent
Connector, the directory can list it under the right target plugin, and every
ability you register is automatically governed by Agent Connector's auth, domain
lock, and audit log.

---

## How the protection is applied (for the curious)

You don't need this to use the API, but for transparency: Agent Connector hooks
the core `wp_register_ability_args` filter and, for **every** ability that will
be MCP-exposed (`meta.mcp.public === true`) — whether registered through this API
or via raw `wp_register_ability()` — it overrides `permission_callback` with its
admin/super-admin check and wraps `execute_callback` with its domain-lock +
audit chokepoint. This is a security backstop: no ability reachable through the
Agent Connector MCP server can run under someone else's (or no) authentication.

If an integrator deliberately needs to opt a specific ability out of this
governance (taking over its auth themselves), they can filter it:

```php
add_filter( 'agent_connector_for_wp_govern_ability', function ( $govern, $name, $args ) {
    if ( 'some-plugin/special' === $name ) {
        return false; // We accept full responsibility for this ability's auth.
    }
    return $govern;
}, 10, 3 );
```

The default is to govern every MCP-exposed ability.
