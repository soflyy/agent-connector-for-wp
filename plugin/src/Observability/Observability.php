<?php
/**
 * Bootstrap for the MCP observability feature (event logging + admin page).
 *
 * @package AgentConnectorForWp
 */

declare( strict_types=1 );

namespace AgentConnectorForWp\Observability;

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
		// Attach our handler to the default server's config. wp_parse_args in
		// DefaultServerFactory only fills *missing* keys, so overriding
		// observability_handler here wins over the NullMcpObservabilityHandler
		// default.
		add_filter( 'mcp_adapter_default_server_config', array( $this, 'set_observability_handler' ) );

		EventsTable::register();
		RequestCapture::register();
		DatabaseObservabilityHandler::register();

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
