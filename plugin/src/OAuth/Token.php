<?php
/**
 * OAuth 2.1 token endpoint: code exchange, refresh, revocation, PKCE.
 *
 * @package AgentConnectorForWp
 */

declare( strict_types=1 );

namespace AgentConnectorForWp\OAuth;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Token endpoint handler.
 *
 * Hardening notes (OAuth 2.1 / RFC 6749 / RFC 7636):
 *   - authorization codes are single-use: the code is claimed with a
 *     conditional `UPDATE … WHERE used = 0` before the remaining checks run,
 *     so concurrent exchanges of one code cannot both mint a token pair;
 *   - replaying a used code revokes every token for that client (§4.1.2:
 *     reuse means the code leaked — assume compromise);
 *   - a client that registered as confidential must present its
 *     client_secret; public clients are bound by PKCE alone;
 *   - all secret comparisons go through hash_equals();
 *   - grant errors share one generic message to prevent state enumeration;
 *   - refresh tokens rotate: the old pair is revoked when a new one is issued.
 */
final class Token {

	/**
	 * Generic error message per RFC 6749 to prevent state enumeration.
	 */
	private const GRANT_ERROR = 'The provided authorization grant is invalid, expired, or revoked.';

	/**
	 * POST /token — dispatch by grant_type.
	 *
	 * @param WP_REST_Request $request The incoming REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$grant_type = sanitize_text_field( (string) ( $request->get_param( 'grant_type' ) ?? '' ) );

		// Opportunistic cleanup.
		Db::cleanup_expired_codes();

		return match ( $grant_type ) {
			'authorization_code' => self::handle_authorization_code( $request ),
			'refresh_token'      => self::handle_refresh_token( $request ),
			default              => self::oauth_error( 'unsupported_grant_type', 'grant_type must be "authorization_code" or "refresh_token".' ),
		};
	}

	/**
	 * Exchange an authorization code (+ PKCE verifier) for a token pair.
	 *
	 * @param WP_REST_Request $request The incoming REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	private static function handle_authorization_code( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$code          = sanitize_text_field( (string) ( $request->get_param( 'code' ) ?? '' ) );
		$redirect_uri  = esc_url_raw( (string) ( $request->get_param( 'redirect_uri' ) ?? '' ) );
		$client_id     = sanitize_text_field( (string) ( $request->get_param( 'client_id' ) ?? '' ) );
		$code_verifier = sanitize_text_field( (string) ( $request->get_param( 'code_verifier' ) ?? '' ) );

		if ( '' === $code || '' === $client_id || '' === $code_verifier ) {
			return self::oauth_error( 'invalid_request', 'Missing required parameters: code, client_id, code_verifier.' );
		}

		// 1. Look up the authorization code.
		$code_row = Db::get_code( $code );
		if ( null === $code_row ) {
			return self::oauth_error( 'invalid_grant', self::GRANT_ERROR );
		}

		// 2. Replayed code — revoke all tokens for this client (RFC 6749 §4.1.2).
		if ( 1 === (int) $code_row['used'] ) {
			Db::revoke_all_for_client( (string) $code_row['client_id'] );
			return self::oauth_error( 'invalid_grant', self::GRANT_ERROR );
		}

		// 3. Check expiration BEFORE marking as used.
		if ( strtotime( (string) $code_row['expires_at'] ) < time() ) {
			return self::oauth_error( 'invalid_grant', self::GRANT_ERROR );
		}

		// 4. Claim the code IMMEDIATELY (single-use enforcement). The claim is a
		// conditional UPDATE, so losing it means another request got there
		// first — i.e. a replay, handled exactly like step 2. Step 2 stays
		// because it also catches a replay of an *expired* code, which never
		// reaches this line.
		if ( ! Db::mark_code_used( $code ) ) {
			Db::revoke_all_for_client( (string) $code_row['client_id'] );
			return self::oauth_error( 'invalid_grant', self::GRANT_ERROR );
		}

		// 5. Verify client_id (timing-safe).
		if ( ! hash_equals( (string) $code_row['client_id'], $client_id ) ) {
			return self::oauth_error( 'invalid_grant', self::GRANT_ERROR );
		}

		// 6. Authenticate the client itself when it registered as confidential.
		$client_auth_error = self::authenticate_client( $request, $client_id );
		if ( null !== $client_auth_error ) {
			return $client_auth_error;
		}

		// 7. Verify redirect_uri (timing-safe).
		if ( ! hash_equals( (string) $code_row['redirect_uri'], $redirect_uri ) ) {
			return self::oauth_error( 'invalid_grant', self::GRANT_ERROR );
		}

		// 8. PKCE verification (RFC 7636).
		if ( ! self::verify_pkce( $code_verifier, (string) $code_row['code_challenge'], (string) $code_row['code_challenge_method'] ) ) {
			return self::oauth_error( 'invalid_grant', self::GRANT_ERROR );
		}

		// 9. Issue the token pair.
		$token_data = Db::insert_token(
			array(
				'client_id' => $client_id,
				'user_id'   => (int) $code_row['user_id'],
				'scope'     => (string) $code_row['scope'],
			)
		);

		if ( false === $token_data ) {
			return self::oauth_error( 'server_error', 'Could not generate tokens.', 500 );
		}

		return new WP_REST_Response(
			array(
				'access_token'  => $token_data['access_token'],
				'token_type'    => 'Bearer',
				'expires_in'    => $token_data['expires_in'],
				'refresh_token' => $token_data['refresh_token'],
				'scope'         => $token_data['scope'],
			),
			200
		);
	}

	/**
	 * Exchange a refresh token for a new token pair (with rotation).
	 *
	 * @param WP_REST_Request $request The incoming REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	private static function handle_refresh_token( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$refresh_token = sanitize_text_field( (string) ( $request->get_param( 'refresh_token' ) ?? '' ) );
		$client_id     = sanitize_text_field( (string) ( $request->get_param( 'client_id' ) ?? '' ) );

		if ( '' === $refresh_token || '' === $client_id ) {
			return self::oauth_error( 'invalid_request', 'Missing required parameters: refresh_token, client_id.' );
		}

		$refresh_hash = Db::hash_token( $refresh_token );
		$token_row    = Db::get_token_by_refresh_hash( $refresh_hash );

		if ( null === $token_row ) {
			return self::oauth_error( 'invalid_grant', 'The provided refresh token is invalid, expired, or revoked.' );
		}

		if ( ! hash_equals( (string) $token_row['client_id'], $client_id ) ) {
			return self::oauth_error( 'invalid_grant', 'The provided refresh token is invalid, expired, or revoked.' );
		}

		$client_auth_error = self::authenticate_client( $request, $client_id );
		if ( null !== $client_auth_error ) {
			return $client_auth_error;
		}

		// Rotate: revoke the old pair, then issue a new one.
		Db::revoke_token_by_refresh_hash( $refresh_hash );

		$new_tokens = Db::insert_token(
			array(
				'client_id' => $client_id,
				'user_id'   => (int) $token_row['user_id'],
				'scope'     => (string) $token_row['scope'],
			)
		);

		if ( false === $new_tokens ) {
			return self::oauth_error( 'server_error', 'Could not generate tokens.', 500 );
		}

		return new WP_REST_Response(
			array(
				'access_token'  => $new_tokens['access_token'],
				'token_type'    => 'Bearer',
				'expires_in'    => $new_tokens['expires_in'],
				'refresh_token' => $new_tokens['refresh_token'],
				'scope'         => $new_tokens['scope'],
			),
			200
		);
	}

	/**
	 * POST /revoke — token revocation (RFC 7009). Always returns 200.
	 *
	 * @param WP_REST_Request $request The incoming REST request.
	 */
	public static function handle_revoke( WP_REST_Request $request ): WP_REST_Response {
		$token      = sanitize_text_field( (string) ( $request->get_param( 'token' ) ?? '' ) );
		$token_hint = sanitize_text_field( (string) ( $request->get_param( 'token_type_hint' ) ?? '' ) );

		if ( '' === $token ) {
			// RFC 7009: always 200, even for invalid/empty tokens.
			return new WP_REST_Response( null, 200 );
		}

		$hash = Db::hash_token( $token );

		if ( 'refresh_token' === $token_hint ) {
			Db::revoke_token_by_refresh_hash( $hash );
		} else {
			// Try access token first, then refresh.
			if ( ! Db::revoke_token_by_access_hash( $hash ) ) {
				Db::revoke_token_by_refresh_hash( $hash );
			}
		}

		return new WP_REST_Response( null, 200 );
	}

