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
 * `agent_connector_for_wp_mcp_request`. Only requests from users who can use
 * the MCP server count (the same admin check Governance applies to every
 * ability): a lower-privileged account with an application password can't
 * pose as "an agent just connected". Unauthenticated requests (e.g. the 401
 * an OAuth client gets while discovering auth) don't count either.
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
			if ( ! self::is_mcp_route( (string) $request->get_route() ) || ! Config::has_admin_access() ) {
				return $result;
			}
			if ( is_wp_error( $result ) || ( $result instanceof \WP_HTTP_Response && $result->get_status() >= 400 ) ) {
				return $result;
			}

			$auth     = self::current_auth();
			$message  = self::jsonrpc_message( (string) $request->get_body() );
			$method   = isset( $message['method'] ) ? sanitize_text_field( (string) $message['method'] ) : '';
			$previous = self::get();

			// Only `initialize` says which agent this is. Later requests in the
			// same session inherit it from the record they continue.
			if ( 'initialize' === $method ) {
				$agent = self::agent_name( $message['params']['clientInfo'] ?? null );
			} elseif ( null !== $previous && $previous['user_id'] === get_current_user_id() && $previous['client_name'] === $auth['client_name'] ) {
				$agent = $previous['agent_name'];
			} else {
				$agent = '';
			}

			$info = array(
				'time'        => time(),
				'user_id'     => get_current_user_id(),
				'auth_method' => $auth['method'],
				'client_name' => $auth['client_name'],
				'agent_name'  => $agent,
				'mcp_method'  => $method,
			);

			/**
			 * Fires after a successful, authenticated request to the MCP server.
			 *
			 * @param array{time:int,user_id:int,auth_method:string,client_name:string,agent_name:string,mcp_method:string} $info
			 */
			do_action( 'agent_connector_for_wp_mcp_request', $info );

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
	 * `client_name` is the credential's label (OAuth client or application
	 * password name); `agent_name` is what the agent says it is in its MCP
	 * `initialize` request (e.g. "Claude Code"), empty if unknown.
	 *
	 * @return array{time:int,user_id:int,auth_method:string,client_name:string,agent_name:string,mcp_method:string}|null
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
			'agent_name'  => (string) ( $info['agent_name'] ?? '' ),
			'mcp_method'  => (string) ( $info['mcp_method'] ?? '' ),
		);
	}

	/**
	 * Display name for the agent from MCP `clientInfo`. Well-known clients get
	 * their product name (their `name` is often a slug); anything else uses
	 * `title`, then `name`, as given. The application-password path goes
	 * through the mcp-wordpress-remote proxy, which forwards the agent's own
	 * `initialize`, so this works for both auth methods.
	 *
	 * @param mixed $client_info The `params.clientInfo` object.
	 */
	private static function agent_name( $client_info ): string {
		if ( ! is_array( $client_info ) ) {
			return '';
		}

		$known = array(
			'claude-ai'             => 'Claude',
			'claude-code'           => 'Claude Code',
			'cursor-vscode'         => 'Cursor',
			'visual studio code'    => 'VS Code',
			'codex-mcp-client'      => 'Codex',
			'openai-mcp'            => 'ChatGPT',
			'windsurf-client'       => 'Windsurf',
			'gemini-cli-mcp-client' => 'Gemini CLI',
		);

		$name  = trim( (string) ( $client_info['name'] ?? '' ) );
		$title = trim( (string) ( $client_info['title'] ?? '' ) );

		$agent = $known[ strtolower( $name ) ] ?? ( '' !== $title ? $title : $name );

		return sanitize_text_field( substr( $agent, 0, 100 ) );
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
	 * The request's JSON-RPC message (first entry of a batch).
	 *
	 * @return array<string, mixed>
	 */
	private static function jsonrpc_message( string $body ): array {
		$decoded = json_decode( $body, true );
		if ( is_array( $decoded ) && array_is_list( $decoded ) ) {
			$decoded = $decoded[0] ?? null;
		}
		return is_array( $decoded ) ? $decoded : array();
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
