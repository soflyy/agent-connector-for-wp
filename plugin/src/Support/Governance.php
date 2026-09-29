<?php
/**
 * Governance: force OUR auth, domain-lock, and audit onto every ability our MCP
 * server will expose — no matter who registered it.
 *
 * @package AgentConnectorForWp
 */

declare( strict_types=1 );

namespace AgentConnectorForWp\Support;

use AgentConnectorForWp\Services\AuditLogger;
use AgentConnectorForWp\Services\WrappedHandler;
use WP\MCP\Abilities\McpAbilityExposure;

defined( 'ABSPATH' ) || exit;

/**
 * Single chokepoint that guarantees no ability reachable through our MCP server
 * uses anyone else's (or no) authentication.
 *
 * The default WordPress MCP server (wordpress/mcp-adapter) auto-exposes ANY
 * ability that resolves as MCP-public (an explicit `mcp.public` wins; when it is
 * absent, exposure is inherited from the high-level `meta.public` flag — see
 * the adapter's McpAbilityExposure), regardless of which plugin registered it.
 * That is convenient — but it means a third party could register a public
 * ability with `permission_callback => __return_true` and bypass our
 * admin/super-admin gate. To prevent that, this layer hooks the core
 * `wp_register_ability_args` filter (which fires for EVERY `wp_register_ability()`
 * call) and, for every ability that will be MCP-exposed, rewrites its arguments
 * before the WP_Ability object is built:
 *
 *   1. permission_callback  → our {@see Config::has_admin_access()} check
 *      (overriding whatever the registrant supplied), and
 *   2. execute_callback     → wrapped with {@see AuditLogger::wrap()} so the
 *      domain lock is enforced and the call is audit-logged.
 *
 * Working on the plain `$args` array (pre-construction) means no reflection and
 * no re-registration: the override is simply the last word before WP_Ability is
 * instantiated.
 *
 * Built-in abilities already wrap their own execute callback (via
 * AuditLogger::wrap, which returns a {@see WrappedHandler}); this layer detects
 * that marker and does not wrap a second time, so there is no double-logging.
 * Overriding their permission_callback with the identical check is a no-op.
 */
final class Governance {

	/**
	 * Register the governance filter. Idempotent — safe to call more than once.
	 */
	public static function register(): void {
		// Priority 99: run late so it wins over anything a registrant added to
		// the same filter, but still before WP_Ability is constructed.
		if ( ! has_filter( 'wp_register_ability_args', array( self::class, 'enforce' ) ) ) {
			add_filter( 'wp_register_ability_args', array( self::class, 'enforce' ), 99, 2 );
		}
	}

	/**
	 * Rewrite an ability's args to enforce our auth + audit, if it will be
	 * exposed over our MCP server.
	 *
	 * @param array<string,mixed> $args The ability registration arguments.
	 * @param string              $name The ability name, with namespace.
	 * @return array<string,mixed> The (possibly) governed arguments.
	 */
	public static function enforce( $args, $name ): array {
		$args = is_array( $args ) ? $args : array();
		$name = is_string( $name ) ? $name : '';

		if ( ! self::should_govern( $args, $name ) ) {
			return $args;
		}

		// (1) Force OUR permission callback. Whatever the registrant supplied
		// (including __return_true or nothing) is discarded.
		$args['permission_callback'] = array( Config::class, 'has_admin_access' );

		// (2) Ensure the execute callback runs through OUR domain-lock + audit
		// chokepoint — unless it already does (built-ins), to avoid double work.
		if ( isset( $args['execute_callback'] ) && is_callable( $args['execute_callback'] ) && ! ( $args['execute_callback'] instanceof WrappedHandler ) ) {
			$summarizer               = self::extract_summarizer( $args );
			$args['execute_callback'] = AuditLogger::wrap( $name, $args['execute_callback'], $summarizer );
		}

		// Clean up our own private key so it never lands in WP_Ability args
		// (which would trigger an "invalid property" doing-it-wrong notice).
		unset( $args['acfw_summarize'] );

		return $args;
	}

	/**
	 * Whether this ability will be exposed over our MCP server and therefore
	 * must be governed.
	 *
	 * Exposure is resolved exactly like the adapter's DefaultServerFactory does
	 * (see {@see self::is_mcp_exposed_meta()}). Integrators can exempt specific
	 * ability names through the `agent_connector_for_wp_govern_ability` filter
	 * (default: govern everything exposed).
	 *
	 * @param array<string,mixed> $args The ability registration arguments.
	 * @param string              $name The ability name.
	 */
	private static function should_govern( array $args, string $name ): bool {
		$meta      = isset( $args['meta'] ) && is_array( $args['meta'] ) ? $args['meta'] : array();
		$is_public = self::is_mcp_exposed_meta( $meta );

		/**
		 * Filters whether a given MCP-exposed ability is governed by Agent
		 * Connector's auth / domain-lock / audit chokepoint.
		 *
		 * Defaults to true for every ability that is exposed over our MCP server
		 * (resolves as MCP-public) and false for everything else. Return false for a
		 * specific name only if an integrator deliberately wants to take over
		 * authentication for that ability — doing so means our admin gate, domain
		 * lock, and audit log no longer apply to it.
		 *
		 * @since 1.4.0
		 *
		 * @param bool                $govern Whether to govern this ability. Default = is it MCP-public.
		 * @param string              $name   The ability name, with namespace.
		 * @param array<string,mixed> $args   The ability registration arguments.
		 */
		return (bool) apply_filters( 'agent_connector_for_wp_govern_ability', $is_public, $name, $args );
	}

	/**
	 * Whether ability meta resolves to MCP exposure, matching the adapter's own
	 * resolution (mcp-adapter ≥ 0.6): an explicit `mcp.public` wins; when it is
	 * absent, exposure is inherited from the high-level `meta.public` flag; a
	 * malformed `meta.mcp` fails closed.
	 *
	 * Defers to the bundled adapter's McpAbilityExposure so the two can never
	 * drift. The mirror below only runs when an older adapter (< 0.6, class
	 * absent) was loaded by another plugin — there, applying the 0.6 semantics
	 * can only over-govern, which is the safe direction.
	 *
	 * @param array<string,mixed> $meta The ability meta.
	 */
	public static function is_mcp_exposed_meta( array $meta ): bool {
		if ( class_exists( McpAbilityExposure::class ) ) {
			return McpAbilityExposure::is_meta_public( $meta );
		}

		$mcp_meta = $meta['mcp'] ?? array();
		if ( ! is_array( $mcp_meta ) ) {
			return false;
		}
		if ( isset( $mcp_meta['public'] ) ) {
			return (bool) $mcp_meta['public'];
		}
		return true === ( $meta['public'] ?? false );
	}

	/**
	 * Pull a summarize/redaction callback out of the args, if one was provided.
	 *
	 * Accepts either `acfw_summarize` (the key our public registration helper
	 * uses) or a `meta.acfw.summarize` callback, so a raw third-party
	 * registration can opt into audit redaction too.
	 *
	 * @param array<string,mixed> $args The ability registration arguments.
	 * @return callable|null
	 */
	private static function extract_summarizer( array $args ): ?callable {
		if ( isset( $args['acfw_summarize'] ) && is_callable( $args['acfw_summarize'] ) ) {
			return $args['acfw_summarize'];
		}
		if ( isset( $args['meta']['acfw']['summarize'] ) && is_callable( $args['meta']['acfw']['summarize'] ) ) {
			return $args['meta']['acfw']['summarize'];
		}
		return null;
	}
}