	/**
	 * Authenticate the client on a token request.
	 *
	 * Public clients (`token_endpoint_auth_method = none`) are the norm here —
	 * the MCP clients this server targets are native/browser apps that cannot
	 * keep a secret, and PKCE is what binds their requests. But a client MAY
	 * register as `client_secret_post`, and DCR hands it a secret when it does;
	 * this enforces that secret instead of letting the client silently fall
	 * back to bearer-of-client_id (a public identifier) authentication.
	 *
	 * @param WP_REST_Request $request   The incoming REST request.
	 * @param string          $client_id The already-validated client ID.
	 * @return WP_Error|null WP_Error when authentication fails, null on success.
	 */
	private static function authenticate_client( WP_REST_Request $request, string $client_id ): ?WP_Error {
		$client = Db::get_client_by_id( $client_id );

		if ( null === $client ) {
			return self::oauth_error( 'invalid_client', 'Unknown client.', 401 );
		}

		if ( 'none' === (string) $client['token_endpoint_auth_method'] ) {
			return null;
		}

		$stored = (string) ( $client['client_secret'] ?? '' );
		$given  = (string) ( $request->get_param( 'client_secret' ) ?? '' );

		// A confidential client with no stored secret can never authenticate —
		// fail closed rather than treating the empty string as a match.
		if ( '' === $stored || '' === $given ) {
			return self::oauth_error( 'invalid_client', 'Client authentication failed.', 401 );
		}

		if ( ! hash_equals( $stored, Db::hash_token( $given ) ) ) {
			return self::oauth_error( 'invalid_client', 'Client authentication failed.', 401 );
		}

		return null;
	}

	/**
	 * Verify a PKCE code_verifier against the stored code_challenge.
	 *
	 * RFC 7636 §4.6: code_challenge = BASE64URL(SHA256(code_verifier)).
	 *
	 * @param string $code_verifier    The PKCE code verifier from the client.
	 * @param string $stored_challenge The stored code challenge.
	 * @param string $method           The challenge method (must be S256).
	 */
	public static function verify_pkce( string $code_verifier, string $stored_challenge, string $method ): bool {
		if ( 'S256' !== $method ) {
			return false;
		}

		$computed_challenge = rtrim(
			strtr(
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- PKCE S256 challenge computation per RFC 7636.
				base64_encode( hash( 'sha256', $code_verifier, true ) ),
				'+/',
				'-_'
			),
			'='
		);

		return hash_equals( $stored_challenge, $computed_challenge );
	}

	/**
	 * Build a standardized OAuth error response.
	 *
	 * @param string $code        The OAuth error code.
	 * @param string $description The error description.
	 * @param int    $status      The HTTP status code.
	 */
	private static function oauth_error( string $code, string $description, int $status = 400 ): WP_Error {
		return new WP_Error( $code, $description, array( 'status' => $status ) );
	}
}
