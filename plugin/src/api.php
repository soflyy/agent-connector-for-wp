<?php
/**
 * Public registration API for third-party "ability pack" plugins.
 *
 * This is the one stable, documented entry point a companion plugin uses to
 * register a WordPress ability that is exposed over Agent Connector's MCP server
 * and automatically protected by Agent Connector's authentication, domain lock,
 * and audit logging. The companion plugin writes NO permission / auth /
 * domain-lock / audit code.
 *
 * See docs/registering-abilities.md for the full developer guide.
 *
 * The functions live in the global namespace on purpose: companion plugins call
 * them directly, with no `use` statement and no knowledge of internal classes.
 *
 * @package AgentConnectorForWp
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'agent_connector_for_wp_register_ability' ) ) {
	/**
	 * Register a WordPress ability and expose it, protected, over Agent
	 * Connector's MCP server.
	 *
	 * Thin wrapper over core `wp_register_ability()`. The caller supplies only the
	 * ability's identity and behavior; Agent Connector force-injects everything
	 * security-related:
	 *
	 *   - permission_callback — our administrator / super-admin gate. Any value
	 *     the caller passes for `permission_callback` is IGNORED and overridden.
	 *   - domain lock + audit logging — the execute callback is wrapped so calls
	 *     are blocked when the site's domain lock is tripped and every invocation
	 *     is recorded to the audit log.
	 *   - MCP exposure — `meta.mcp.public` and `meta.show_in_rest` are forced on
	 *     so the bundled mcp-adapter surfaces the ability.
	 *
	 * Must be called on (or before) the `wp_abilities_api_init` action, exactly
	 * like `wp_register_ability()`. The ability's `category` must already be
	 * registered (register it on `wp_abilities_api_categories_init`).
	 *
	 * @since 1.4.0
	 *
	 * @param string               $name The ability name, with the CALLER's own
	 *                                    vendor namespace, e.g. "my-plugin/do-thing".
	 *                                    Lowercase alphanumerics, dashes, one slash.
	 * @param array<string,mixed>  $args {
	 *     Ability definition. Same shape as wp_register_ability() minus the auth.
	 *
	 *     @type string               $label            Required. Human-readable label.
	 *     @type string               $description      Required. What the ability does (the agent reads this).
	 *     @type string               $category         Required. A registered ability-category slug.
	 *     @type array<string,mixed>  $input_schema     JSON Schema for the input object. Strongly recommended.
	 *     @type array<string,mixed>  $output_schema    JSON Schema for the result object. Recommended.
	 *     @type callable             $execute_callback Required. fn(array $input): array|WP_Error.
	 *     @type callable             $summarize        Optional. fn(array $input): array — a REDACTED
	 *                                                  summary of the input for the audit log. Use this to
	 *                                                  keep secrets / large blobs out of the log. If omitted,
	 *                                                  nothing from the input is logged.
	 *     @type array<string,mixed>  $meta             Optional. Extra ability meta (e.g. `annotations`).
	 *                                                  Any `mcp.public` / `show_in_rest` you set is overridden.
	 *     @type array<string,mixed>  $annotations      Optional. Shorthand for `meta.annotations`
	 *                                                  (readonly / destructive / idempotent hints).
	 *     @type callable             $permission_callback IGNORED. Auth is owned by Agent Connector.
	 * }
	 * @return void
	 */
	function agent_connector_for_wp_register_ability( string $name, array $args ): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		// Normalize meta and fold in the optional `annotations` shorthand.
		$meta = isset( $args['meta'] ) && is_array( $args['meta'] ) ? $args['meta'] : array();
		if ( isset( $args['annotations'] ) && is_array( $args['annotations'] ) ) {
			$meta['annotations'] = array_merge(
				isset( $meta['annotations'] ) && is_array( $meta['annotations'] ) ? $meta['annotations'] : array(),
				$args['annotations']
			);
		}
		unset( $args['annotations'] );

		// Force MCP exposure so the adapter surfaces this ability.
		$meta['mcp']          = array_merge(
			isset( $meta['mcp'] ) && is_array( $meta['mcp'] ) ? $meta['mcp'] : array(),
			array( 'public' => true )
		);
		$meta['show_in_rest'] = true;
		$args['meta']         = $meta;

		// The caller does NOT own auth. Drop anything they passed; Governance
		// injects our permission callback for every MCP-public ability.
		unset( $args['permission_callback'] );

		// Hand the redaction summarizer to the governance/audit chokepoint under
		// our private key. Governance consumes it when it wraps the handler and
		// strips the key before WP_Ability is constructed.
		if ( isset( $args['summarize'] ) && is_callable( $args['summarize'] ) ) {
			$args['acfw_summarize'] = $args['summarize'];
		}
		unset( $args['summarize'] );

		// Register via core. The execute_callback is wrapped, and the permission
		// callback injected, by AgentConnectorForWp\Support\Governance on the
		// `wp_register_ability_args` filter — the same chokepoint built-ins use.
		wp_register_ability( $name, $args );
	}
}

if ( ! function_exists( 'acfw_register_ability' ) ) {
	/**
	 * Short alias for {@see agent_connector_for_wp_register_ability()}.
	 *
	 * @since 1.4.0
	 *
	 * @param string              $name The ability name, with the caller's vendor namespace.
	 * @param array<string,mixed> $args Ability definition. See the canonical function.
	 * @return void
	 */
	function acfw_register_ability( string $name, array $args ): void {
		agent_connector_for_wp_register_ability( $name, $args );
	}
}
