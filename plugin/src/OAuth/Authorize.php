<?php
/**
 * OAuth 2.1 authorization endpoint: consent page display and processing.
 *
 * @package AgentConnectorForWp
 */

declare( strict_types=1 );

namespace AgentConnectorForWp\OAuth;

use AgentConnectorForWp\Support\Config;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * GET renders the consent page; POST processes approve/deny.
 *
 * IMPORTANT — admin-only consent: this plugin grants root-equivalent
 * capability (shell, PHP eval, filesystem) to the agents acting on a token,
 * so unlike a generic OAuth server, ONLY administrators / super admins
 * ({@see Config::has_admin_access}) may authorize a client. A logged-in
 * non-admin never sees a consent page: they are sent back to the client with
 * `error=access_denied`, so the agent waiting on the callback learns the
 * request failed instead of hanging until it times out.
 */
final class Authorize {

	/**
	 * Nonce action for the consent form.
	 */
	private const NONCE_ACTION = 'acfw_oauth_authorize';

	/**
	 * Form field carrying the nonce. Named to avoid the REST API's reserved
	 * `_wpnonce` parameter.
	 */
	private const NONCE_FIELD = 'acfw_oauth_nonce';

	/**
	 * GET /authorize — validate params, check admin login, render consent page.
	 *
	 * @param WP_REST_Request $request The incoming REST request.
	 * @return WP_Error|void
	 */
	public static function handle_get( WP_REST_Request $request ) {
		$params = self::extract_params( $request );

		if ( 'code' !== $params['response_type'] ) {
			return new WP_Error(
				'unsupported_response_type',
				'Only response_type=code is supported.',
				array( 'status' => 400 )
			);
		}

		$client = Db::get_client_by_id( $params['client_id'] );
		if ( null === $client ) {
			return new WP_Error( 'invalid_client', 'Unknown client_id.', array( 'status' => 400 ) );
		}

		// Exact-match redirect_uri against the registered list.
		if ( ! in_array( $params['redirect_uri'], $client['redirect_uris'], true ) ) {
			return new WP_Error(
				'invalid_redirect_uri',
				'redirect_uri does not match registered URIs.',
				array( 'status' => 400 )
			);
		}

		// PKCE S256 is mandatory (OAuth 2.1). The redirect_uri is trusted by
		// this point, so the client is told why rather than left waiting.
		if ( 'S256' !== $params['code_challenge_method'] || '' === $params['code_challenge'] ) {
			self::redirect_with_error(
				$params['redirect_uri'],
				'invalid_request',
				'PKCE with S256 is required.',
				$params['state']
			);
		}

		// The REST API strips cookie auth without a wp_rest nonce; this is a
		// browser redirect flow, so restore the user from the logged_in cookie.
		self::restore_user_from_cookie();

		if ( ! is_user_logged_in() ) {
			$current_url = rest_url( Server::REST_NAMESPACE . '/authorize' ) . '?' . http_build_query( $params );
			wp_safe_redirect( wp_login_url( $current_url ) );
			exit;
		}

		// Admin-only consent (see class docblock). The client and redirect_uri
		// are validated above, so this goes back to the waiting agent as an
		// OAuth error rather than a browser-only 403 that leaves it hanging
		// until it times out.
		if ( ! Config::has_admin_access() ) {
			self::redirect_with_error(
				$params['redirect_uri'],
				'access_denied',
				'Only administrators may authorize agent access to this site.',
				$params['state']
			);
		}

		self::render_authorize_page( $client, $params );
		exit;
	}

