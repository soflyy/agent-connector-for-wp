<?php
/**
 * Mint and redeem one-time wp-admin login links.
 *
 * @package AgentConnectorForWp
 */

declare( strict_types=1 );

namespace AgentConnectorForWp\Services;

use AgentConnectorForWp\Support\Config;
use WP_Error;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Gives a browser-driving agent (Playwright et al.) a way into wp-admin.
 *
 * An agent authenticates to MCP with an application password, not browser
 * cookies — so it can call abilities but can't open the admin UI. This service
 * mints a single-use, short-lived URL that, when opened, logs the browser in as
 * the same super-admin who requested it and drops them at an admin screen.
 *
 * Design:
 *  - Token is random; only its SHA-256 hash is stored (in a transient keyed by
 *    that hash), so a leaked database row can't be replayed into a session.
 *  - One-time: the transient is deleted the moment it's redeemed.
 *  - Short TTL (default 5 min): expiry is the transient's own lifetime.
 *  - The URL is built from the *current request host*, not home_url(), so the
 *    party that will open it (a browser inside the same Docker network, reaching
 *    the site as http://wordpress) actually lands on a reachable host. The
 *    redemption then logs in and redirects on that same host, keeping the auth
 *    cookie's host aligned with where the browser is.
 */
final class AdminLoginLink {

	/**
	 * Query var that triggers redemption on the front end.
	 */
	public const QUERY_VAR = 'acfw_login';

	/**
	 * Transient key prefix; the suffix is the token hash.
	 */
	private const TRANSIENT_PREFIX = 'acfw_login_';

	public const DEFAULT_TTL = 300;
	public const MIN_TTL     = 30;
	public const MAX_TTL     = 900;

	/**
	 * Create a login link for the given user.
	 *
	 * @param int    $user_id     The super-admin to log in as.
	 * @param string $redirect_to Admin-relative target (e.g. "plugins.php"). Absolute
	 *                            URLs are rejected to prevent open redirects.
	 * @param int    $ttl_seconds Lifetime; clamped to [MIN_TTL, MAX_TTL].
	 * @return array{login_url:string,expires_at:string,expires_in_seconds:int}|WP_Error
	 */
	public static function create( int $user_id, string $redirect_to = '', int $ttl_seconds = self::DEFAULT_TTL ) {
		$user = get_user_by( 'id', $user_id );
		if ( ! ( $user instanceof WP_User ) || ! $user->exists() ) {
			return new WP_Error( 'agent_connector_login_no_user', 'Could not resolve the requesting user.', array( 'status' => 400 ) );
		}

		$ttl  = max( self::MIN_TTL, min( self::MAX_TTL, $ttl_seconds ) );
		$path = self::sanitize_admin_path( $redirect_to );

		$token = bin2hex( random_bytes( 32 ) );
		$hash  = hash( 'sha256', $token );

		set_transient(
			self::TRANSIENT_PREFIX . $hash,
			array(
				'user_id'     => $user->ID,
				'redirect_to' => $path,
			),
			$ttl
		);

		$login_url = add_query_arg( self::QUERY_VAR, $token, self::request_base_url() . '/' );

		return array(
			'login_url'          => $login_url,
			'expires_at'         => gmdate( 'c', time() + $ttl ),
			'expires_in_seconds' => $ttl,
		);
	}

	/**
	 * Front-end hook: if the request carries a valid login token, consume it,
	 * establish the session, and redirect into wp-admin. No-ops otherwise.
	 */
	public static function maybe_consume(): void {
		if ( empty( $_GET[ self::QUERY_VAR ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$token = sanitize_text_field( wp_unslash( $_GET[ self::QUERY_VAR ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! ctype_xdigit( $token ) ) {
			self::deny( 'This login link is malformed.' );
		}

		$hash = hash( 'sha256', $token );
		$key  = self::TRANSIENT_PREFIX . $hash;
		$data = get_transient( $key );

		// One-time: delete immediately, before any further checks, so a token
		// can never be redeemed twice even if a later check fails.
		delete_transient( $key );

		if ( ! is_array( $data ) || empty( $data['user_id'] ) ) {
			self::deny( 'This login link is invalid or has expired. Ask the agent to generate a fresh one.' );
		}

		// Re-check the domain lock at redemption: the site's identity could have
		// changed between link creation and the click.
		$lock_reason = Config::lock_mismatch_reason();
		if ( null !== $lock_reason ) {
			self::deny( $lock_reason );
		}

		$user = get_user_by( 'id', (int) $data['user_id'] );
		if ( ! ( $user instanceof WP_User ) || ! $user->exists() || ! is_super_admin( $user->ID ) ) {
			self::deny( 'The account this link was issued for is no longer a super admin.' );
		}

		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID, false );

		$target = self::request_base_url() . '/wp-admin/' . self::sanitize_admin_path( (string) ( $data['redirect_to'] ?? '' ) );

		// wp_safe_redirect() only allows hosts it knows; we redirect to the host
		// the browser actually used (which may differ from siteurl), so allow it
		// explicitly for this one redirect.
		$host = self::request_host();
		add_filter(
			'allowed_redirect_hosts',
			static function ( array $hosts ) use ( $host ): array {
				$hosts[] = $host;
				return $hosts;
			}
		);

		wp_safe_redirect( $target );
		exit;
	}

	/**
	 * Reject a redemption attempt with a plain, human-readable message (the
	 * person clicking is a human in a browser, not the agent).
	 */
	private static function deny( string $message ): void {
		wp_die(
			esc_html( $message ),
			esc_html__( 'Agent Connector for WP', 'agent-connector-for-wp' ),
			array( 'response' => 403 )
		);
	}

	/**
	 * scheme://host (with port) of the current request — the host the caller
	 * used to reach this site.
	 */
	private static function request_base_url(): string {
		$scheme = is_ssl() ? 'https' : 'http';
		return $scheme . '://' . self::request_host();
	}

	/**
	 * Host (including any :port) from the current request, fingerprint-safe.
	 */
	private static function request_host(): string {
		$host = isset( $_SERVER['HTTP_HOST'] ) ? wp_unslash( $_SERVER['HTTP_HOST'] ) : '';
		// Allow only the characters legal in a host:port; drop anything else.
		$host = preg_replace( '/[^A-Za-z0-9.\-:\[\]]/', '', (string) $host );
		if ( '' === (string) $host ) {
			// Fall back to the declared home host if the request has no Host.
			$host = Config::declared_host();
		}
		return (string) $host;
	}

	/**
	 * Reduce a caller-supplied redirect target to a safe admin-relative path.
	 *
	 * Rejects absolute and protocol-relative URLs (open-redirect guard) and
	 * strips a leading "wp-admin/" so callers can pass either form.
	 */
	private static function sanitize_admin_path( string $redirect_to ): string {
		$redirect_to = trim( $redirect_to );

		if ( '' === $redirect_to ) {
			return 'index.php';
		}

		// Anything that looks like it points off-path becomes the dashboard.
		if ( false !== strpos( $redirect_to, '://' ) || 0 === strpos( $redirect_to, '//' ) || 0 === strpos( $redirect_to, '/' ) ) {
			return 'index.php';
		}

		$redirect_to = preg_replace( '#^wp-admin/#', '', $redirect_to );

		return '' === $redirect_to ? 'index.php' : ltrim( $redirect_to, '/' );
	}
}
