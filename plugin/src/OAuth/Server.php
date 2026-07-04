<?php
/**
 * OAuth 2.1 server orchestrator: discovery, routes, DCR, CORS.
 *
 * @package AgentConnectorForWp
 */

declare( strict_types=1 );

namespace AgentConnectorForWp\OAuth;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Wires up the OAuth 2.1 authorization server that lets MCP clients (e.g.
 * claude.ai remote connectors) authenticate against this site directly over
 * Streamable HTTP — no mcp-wordpress-remote proxy or application password
 * required.
 *
 * Registers:
 *   - /.well-known/oauth-protected-resource and
 *     /.well-known/oauth-authorization-server discovery documents,
 *   - REST routes under `acfw-auth/v1`: /register (DCR, RFC 7591),
 *     /authorize (consent), /token (RFC 6749 §4.1.3), /revoke (RFC 7009),
 *   - CORS handling for the OAuth and MCP endpoints, and
 *   - the Bearer token {@see Interceptor} on MCP requests.
 *
 * All protocol endpoints use `__return_true` as permission_callback because
 * they MUST be publicly reachable per the OAuth 2.1 spec; each endpoint
 * implements its own security controls (see the route registrations below).
 */
final class Server {

	/**
	 * REST namespace for all OAuth endpoints.
	 */
	public const REST_NAMESPACE = 'acfw-auth/v1';

	/**
	 * Scopes this server advertises and understands.
	 *
	 * @var string[]
	 */
	private const SCOPES = array( 'mcp:tools', 'mcp:read', 'mcp:write' );

	/**
	 * Bootstrap all OAuth hooks and filters. Call once from the plugin
	 * bootstrap, after the enable/environment gates have passed.
	 */
	public static function init(): void {
		// Tables must exist before any endpoint runs. Priority 0: ahead of the
		// well-known/preflight handlers below on the same hook.
		add_action( 'init', array( Db::class, 'maybe_create' ), 0 );

		// .well-known endpoints — early in init, before WP routing.
		add_action( 'init', array( self::class, 'handle_well_known' ), 1 );

		// CORS preflight (OPTIONS).
		add_action( 'init', array( self::class, 'handle_preflight' ), 1 );

		// REST API route registration.
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );

		// CORS headers on REST responses.
		add_action( 'rest_api_init', array( self::class, 'add_cors_filters' ) );