	/**
	 * Hand an error back to the client by redirect (RFC 6749 §4.1.2.1).
	 *
	 * Only safe once client_id and redirect_uri have been validated against the
	 * registered list — before that there is nowhere trustworthy to send the
	 * user and the caller must return a WP_Error instead. Everything past that
	 * point should come back here, so a failure reaches the agent waiting on
	 * the callback instead of only the browser.
	 *
	 * @param string $redirect_uri Validated redirect URI.
	 * @param string $error        OAuth error code.
	 * @param string $description  Human-readable description for the client.
	 * @param string $state        Client state to echo back; '' when absent.
	 */
	private static function redirect_with_error( string $redirect_uri, string $error, string $description, string $state = '' ): void {
		// Note: add_query_arg() URL-encodes values itself — do NOT pre-encode
		// (e.g. rawurlencode) or state/description arrive double-encoded and
		// strict clients reject the mismatched state.
		$args = array(
			'error'             => $error,
			'error_description' => $description,
		);
		if ( '' !== $state ) {
			$args['state'] = $state;
		}

		// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- External client redirect_uri, validated against the registered list by the caller.
		wp_redirect( add_query_arg( $args, $redirect_uri ) );
		exit;
	}

	/**
	 * POST /authorize — process the consent form (approve or deny).
	 *
	 * @param WP_REST_Request $request The incoming REST request.
	 * @return WP_Error|void
	 */
	public static function handle_post( WP_REST_Request $request ) {
		self::restore_user_from_cookie();

		$nonce = sanitize_text_field( (string) ( $request->get_param( self::NONCE_FIELD ) ?? '' ) );
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return new WP_Error( 'invalid_nonce', 'Security check failed.', array( 'status' => 403 ) );
		}

		$action                = sanitize_text_field( (string) ( $request->get_param( 'action' ) ?? '' ) );
		$client_id             = sanitize_text_field( (string) ( $request->get_param( 'client_id' ) ?? '' ) );
		$redirect_uri          = esc_url_raw( (string) ( $request->get_param( 'redirect_uri' ) ?? '' ) );
		$scope                 = sanitize_text_field( (string) ( $request->get_param( 'scope' ) ?? '' ) );
		$state                 = sanitize_text_field( (string) ( $request->get_param( 'state' ) ?? '' ) );
		$code_challenge        = sanitize_text_field( (string) ( $request->get_param( 'code_challenge' ) ?? '' ) );
		$code_challenge_method = sanitize_text_field( (string) ( $request->get_param( 'code_challenge_method' ) ?? '' ) );

		// Re-validate client and redirect_uri (defense in depth). This runs
		// before the access checks below so that when one of them fails there
		// is already a trusted redirect_uri to report the failure to.
		$client = Db::get_client_by_id( $client_id );
		if ( null === $client || ! in_array( $redirect_uri, $client['redirect_uris'], true ) ) {
			return new WP_Error( 'invalid_client', 'Invalid client or redirect URI.', array( 'status' => 400 ) );
		}

		// Session expired between rendering the consent page and submitting it.
		if ( ! is_user_logged_in() ) {
			self::redirect_with_error(
				$redirect_uri,
				'access_denied',
				'The WordPress session expired before authorization completed.',
				$state
			);
		}

		if ( ! Config::has_admin_access() ) {
			self::redirect_with_error(
				$redirect_uri,
				'access_denied',
				'Only administrators may authorize agent access to this site.',
				$state
			);
		}

		// User denied authorization.
		if ( 'authorize' !== $action ) {
			self::redirect_with_error(
				$redirect_uri,
				'access_denied',
				'User denied the authorization request.',
				$state
			);
		}

		$code = Db::insert_code(
			array(
				'client_id'             => $client_id,
				'user_id'               => get_current_user_id(),
				'redirect_uri'          => $redirect_uri,
				'scope'                 => $scope,
				'code_challenge'        => $code_challenge,
				'code_challenge_method' => $code_challenge_method,
			)
		);

		if ( false === $code ) {
			self::redirect_with_error(
				$redirect_uri,
				'server_error',
				'Could not generate authorization code.',
				$state
			);
		}

