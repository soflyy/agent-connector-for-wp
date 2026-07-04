<?php
/**
 * OAuth 2.1 Bearer token interceptor for MCP REST requests.
 *
 * @package AgentConnectorForWp
 */

declare( strict_types=1 );

namespace AgentConnectorForWp\OAuth;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Validates Bearer tokens on incoming MCP requests and maps them to their
 * owning WordPress user.
 *
 * Hooks `rest_authentication_errors` (priority 5) for routes under the MCP
 * adapter's `/mcp/` namespace only. When no Authorization header is present
 * it defers to other auth (Application Passwords via the proxy keeps working
 * unchanged) or returns a 401 whose WWW-Authenticate header points at the
 * .well-known discovery document — that 401 is how MCP clients bootstrap the
 * OAuth flow.
 *
 * Note: this layer only authenticates. Authorization stays with
 * {@see \AgentConnectorForWp\Support\Governance}, which forces the
 * admin/super-admin permission gate onto every exposed ability — a token
 * held by a non-admin authenticates but can execute nothing.
 */
final class Interceptor {

	/**
	 * Resolved OAuth context for the current REST request.
	 *
	 * @var array{client_id?:string,user_id?:int,token_id?:int}
	 */
	private static array $current = array();

	/**
	 * Register the authentication filter. Idempotent.
	 */
	public static function init(): void {
		if ( ! has_filter( 'rest_authentication_errors', array( self::class, 'authenticate' ) ) ) {
			add_filter( 'rest_authentication_errors', array( self::class, 'authenticate' ), 5 );
		}
	}

	/**
	 * The OAuth client_id for the current request; empty string when the
	 * request is not OAuth-authenticated (cookie, application password, none).
	 */
	public static function get_current_client_id(): string {
		return isset( self::$current['client_id'] ) ? (string) self::$current['client_id'] : '';
	}

	/**
	 * The full OAuth context for the current request.
	 *
	 * @return array{client_id?:string,user_id?:int,token_id?:int}
	 */
	public static function get_current_context(): array {
		return self::$current;
	}

	/**
	 * Authenticate MCP requests via Bearer token.
	 *
	 * @param WP_Error|null|true $result Existing authentication result.
	 * @return WP_Error|null|true
	 */
	public static function authenticate( $result ) {
		// Another auth mechanism already resolved — do not interfere.
		if ( null !== $result ) {
			return $result;
		}

		$request_uri = isset( $_SERVER['REQUEST_URI'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) )
			: '';

		// Only intercept the MCP adapter namespace.
		if ( false === strpos( $request_uri, '/mcp/' ) ) {
			return $result;
		}

		// Never intercept our own OAuth endpoints.
		if ( false !== strpos( $request_uri, '/' . Server::REST_NAMESPACE . '/' ) ) {
			return $result;
		}

		// Already authenticated by another mechanism — cookie auth, or Basic
		// auth Application Passwords (which core resolves in
		// determine_current_user, BEFORE this filter runs at priority 5, while
		// $result is still null). Without this early check a Basic header
		// would be rejected below for not being Bearer.
		if ( is_user_logged_in() ) {
			Server::send_cors_headers();
			return $result;
		}

		$auth_header = self::get_authorization_header();

		// No Authorization header — 401 whose WWW-Authenticate header
		// advertises the OAuth discovery document (MCP flow bootstrap).
		if ( '' === $auth_header ) {
			Server::send_cors_headers();
			header(
				sprintf(
					'WWW-Authenticate: Bearer resource_metadata="%s"',
					esc_url( home_url( '/.well-known/oauth-protected-resource' ) )
				)
			);
			return new WP_Error(
				'rest_not_logged_in',
				'Authentication required. Use OAuth 2.1 Bearer token.',
				array( 'status' => 401 )
			);
		}

		if ( 0 !== strpos( $auth_header, 'Bearer ' ) ) {
			return new WP_Error(
				'rest_invalid_auth',
				'Authorization header must use Bearer scheme, or a valid Application Password with Basic scheme.',
				array( 'status' => 401 )
			);
		}

		$token     = substr( $auth_header, 7 );
		$token_row = Db::get_token_by_access_hash( Db::hash_token( $token ) );

		if ( null === $token_row ) {
			return new WP_Error(
				'rest_invalid_token',
				'Invalid or expired access token.',
				array( 'status' => 401 )
			);
		}

		// Map the validated token to its owner — the same pattern WordPress
		// core uses for Application Passwords. This does NOT create or log in
		// users. Governance still runs its admin permission gate per ability.
		wp_set_current_user( (int) $token_row['user_id'] );

		self::$current = array(
			'client_id' => (string) ( $token_row['client_id'] ?? '' ),
			'user_id'   => (int) $token_row['user_id'],
			'token_id'  => (int) ( $token_row['id'] ?? 0 ),
		);

		// Send CORS headers early: SSE streams from the MCP adapter can bypass
		// rest_pre_serve_request, so headers must already be out.
		Server::send_cors_headers();

		return true;
	}

	/**
	 * Retrieve the Authorization header across server configurations
	 * (Apache mod_php, mod_cgi, nginx expose it under different keys).
	 */
	private static function get_authorization_header(): string {
		if ( ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			return sanitize_text_field( wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ) );
		}

		// Apache mod_rewrite may move the header here.
		if ( ! empty( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			return sanitize_text_field( wp_unslash( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) );
		}

		// Apache mod_cgi fallback.
		if ( function_exists( 'apache_request_headers' ) ) {
			$headers = apache_request_headers();
			if ( is_array( $headers ) ) {
				foreach ( $headers as $key => $value ) {
					if ( 'authorization' === strtolower( (string) $key ) ) {
						return sanitize_text_field( (string) $value );
					}
				}
			}
		}

		return '';
	}
}