		// Bearer token interceptor for MCP requests.
		Interceptor::init();
	}

	/**
	 * Handle .well-known OAuth discovery requests.
	 */
	public static function handle_well_known(): void {
		if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return;
		}

		$request_uri = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) );
		$request_uri = (string) strtok( $request_uri, '?' );

		// Handle subdirectory installs by stripping the home_url path.
		$home_path = wp_parse_url( home_url(), PHP_URL_PATH );
		$home_path = is_string( $home_path ) ? $home_path : '';
		$relative  = '' !== $home_path ? substr( $request_uri, strlen( $home_path ) ) : $request_uri;

		if ( '/.well-known/oauth-protected-resource' === $relative ) {
			self::send_protected_resource_metadata();
		}

		if ( '/.well-known/oauth-authorization-server' === $relative ) {
			self::send_authorization_server_metadata();
		}
	}

	/**
	 * Send the OAuth protected resource metadata JSON (RFC 9728).
	 */
	private static function send_protected_resource_metadata(): void {
		self::send_cors_headers();
		wp_send_json(
			array(
				'resource'                 => home_url(),
				'authorization_servers'    => array( home_url() ),
				'bearer_methods_supported' => array( 'header' ),
				'scopes_supported'         => self::SCOPES,
			)
		);
	}

	/**
	 * Send the OAuth authorization server metadata JSON (RFC 8414).
	 */
	private static function send_authorization_server_metadata(): void {
		self::send_cors_headers();
		wp_send_json(
			array(
				'issuer'                                => home_url(),
				'authorization_endpoint'                => rest_url( self::REST_NAMESPACE . '/authorize' ),
				'token_endpoint'                        => rest_url( self::REST_NAMESPACE . '/token' ),
				'registration_endpoint'                 => rest_url( self::REST_NAMESPACE . '/register' ),
				'revocation_endpoint'                   => rest_url( self::REST_NAMESPACE . '/revoke' ),
				'scopes_supported'                      => self::SCOPES,
				'response_types_supported'              => array( 'code' ),
				'grant_types_supported'                 => array( 'authorization_code', 'refresh_token' ),
				'token_endpoint_auth_methods_supported' => array( 'none', 'client_secret_post' ),
				'code_challenge_methods_supported'      => array( 'S256' ),
			)
		);
	}

	/**
	 * Register all OAuth REST API routes.
	 *
	 * Every route below is intentionally public (`__return_true`) per the
	 * OAuth 2.1 specification; the security lives inside each handler:
	 *
	 *  - /register : DCR (RFC 7591) — validates client metadata; HTTPS-only
	 *                redirect_uris.
	 *  - /authorize: consent page — requires a logged-in administrator
	 *                (Config::has_admin_access); verifies a nonce on POST;
	 *                validates client + exact redirect_uri + PKCE S256.
	 *  - /token    : code exchange — validates code, PKCE verifier, client_id,
	 *                redirect_uri; single-use codes; reuse revokes the client.
	 *  - /revoke   : RFC 7009 — always 200; the token is proof of possession.
	 */
	public static function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/register',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'handle_register' ),
				'permission_callback' => '__return_true', // Public per RFC 7591.
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/authorize',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( Authorize::class, 'handle_get' ),
					'permission_callback' => '__return_true', // Admin login enforced in callback.
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( Authorize::class, 'handle_post' ),
					'permission_callback' => '__return_true', // Nonce + admin login verified in callback.
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/token',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( Token::class, 'handle' ),
				'permission_callback' => '__return_true', // Public per RFC 6749.
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/revoke',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( Token::class, 'handle_revoke' ),
				'permission_callback' => '__return_true', // Public per RFC 7009.
			)
		);
	}

	/**
	 * Handle Dynamic Client Registration (RFC 7591).
	 *
	 * @param WP_REST_Request $request The incoming REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_register( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$body = $request->get_json_params();
		$body = is_array( $body ) ? $body : array();

		$client_name    = sanitize_text_field( (string) ( $body['client_name'] ?? '' ) );
		$redirect_uris  = $body['redirect_uris'] ?? array();
		$grant_types    = $body['grant_types'] ?? array( 'authorization_code', 'refresh_token' );
		$response_types = $body['response_types'] ?? array( 'code' );
		$auth_method    = sanitize_text_field( (string) ( $body['token_endpoint_auth_method'] ?? 'none' ) );

		if ( '' === $client_name || empty( $redirect_uris ) || ! is_array( $redirect_uris ) ) {
			return new WP_Error(
				'invalid_client_metadata',
				'client_name and redirect_uris are required.',
				array( 'status' => 400 )
			);
		}

		foreach ( $redirect_uris as $uri ) {
			$uri = esc_url_raw( (string) $uri );
			if ( '' === $uri || 0 !== strpos( $uri, 'https://' ) ) {
				return new WP_Error(
					'invalid_redirect_uri',
					'All redirect_uris must be valid HTTPS URLs.',
					array( 'status' => 400 )
				);
			}
		}
		$redirect_uris = array_map( 'esc_url_raw', array_map( 'strval', $redirect_uris ) );

		if ( ! in_array( $auth_method, array( 'none', 'client_secret_post' ), true ) ) {
			return new WP_Error(
				'invalid_client_metadata',
				'token_endpoint_auth_method must be "none" or "client_secret_post".',
				array( 'status' => 400 )
			);
		}

		$client = Db::insert_client(
			array(
				'client_name'                => $client_name,
				'redirect_uris'              => $redirect_uris,
				'grant_types'                => $grant_types,
				'token_endpoint_auth_method' => $auth_method,
			)
		);

		if ( false === $client ) {
			return new WP_Error( 'server_error', 'Could not register client.', array( 'status' => 500 ) );
		}

		$response_data = array(
			'client_id'                  => $client['client_id'],
			'client_name'                => $client['client_name'],
			'redirect_uris'              => $client['redirect_uris'],
			'grant_types'                => $client['grant_types'],
			'response_types'             => $response_types,
			'token_endpoint_auth_method' => $client['token_endpoint_auth_method'],
		);

		if ( ! empty( $client['client_secret'] ) ) {
			$response_data['client_secret'] = $client['client_secret'];
		}

		return new WP_REST_Response( $response_data, 201 );
	}

	/**
	 * Handle CORS preflight (OPTIONS) requests for OAuth/MCP endpoints.
	 */
	public static function handle_preflight(): void {
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'OPTIONS' !== $_SERVER['REQUEST_METHOD'] ) {
			return;
		}

		$request_uri = isset( $_SERVER['REQUEST_URI'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) )
			: '';

		$needs_cors = false !== strpos( $request_uri, self::REST_NAMESPACE )
			|| false !== strpos( $request_uri, '.well-known/oauth' )
			|| false !== strpos( $request_uri, '/mcp/' );

		if ( ! $needs_cors ) {
			return;
		}

		self::send_cors_headers();
		status_header( 204 );
		exit;
	}

	/**
	 * Send CORS headers when the Origin is allowed.
	 *
	 * Defaults to Claude's origins; extend via the
	 * `agent_connector_for_wp_oauth_allowed_origins` filter.
	 */
	public static function send_cors_headers(): void {
		$origin = isset( $_SERVER['HTTP_ORIGIN'] )
			? sanitize_url( wp_unslash( $_SERVER['HTTP_ORIGIN'] ) )
			: '';

		/**
		 * Filters the origins allowed to make cross-origin OAuth/MCP requests.
		 *
		 * Defaults cover the popular browser-based agent clients. Native/desktop
		 * clients (Claude Desktop, Cursor, VS Code, etc.) don't send an Origin
		 * header, so they are unaffected by CORS and need no entry here.
		 *
		 * @param string[] $origins Allowed origins (scheme + host, no trailing slash).
		 */
		$allowed_origins = (array) apply_filters(
			'agent_connector_for_wp_oauth_allowed_origins',
			array(
				// Anthropic Claude.
				'https://claude.ai',
				'https://claude.com',
				// OpenAI ChatGPT.
				'https://chatgpt.com',
				'https://chat.openai.com',
				'https://platform.openai.com',
				// Google Gemini.
				'https://gemini.google.com',
				// Microsoft Copilot.
				'https://copilot.microsoft.com',
				'https://m365.cloud.microsoft',
				// Perplexity.
				'https://www.perplexity.ai',
				'https://perplexity.ai',
				// Mistral Le Chat.
				'https://chat.mistral.ai',
			)
		);

		if ( in_array( $origin, $allowed_origins, true ) ) {
			header( 'Access-Control-Allow-Origin: ' . $origin );
		}

		header( 'Access-Control-Allow-Methods: GET, POST, OPTIONS' );
		header( 'Access-Control-Allow-Headers: Authorization, Content-Type' );
		header( 'Access-Control-Allow-Credentials: true' );
		header( 'Vary: Origin' );
	}

	/**
	 * Add CORS + cache-control headers to REST responses on our routes.
	 */
	public static function add_cors_filters(): void {
		add_filter(
			'rest_pre_serve_request',
			static function ( $served, $result, $request ) {
				$route = $request->get_route();

				if ( 0 === strpos( $route, '/' . self::REST_NAMESPACE . '/' ) || 0 === strpos( $route, '/mcp/' ) ) {
					self::send_cors_headers();
				}

				// Prevent caching of token responses (RFC 6749 §5.1).
				if ( '/' . self::REST_NAMESPACE . '/token' === $route || '/' . self::REST_NAMESPACE . '/revoke' === $route ) {
					header( 'Cache-Control: no-store' );
					header( 'Pragma: no-cache' );
				}

				return $served;
			},
			10,
			4
		);
	}
}
