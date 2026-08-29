<?php
/**
 * Bootstrap for the MCP observability feature (event logging + admin page).
 *
 * @package AgentConnectorForWp
 */

declare( strict_types=1 );

namespace AgentConnectorForWp\Observability;

use AgentConnectorForWp\Support\Config;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the MCP events logger into the MCP Adapter's *default* server and
 * exposes the "MCP Events" admin page.
 *
 * Agent Connector reuses the adapter's default server rather than creating its
 * own, so the observability handler is attached through the
 * `mcp_adapter_default_server_config` filter (the default server is created on
 * `mcp_adapter_init`, well after `plugins_loaded`, so registering the filter
 * here is early enough).
 */
final class Observability {

	public function register(): void {
		// Event logging is gated on the Debug setting (Connection screen): the
		// raw JSON-RPC bodies it captures can contain sensitive data, so the
		// adapter keeps its NullMcpObservabilityHandler until the operator
		// opts in.
		if ( Config::mcp_debug_enabled() ) {
			// Attach our handler to the default server's config. wp_parse_args in
			// DefaultServerFactory only fills *missing* keys, so overriding
			// observability_handler here wins over the NullMcpObservabilityHandler
			// default.
			add_filter( 'mcp_adapter_default_server_config', array( $this, 'set_observability_handler' ) );

			EventsTable::register();
			RequestCapture::register();
			DatabaseObservabilityHandler::register();

			// OAuth protocol logging (oauth.* events): discovery hits and the
			// acfw-auth/v1 endpoints, with secrets redacted. Registered under
			// the same Debug gate because the bodies include client metadata
			// and consent-flow details.
			OAuthLog::register();
		}

		// The MCP Events page stays available even with Debug off, so
		// previously logged events remain viewable; it shows a notice
		// pointing at the Debug setting while logging is disabled.
		if ( is_admin() ) {
			( new EventsPage() )->register();
		}
	}

	/**
	 * Point the default MCP server at the database-backed observability handler.
	 *
	 * @param mixed $config Default server config (array) from the adapter.
	 * @return mixed
	 */
	public function set_observability_handler( $config ) {
		if ( is_array( $config ) ) {
			$config['observability_handler'] = DatabaseObservabilityHandler::class;
		}

		return $config;
	}
}
