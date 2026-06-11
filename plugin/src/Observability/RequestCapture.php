<?php
/**
 * Request-scoped capture of raw MCP JSON-RPC request/response bodies.
 *
 * @package AgentConnectorForWp
 */

declare( strict_types=1 );

namespace AgentConnectorForWp\Observability;

defined( 'ABSPATH' ) || exit;

/**
 * The MCP adapter only hands sanitized tags to `record_event`; the full
 * input/output never reach the observability layer. We grab them here at the
 * REST boundary and stash them in static (request-scoped) storage so the
 * {@see DatabaseObservabilityHandler} can persist them.
 *
 * Last-write-wins. The statics reset between requests automatically because
 * they live only for the request lifetime.
 */
final class RequestCapture {

	/**
	 * Max bytes retained per body. Keeps the table from ballooning on large
	 * payloads; the rest is dropped with a `...[truncated]` marker.
	 */
	private const MAX_BODY_BYTES = 65536;

	/** @var string|null */
	private static $input = null;

	/** @var string|null */
	private static $output = null;

	/** @var array<string, mixed>|null */
	private static $pending_row = null;

	/**
	 * Hook capture onto the REST dispatch boundary.
	 */
	public static function register(): void {
		add_filter( 'rest_pre_dispatch', array( self::class, 'capture_request' ), 10, 3 );
		add_filter( 'rest_post_dispatch', array( self::class, 'capture_response' ), 10, 3 );
	}

	/**
	 * Whether a REST route is the MCP Adapter default server's route.
	 *
	 * Defaults to `mcp/mcp-adapter-default-server`. Override with the
	 * `agent_connector_for_wp_mcp_route_regex` filter if the default server is
	 * relocated via `mcp_adapter_default_server_config`.
	 */
	private static function is_mcp_route( string $route ): bool {
		/**
		 * Filters the regex used to detect MCP REST routes for logging.
		 *
		 * @param string $regex Anchored PCRE pattern matched against the REST route.
		 */
		$regex = (string) apply_filters(
			'agent_connector_for_wp_mcp_route_regex',
			'#^/mcp/mcp-adapter-default-server/?$#'
		);

		return (bool) preg_match( $regex, $route );
	}

	/**
	 * Capture the raw request body before the adapter dispatches it.
	 *
	 * @param mixed                                  $result  Short-circuit response (ignored).
	 * @param \WP_REST_Server                        $server  REST server.
	 * @param \WP_REST_Request<array<string, mixed>> $request The request.
	 * @return mixed
	 */
	public static function capture_request( $result, $server, $request ) {
		try {
			$route = (string) $request->get_route();
			if ( self::is_mcp_route( $route ) ) {
				self::set_input( (string) $request->get_body() );
			}
		} catch ( \Throwable $exception ) {
			error_log( '[ACFW MCP Observability] Failed to capture request body: ' . $exception->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}

		return $result;
	}

	/**
	 * Capture the response body after the adapter finishes.
	 *
	 * @param mixed                                  $result  REST response.
	 * @param \WP_REST_Server                        $server  REST server.
	 * @param \WP_REST_Request<array<string, mixed>> $request The request.
	 * @return mixed
	 */
	public static function capture_response( $result, $server, $request ) {
		try {
			$route = (string) $request->get_route();
			if ( self::is_mcp_route( $route ) ) {
				if ( $result instanceof \WP_REST_Response || $result instanceof \WP_HTTP_Response ) {
					$data = $result->get_data();
				} else {
					$data = $result;
				}

				$encoded = wp_json_encode( $data );
				self::set_output( false === $encoded ? '' : $encoded );
			}
		} catch ( \Throwable $exception ) {
			error_log( '[ACFW MCP Observability] Failed to capture response body: ' . $exception->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}

		return $result;
	}

	public static function set_input( string $body ): void {
		self::$input = self::truncate( $body );
	}

	public static function set_output( string $body ): void {
		self::$output = self::truncate( $body );
	}

	public static function get_input(): ?string {
		return self::$input;
	}

	public static function get_output(): ?string {
		return self::$output;
	}

	/**
	 * Stash a row to be inserted after the REST response is captured, so the
	 * row can include the actual `output_body`.
	 *
	 * @param array<string, mixed> $row Row pending its output body.
	 */
	public static function set_pending_row( array $row ): void {
		self::$pending_row = $row;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public static function take_pending_row(): ?array {
		$row               = self::$pending_row;
		self::$pending_row = null;

		return $row;
	}

	private static function truncate( string $body ): string {
		if ( strlen( $body ) <= self::MAX_BODY_BYTES ) {
			return $body;
		}

		return substr( $body, 0, self::MAX_BODY_BYTES ) . "\n...[truncated]";
	}
}