		// add_query_arg() URL-encodes values itself; pass raw so state round-trips
		// back to the client byte-for-byte.
		$success_args = array( 'code' => $code );
		if ( '' !== $state ) {
			$success_args['state'] = $state;
		}
		$success_url = add_query_arg( $success_args, $redirect_uri );
		// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- External client redirect_uri validated against the registered list above.
		wp_redirect( $success_url );
		exit;
	}

	/**
	 * Restore the current user from the logged_in cookie.
	 *
	 * The REST API intentionally clears cookie auth when there is no valid
	 * wp_rest nonce (CSRF protection). The authorize flow is a browser
	 * redirect, so there is no nonce on the initial GET. We validate the
	 * logged_in cookie directly via wp_validate_auth_cookie() and restore the
	 * user. This does NOT create or log in users — it only restores an
	 * already-authenticated session for the consent page.
	 */
	private static function restore_user_from_cookie(): void {
		if ( is_user_logged_in() ) {
			return;
		}

		$user_id = wp_validate_auth_cookie( '', 'logged_in' );
		if ( false !== $user_id ) {
			wp_set_current_user( $user_id );
		}
	}

	/**
	 * Extract and sanitize authorization parameters from the request.
	 *
	 * @param WP_REST_Request $request The incoming REST request.
	 * @return array<string,string>
	 */
	private static function extract_params( WP_REST_Request $request ): array {
		return array(
			'response_type'         => sanitize_text_field( (string) ( $request->get_param( 'response_type' ) ?? '' ) ),
			'client_id'             => sanitize_text_field( (string) ( $request->get_param( 'client_id' ) ?? '' ) ),
			'redirect_uri'          => esc_url_raw( (string) ( $request->get_param( 'redirect_uri' ) ?? '' ) ),
			'scope'                 => sanitize_text_field( (string) ( $request->get_param( 'scope' ) ?? 'mcp:tools' ) ),
			'state'                 => sanitize_text_field( (string) ( $request->get_param( 'state' ) ?? '' ) ),
			'code_challenge'        => sanitize_text_field( (string) ( $request->get_param( 'code_challenge' ) ?? '' ) ),
			'code_challenge_method' => sanitize_text_field( (string) ( $request->get_param( 'code_challenge_method' ) ?? '' ) ),
		);
	}

	/**
	 * Render the consent page HTML and styles.
	 *
	 * @param array<string,mixed>  $client The OAuth client row.
	 * @param array<string,string> $params The authorization request parameters.
	 */
	private static function render_authorize_page( array $client, array $params ): void {
		$current_user = wp_get_current_user();
		$site_name    = get_bloginfo( 'name' );
		$site_icon    = get_site_icon_url( 64 );
		$nonce        = wp_create_nonce( self::NONCE_ACTION );
		$form_action  = rest_url( Server::REST_NAMESPACE . '/authorize' );

		$scope_labels = array(
			'mcp:tools' => __( 'Use MCP tools and abilities on your site', 'agent-connector-for-wp' ),
			'mcp:read'  => __( 'Read content and data from your site', 'agent-connector-for-wp' ),
			'mcp:write' => __( 'Create and modify content on your site', 'agent-connector-for-wp' ),
		);

		$requested_scopes = array_map( 'trim', explode( ' ', $params['scope'] ) );

		Server::send_cors_headers();
		status_header( 200 );
		header( 'Content-Type: text/html; charset=utf-8' );

		// Anti-clickjacking. This page is rendered by the REST API, so it gets
		// none of the framing protection core puts on wp-admin/wp-login. A
		// framed consent screen would let an attacker who registered their own
		// client (DCR is public) overlay the Authorize button and trick a
		// logged-in admin into granting a token that fronts shell/PHP-eval
		// abilities. The nonce below is no defense: it is rendered inside the
		// framed page and submits with it.
		header( 'X-Frame-Options: DENY' );
		header( "Content-Security-Policy: frame-ancestors 'none'" );

		// The URL carries state and code_challenge; keep them out of the
		// Referer sent to any off-site asset (e.g. a CDN-hosted site icon).
		header( 'Referrer-Policy: no-referrer' );

		?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<title>
		<?php
		/* translators: %s: client application name */
		echo esc_html( sprintf( __( 'Authorize %s', 'agent-connector-for-wp' ), (string) $client['client_name'] ) . ' — ' . $site_name );
		?>
	</title>
		<?php
		wp_register_style(
			'acfw-oauth-authorize',
			plugins_url( 'assets/css/oauth-authorize.css', AGENT_CONNECTOR_FOR_WP_FILE ),
			array(),
			AGENT_CONNECTOR_FOR_WP_VERSION
		);
		wp_print_styles( 'acfw-oauth-authorize' );
		?>
</head>
<body>
	<div class="acfw-oauth-card">
		<div class="acfw-oauth-header">
			<?php if ( $site_icon ) : ?>
				<img src="<?php echo esc_url( $site_icon ); ?>" alt="<?php echo esc_attr( $site_name ); ?>" />
			<?php endif; ?>
			<h1><?php echo esc_html( $site_name ); ?></h1>
			<p>
				<?php
				printf(
					/* translators: %s: client application name */
					esc_html__( 'The application %s is requesting agent access to your site.', 'agent-connector-for-wp' ),
					'<strong>' . esc_html( (string) $client['client_name'] ) . '</strong>'
				);
				?>
			</p>
		</div>

		<div class="acfw-oauth-warning">
			<?php esc_html_e( 'Warning: abilities exposed through Agent Connector can include shell access, PHP evaluation, and filesystem operations. Only authorize applications you trust.', 'agent-connector-for-wp' ); ?>
		</div>

		<div class="acfw-oauth-scopes">
			<h3><?php esc_html_e( 'Requested permissions', 'agent-connector-for-wp' ); ?></h3>
			<ul>
				<?php foreach ( $requested_scopes as $requested_scope ) : ?>
					<li><?php echo esc_html( $scope_labels[ $requested_scope ] ?? $requested_scope ); ?></li>
				<?php endforeach; ?>
			</ul>
		</div>

		<p class="acfw-oauth-user">
			<?php
			printf(
				/* translators: %s: current user display name */
				esc_html__( 'Logged in as %s', 'agent-connector-for-wp' ),
				'<strong>' . esc_html( $current_user->display_name ) . '</strong>'
			);
			?>
		</p>

		<form method="post" action="<?php echo esc_url( $form_action ); ?>">
			<input type="hidden" name="<?php echo esc_attr( self::NONCE_FIELD ); ?>" value="<?php echo esc_attr( $nonce ); ?>" />
			<input type="hidden" name="client_id" value="<?php echo esc_attr( $params['client_id'] ); ?>" />
			<input type="hidden" name="redirect_uri" value="<?php echo esc_url( $params['redirect_uri'] ); ?>" />
			<input type="hidden" name="scope" value="<?php echo esc_attr( $params['scope'] ); ?>" />
			<input type="hidden" name="state" value="<?php echo esc_attr( $params['state'] ); ?>" />
			<input type="hidden" name="code_challenge" value="<?php echo esc_attr( $params['code_challenge'] ); ?>" />
			<input type="hidden" name="code_challenge_method" value="<?php echo esc_attr( $params['code_challenge_method'] ); ?>" />

			<div class="acfw-oauth-actions">
				<button type="submit" name="action" value="deny" class="acfw-oauth-btn-deny">
					<?php esc_html_e( 'Deny', 'agent-connector-for-wp' ); ?>
				</button>
				<button type="submit" name="action" value="authorize" class="acfw-oauth-btn-authorize">
					<?php esc_html_e( 'Authorize', 'agent-connector-for-wp' ); ?>
				</button>
			</div>
		</form>
	</div>
</body>
</html>
		<?php
	}
}
