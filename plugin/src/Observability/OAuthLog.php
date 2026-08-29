<?php
/**
 * Debug logging for OAuth protocol requests (discovery, DCR, consent, token).
 *
 * @package AgentConnectorForWp
 */

declare( strict_types=1 );

namespace AgentConnectorForWp\Observability;

use AgentConnectorForWp\OAuth\Server;
use AgentConnectorForWp\Support\Helpers;

defined( 'ABSPATH' ) || exit;

/**
 * Records every OAuth protocol request as an `oauth.*` event in the MCP events
 * table (the same table the MCP Events / Log page reads), while the Debug
 * setting is on.
 *
 * Remote clients (claude.ai connectors) run the whole OAuth flow from their
 * cloud, so when a step fails there the operator only sees the client's opaque
 * error message. These rows show what actually arrived and what this server
 * answered:
 *
 *  - `oauth.discovery` — any hit under /.well-known/oauth-*, including the
 *    RFC 9728 path-suffixed form this server doesn't serve (visible as a 404).
 *    Logged even while the OAuth server itself is disabled, which makes "the
 *    client reached us but OAuth is off" distinguishable from "no traffic".
 *  - `oauth.register` / `oauth.authorize` / `oauth.token` / `oauth.revoke` —
 *    the acfw-auth/v1 REST endpoints, with request body, response body, HTTP
 *    status and error code.
 *
 * Bodies are redacted before persisting (see REDACTED_KEYS): DCR metadata is
 * public by design, but token responses, PKCE verifiers and authorization
 *  codes must never land in the database.
 *
 * Rows normally insert on `rest_post_dispatch`; the consent page and the
 * discovery documents exit before that filter runs (`wp_send_json`,
 * `wp_redirect` + exit), so a `shutdown` flush — which WordPress still fires
 * after die() — is the safety net that records those with the final
 * http_response_code().
 */
final class OAuthLog {

	/**
	 * Max bytes retained per logged body (matches RequestCapture).
	 */
	private const MAX_BODY_BYTES = 65536;

	/**
	 * Parameter names whose values are replaced with "[redacted]" wherever they
	 * appear in a request or response body. client_id, redirect_uris, scope,
	 * state and code_challenge are deliberately not here — they're the
	 * non-secret metadata this log exists to show.
	 *
	 * "code" is request-only (see REQUEST_ONLY_REDACTED_KEYS): in a token
	 * request it's the authorization code (secret), but in a response body it's
	 * the WP_Error slug (e.g. invalid_client_metadata) that the log exists to
	 * surface.
	 *
	 * @var string[]
	 */
	private const REDACTED_KEYS = array(
		'client_secret',
		'code_verifier',
		'refresh_token',
		'access_token',
		'token',
		'password',
		'authorization',
	);

	/**
	 * Additional keys redacted only in request bodies.
	 *
	 * @var string[]
	 */
	private const REQUEST_ONLY_REDACTED_KEYS = array( 'code' );

	/**
	 * Row waiting for its response/status, keyed request-scoped like
	 * {@see RequestCapture}. ['row' => array, 'route' => string, 'start' => float]
	 *
	 * @var array<string, mixed>|null
	 */
	private static $pending = null;

	/**
	 * Hook the capture points. Call only when the Debug setting is on (see
	 * Observability::register()).
	 */
	public static function register(): void {
		// Priority 0: ahead of Server::handle_well_known (init, 1), which exits.
		add_action( 'init', array( self::class, 'sniff_discovery' ), 0 );
		add_filter( 'rest_request_before_callbacks', array( self::class, 'capture_request' ), 10, 3 );
		add_filter( 'rest_post_dispatch', array( self::class, 'capture_response' ), 10, 3 );
		add_action( 'shutdown', array( self::class, 'flush' ) );
	}

	/**
	 * Stash a pending row for any /.well-known/oauth-* request. The discovery
	 * handlers respond via wp_send_json + exit, so the row completes in flush().
	 */
	public static function sniff_discovery(): void {
		if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return;
		}

