# Registering abilities with Agent Connector

This guide is for **third-party plugin authors** (and code generators) who want to
add a WordPress *ability* that is exposed over the Agent Connector MCP
server and automatically protected by Agent Connector's security.

> **You write no auth.** When you register through the API below, Agent Connector
> for WP injects, for every ability:
>
> - the **permission check** — administrator **and** super-admin only;
> - the **domain lock** — when the operator has enabled it, calls are blocked if
>   the site's locked domain no longer matches (prevents a moved/cloned site from
>   acting on a stale identity);
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
plugin degrades gracefully when Agent Connector is inactive:

```php
add_action( 'wp_abilities_api_init', function () {
    if ( ! function_exists( 'agent_connector_for_wp_register_ability' ) ) {
        return; // Agent Connector not active — register nothing.
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
| `permission_callback`| callable   | — | **Ignored.** Auth is owned by Agent Connector. |

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

A full, copy-pasteable single-file plugin that registers one ability.

```php
<?php
/**
 * Plugin Name:       My Plugin Abilities
 * Description:        Adds the my-plugin/do-thing ability to Agent Connector.
 * Version:           1.0.0
 * Requires PHP:      8.1
 * License:           GPL-2.0-or-later
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
**no** logging — Agent Connector provides all three.

---

## Companion plugin conventions

1. **Namespace your abilities** under your plugin's own vendor prefix
   (`woo/...`, `my-plugin/...`) — never `agent-connector-for-wp/...`.

2. **Register on the standard hooks** (`wp_abilities_api_categories_init` then
   `wp_abilities_api_init`) and **guard with `function_exists()`** so your plugin
   is inert when Agent Connector is absent.

3. **Don't declare `Requires Plugins` on Agent Connector.** Its folder differs
   between builds (`agent-connector` from wordpress.org,
   `agent-connector-for-wp` from GitHub), and `Requires Plugins` can name only
   one, so the dependency would block activation on sites running the other
   copy. The `function_exists()` guard above is enough.

4. **Don't bundle** wordpress/mcp-adapter or the Abilities API — the Abilities
   API is in core, and the MCP server comes from the canonical MCP Adapter
   plugin, which Agent Connector requires (and offers to install) for you.

Every ability you register this way is automatically governed by Agent
Connector's auth, domain lock, and audit log.

---

## How the protection is applied (for the curious)

You don't need this to use the API, but for transparency: Agent Connector hooks
the core `wp_register_ability_args` filter and, for **every** ability that will
be MCP-exposed (an explicit `meta.mcp.public` wins; when absent, exposure is
inherited from `meta.public`) — whether registered through this API
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
