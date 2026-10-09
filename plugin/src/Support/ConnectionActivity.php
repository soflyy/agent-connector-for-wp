<?php
/**
 * Records the most recent authenticated MCP request.
 *
 * @package AgentConnectorForWp
 */

declare( strict_types=1 );

namespace AgentConnectorForWp\Support;

use AgentConnectorForWp\OAuth\Db;
use AgentConnectorForWp\OAuth\Interceptor;

defined( 'ABSPATH' ) || exit;

/**
 * Answers "has an agent actually connected?" for any auth method.
 *
 * The existing timestamps are too coarse for that: OAuth's last_used_at is
 * throttled to once a minute and core only touches an application password's
 * last_used once a day. So after every successful, authenticated request to the
 * MCP route we keep a small summary in an option and fire
 * `agent_connector_for_wp_mcp_request`. Unauthenticated requests (e.g. the 401
 * an OAuth client gets while discovering auth) don't count.
 *
 * The summary is exposed on GET /agent-connector-for-wp/v1/status as
 * `last_mcp_request`, so a setup wizard can poll it and compare `time` against
 * when it started listening.
 */
final class ConnectionActivity {

	public const OPTION = 'agent_connector_for_wp_last_mcp_request';

	/**
	 * Minimum seconds between option writes for the same kind of request, so a
	 * busy agent doesn't write to the options table on every call. `initialize`
	 * (a new session) always writes.
	 */
	private const WRITE_THROTTLE = 10;

	public static function register(): void {
		add_filter( 'rest_post_dispatch', array( self::class, 'record' ), 20, 3 );
	}

	/**
	 * @param mixed                                  $result  REST response.
	 * @param \WP_REST_Server                        $server  REST server.
	 * @param \WP_REST_Request<array<string, mixed>> $request The request.
	 * @return mixed
	 */
	public static function record( $result, $server, $request ) {
		try {
			if ( ! self::is_mcp_route( (string) $request->get_route() ) || get_current_user_id() <= 0 ) {
				return $result;
			}
			if ( is_wp_error( $result ) || ( $result instanceof \WP_HTTP_Response && $result->get_status() >= 400 ) ) {
				return $result;
			}

			$auth = self::current_auth();
			$info = array(
				'time'        => time(),
				'user_id'     => get_current_user_id(),
				'auth_method' => $auth['method'],
				'client_name' => $auth['client_name'],
				'mcp_method'  => self::mcp_method( (string) $request->get_body() ),
			);

			/**
			 * Fires after a successful, authenticated request to the MCP server.
			 *
			 * @param array{time:int,user_id:int,auth_method:string,client_name:string,mcp_method:string} $info
			 */
			do_action( 'agent_connector_for_wp_mcp_request', $info );

			$previous = self::get();
			if (
				null === $previous
				|| 'initialize' === $info['mcp_method']
				|| $info['time'] - (int) $previous['time'] >= self::WRITE_THROTTLE
				|| $info['auth_method'] !== $previous['auth_method']
				|| $info['client_name'] !== $previous['client_name']
			) {
				update_option( self::OPTION, $info, false );
			}
		} catch ( \Throwable $exception ) {
			error_log( '[ACFW] Failed to record MCP request: ' . $exception->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}

		return $result;
	}

	/**
	 * The last recorded request, or null if no agent has connected yet.
	 *
	 * @return array{time:int,user_id:int,auth_method:string,client_name:string,mcp_method:string}|null
	 */
	public static function get(): ?array {
		$info = get_option( self::OPTION, null );
		if ( ! is_array( $info ) || empty( $info['time'] ) ) {
			return null;
		}
		return array(
			'time'        => (int) $info['time'],
			'user_id'     => (int) ( $info['user_id'] ?? 0 ),
			'auth_method' => (string) ( $info['auth_method'] ?? '' ),
			'client_name' => (string) ( $info['client_name'] ?? '' ),
			'mcp_method'  => (string) ( $info['mcp_method'] ?? '' ),
		);
	}

	/**
	 * @return array{method:string,client_name:string}
	 */
	private static function current_auth(): array {
		$client_id = Interceptor::get_current_client_id();
		if ( '' !== $client_id ) {
			$client = Db::get_client_by_id( $client_id );
			return array(
				'method'      => 'oauth',
				'client_name' => is_array( $client ) ? (string) $client['client_name'] : '',
			);
		}

		$uuid = function_exists( 'rest_get_authenticated_app_password' ) ? rest_get_authenticated_app_password() : null;
		if ( $uuid ) {
			$item = \WP_Application_Passwords::get_user_application_password( get_current_user_id(), $uuid );
			return array(
				'method'      => 'application_password',
				'client_name' => is_array( $item ) ? (string) $item['name'] : '',
			);
		}

		return array(
			'method'      => 'cookie',
			'client_name' => '',
		);
	}

	/**
	 * The JSON-RPC method of the request (first entry of a batch).
	 */
	private static function mcp_method( string $body ): string {
		$decoded = json_decode( $body, true );
		if ( is_array( $decoded ) && array_is_list( $decoded ) ) {
			$decoded = $decoded[0] ?? null;
		}
		return is_array( $decoded ) && isset( $decoded['method'] ) ? sanitize_text_field( (string) $decoded['method'] ) : '';
	}

	/** Same route check (and filter) as Observability\RequestCapture. */
	private static function is_mcp_route( string $route ): bool {
		/** This filter is documented in src/Observability/RequestCapture.php. */
		$regex = (string) apply_filters(
			'agent_connector_for_wp_mcp_route_regex',
			'#^/mcp/mcp-adapter-default-server/?$#'
		);

		return (bool) preg_match( $regex, $route );
	}
}