		$uri = (string) strtok( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ), '?' );
		if ( false === strpos( $uri, '/.well-known/oauth-' ) ) {
			return;
		}

		self::$pending = array(
			'row'   => self::base_row( 'oauth.discovery', self::request_method(), $uri ),
			'route' => null,
			'start' => microtime( true ),
		);
	}

	/**
	 * Stash a pending row when an acfw-auth/v1 endpoint is about to run.
	 *
	 * @param mixed                                  $response Unused pass-through.
	 * @param mixed                                  $handler  Unused.
	 * @param \WP_REST_Request<array<string, mixed>> $request  The request.
	 * @return mixed
	 */
	public static function capture_request( $response, $handler, $request ) {
		try {
			$route  = (string) $request->get_route();
			$prefix = '/' . Server::REST_NAMESPACE . '/';
			if ( 0 !== strpos( $route, $prefix ) ) {
				return $response;
			}

			$row               = self::base_row( 'oauth.' . substr( $route, strlen( $prefix ) ), $request->get_method(), $route );
			$row['input_body'] = self::encode_body( self::request_params( $request ), true );

			self::$pending = array(
				'row'   => $row,
				'route' => $route,
				'start' => microtime( true ),
			);
		} catch ( \Throwable $exception ) {
			error_log( '[ACFW OAuth Log] Failed to capture request: ' . $exception->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}

		return $response;
	}

	/**
	 * Complete and insert the pending row once the REST response exists.
	 *
	 * @param mixed                                  $result  REST response (passed through).
	 * @param \WP_REST_Server                        $server  REST server.
	 * @param \WP_REST_Request<array<string, mixed>> $request The request.
	 * @return mixed
	 */
	public static function capture_response( $result, $server, $request ) {
		try {
			if ( null === self::$pending || (string) $request->get_route() !== self::$pending['route'] ) {
				return $result;
			}

			$row = self::$pending['row'];

			if ( $result instanceof \WP_HTTP_Response ) {
				$status = (int) $result->get_status();
				$data   = $result->get_data();

				$row['status']      = (string) $status;
				$row['output_body'] = self::encode_body( is_array( $data ) ? $data : array( 'response' => $data ) );

				if ( $status >= 400 && is_array( $data ) ) {
					$row['error_code']     = isset( $data['code'] ) ? substr( (string) $data['code'], 0, 32 ) : null;
					$row['failure_reason'] = isset( $data['message'] ) ? (string) $data['message'] : null;
				}
			}

			$row['duration_ms'] = round( ( microtime( true ) - (float) self::$pending['start'] ) * 1000, 2 );

			self::$pending = null;
			DatabaseObservabilityHandler::record_row( $row );
		} catch ( \Throwable $exception ) {
			error_log( '[ACFW OAuth Log] Failed to record response: ' . $exception->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}

		return $result;
	}

	/**
	 * Insert a still-pending row at shutdown — the path for handlers that exit
	 * before `rest_post_dispatch` (discovery documents, the consent page HTML,
	 * the authorization redirect). WordPress runs the `shutdown` action even
	 * after die(), so the final http_response_code() is available here.
	 */
	public static function flush(): void {
		if ( null === self::$pending ) {
			return;
		}

		try {
			$row           = self::$pending['row'];
			$status        = http_response_code();
			$row['status'] = is_int( $status ) ? (string) $status : null;

			$row['duration_ms'] = round( ( microtime( true ) - (float) self::$pending['start'] ) * 1000, 2 );

			self::$pending = null;
			DatabaseObservabilityHandler::record_row( $row );
		} catch ( \Throwable $exception ) {
			error_log( '[ACFW OAuth Log] Failed to flush pending row: ' . $exception->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	/**
	 * Common columns for every oauth.* row.
	 *
	 * @param string $event  Event name (oauth.…).
	 * @param string $method HTTP method.
	 * @param string $uri    Request route/URI, stored in resource_uri.
	 * @return array<string, mixed>
	 */
	private static function base_row( string $event, string $method, string $uri ): array {
		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		$origin     = isset( $_SERVER['HTTP_ORIGIN'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_ORIGIN'] ) ) : '';

		$tags = array(
			'ip'         => Helpers::client_ip(),
			'user_agent' => $user_agent,
			'origin'     => $origin,
		);

		$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;

		return array(
			'event'        => substr( $event, 0, 64 ),
			'method'       => substr( $method, 0, 64 ),
			'resource_uri' => $uri,
			'user_id'      => $user_id > 0 ? $user_id : null,
			'tags_json'    => (string) wp_json_encode( $tags ),
			'created_at'   => gmdate( 'Y-m-d H:i:s' ),
		);
	}

	/**
	 * The request parameters in the most useful available form: JSON body,
	 * form-encoded body (the token endpoint per RFC 6749), or query string
	 * (the authorize GET).
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request The request.
	 * @return array<string, mixed>
	 */
	private static function request_params( $request ): array {
		$json = $request->get_json_params();
		if ( is_array( $json ) && array() !== $json ) {
			return $json;
		}

		$body = $request->get_body_params();
		if ( is_array( $body ) && array() !== $body ) {
			return $body;
		}

		$query = $request->get_query_params();
		return is_array( $query ) ? $query : array();
	}

	/**
	 * Redact + JSON-encode + truncate a body for storage.
	 *
	 * @param array<string, mixed> $data       Decoded body.
	 * @param bool                 $is_request Whether request-only keys also redact.
	 */
	private static function encode_body( array $data, bool $is_request = false ): ?string {
		if ( array() === $data ) {
			return null;
		}

		$keys = $is_request
			? array_merge( self::REDACTED_KEYS, self::REQUEST_ONLY_REDACTED_KEYS )
			: self::REDACTED_KEYS;

		$encoded = wp_json_encode( self::redact( $data, $keys ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		if ( false === $encoded ) {
			return null;
		}

		if ( strlen( $encoded ) > self::MAX_BODY_BYTES ) {
			$encoded = substr( $encoded, 0, self::MAX_BODY_BYTES ) . "\n...[truncated]";
		}

		return $encoded;
	}

	/**
	 * Recursively replace secret values.
	 *
	 * @param array<mixed> $data Decoded body.
	 * @param string[]     $keys Key names to redact.
	 * @return array<mixed>
	 */
	private static function redact( array $data, array $keys ): array {
		foreach ( $data as $key => $value ) {
			if ( is_array( $value ) ) {
				$data[ $key ] = self::redact( $value, $keys );
				continue;
			}

			if ( is_string( $key ) && in_array( strtolower( $key ), $keys, true ) && '' !== (string) $value ) {
				$data[ $key ] = '[redacted]';
			}
		}

		return $data;
	}

	/**
	 * The request method, outside REST context.
	 */
	private static function request_method(): string {
		return isset( $_SERVER['REQUEST_METHOD'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) )
			: '';
	}
}
