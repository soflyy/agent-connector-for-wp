<?php
/**
 * Plugin Name: Migration Mode Switch (MU)
 * Description: Per-request plugin-activation switch for builder migrations. Filters the active-plugin list before plugins load, so one URL can be rendered as if only a chosen "profile" of plugins were active. Managed by the "Migration Mode Abilities" plugin. Delete this file to fully disable.
 *
 * ACM-MU-VERSION: 1.0.0
 *
 * Why this is an mu-plugin: WordPress loads the active-plugin list in
 * wp_get_active_and_valid_plugins() (wp-settings.php), which reads
 * get_option('active_plugins') through the `option_active_plugins` filter
 * BEFORE any normal plugin is included. Only code that runs earlier than that
 * — i.e. an mu-plugin — can rewrite the list for the current request. A normal
 * plugin is already too late to filter its own request.
 *
 * @package MigrationModeAbilities
 */

namespace MigrationMode\Switcher;

defined( 'ABSPATH' ) || exit;

const VERSION = '1.0.0';

// Shared option keys (written by the Migration Mode Abilities plugin's abilities).
const OPT_ENABLED   = 'acm_migration_enabled';
const OPT_SECRET    = 'acm_migration_secret';
const OPT_PROFILES  = 'acm_migration_profiles';
const OPT_MANAGED   = 'acm_migration_managed';
const OPT_PROTECTED = 'acm_migration_protected';

// Per-request signal carriers. Checked in order: query string, header, cookie.
const PARAM_PROFILE  = 'acm_profile';
const PARAM_TOKEN    = 'acm_token';
const HEADER_PROFILE = 'HTTP_X_ACM_PROFILE';
const HEADER_TOKEN   = 'HTTP_X_ACM_TOKEN';
const COOKIE_PROFILE = 'acm_profile';
const COOKIE_TOKEN   = 'acm_token';

/**
 * Holds the computed active-plugin list for this request. `option_active_plugins`
 * can fire many times; we compute once and reuse, and we expose it for the debug
 * header.
 */
final class State {
	/** @var string[]|null */
	public static $active = null;
	/** @var string */
	public static $profile = '';
}

/**
 * Read a signal value from query string, then header, then cookie.
 */
function request_value( string $param, string $header, string $cookie ): string {
	if ( isset( $_GET[ $param ] ) && '' !== $_GET[ $param ] ) {
		return (string) wp_unslash( $_GET[ $param ] );
	}
	if ( isset( $_SERVER[ $header ] ) && '' !== $_SERVER[ $header ] ) {
		return (string) $_SERVER[ $header ];
	}
	if ( isset( $_COOKIE[ $cookie ] ) && '' !== $_COOKIE[ $cookie ] ) {
		return (string) $_COOKIE[ $cookie ];
	}
	return '';
}

/**
 * Make the browsing session sticky to a profile so the page's asset/AJAX
 * sub-requests (which won't carry the query string) inherit it too. Passing an
 * empty profile clears the cookies.
 */
function set_sticky_cookie( string $profile, string $token ): void {
	if ( headers_sent() ) {
		return;
	}
	$path = defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/';
	if ( '' === $profile ) {
		setcookie( COOKIE_PROFILE, '', time() - 3600, $path );
		setcookie( COOKIE_TOKEN, '', time() - 3600, $path );
		return;
	}
	// Session cookies (expire = 0). HttpOnly off so a driver could read them if needed.
	setcookie( COOKIE_PROFILE, $profile, 0, $path, '', false, false );
	setcookie( COOKIE_TOKEN, $token, 0, $path, '', false, false );
}

/**
 * Compute the active-plugin list for a profile from a real active list.
 *
 * Rules:
 *  - protected plugins are always kept (Agent Connector, this tool, etc.);
 *  - "managed" plugins (the union of every plugin named across all profiles, plus
 *    any managed_extra) are arbitrated: kept only if listed in this profile;
 *  - every other (unmanaged) plugin passes through untouched.
 *
 * @param string[] $plugins      Real active list.
 * @param string[] $managed      Plugins arbitrated by profiles.
 * @param string[] $protected    Always-on plugins.
 * @param string[] $keep_managed Managed plugins this profile keeps active.
 * @return string[]
 */
function compute( array $plugins, array $managed, array $protected, array $keep_managed ): array {
	$out = array();
	foreach ( $plugins as $p ) {
		if ( in_array( $p, $protected, true ) ) {
			$out[] = $p;
			continue;
		}
		if ( in_array( $p, $managed, true ) ) {
			if ( in_array( $p, $keep_managed, true ) ) {
				$out[] = $p;
			}
			continue;
		}
		$out[] = $p; // unmanaged — leave as the operator set it.
	}
	return array_values( array_unique( $out ) );
}

function boot(): void {
	// Never filter admin, login, or cron requests. This guarantees you can never
	// lock yourself out of wp-admin, and background jobs run with the real stack.
	$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
	if ( false !== stripos( $uri, '/wp-admin/' ) || false !== stripos( $uri, 'wp-login.php' ) ) {
		return;
	}
	if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
		return;
	}

	if ( ! get_option( OPT_ENABLED, false ) ) {
		return;
	}

	$profile_id = request_value( PARAM_PROFILE, HEADER_PROFILE, COOKIE_PROFILE );
	$token      = request_value( PARAM_TOKEN, HEADER_TOKEN, COOKIE_TOKEN );
	if ( '' === $profile_id ) {
		return; // No signal — behave like a completely normal request.
	}

	// Authentication gate. Without a valid token the switch is inert, so a random
	// visitor cannot disable your security/membership plugins. hash_equals is
	// constant-time. The token is a low-privilege preview secret, distinct from
	// any WP credential.
	$secret = (string) get_option( OPT_SECRET, '' );
	if ( '' === $secret || ! hash_equals( $secret, $token ) ) {
		return;
	}

	// Explicit "back to normal" — clear stickiness and run the full stack.
	if ( 'off' === $profile_id || 'default' === $profile_id ) {
		set_sticky_cookie( '', '' );
		return;
	}

	$profiles = get_option( OPT_PROFILES, array() );
	if ( ! is_array( $profiles ) || ! isset( $profiles[ $profile_id ] ) ) {
		return;
	}
	$profile      = $profiles[ $profile_id ];
	$keep_managed = ( isset( $profile['active'] ) && is_array( $profile['active'] ) )
		? array_values( $profile['active'] )
		: array();

	$managed   = (array) get_option( OPT_MANAGED, array() );
	$protected = (array) get_option( OPT_PROTECTED, array() );

	State::$profile = $profile_id;
	set_sticky_cookie( $profile_id, $secret );

	// THE CORE MECHANISM: rewrite the active-plugin list for this request only.
	add_filter(
		'option_active_plugins',
		static function ( $plugins ) use ( $managed, $protected, $keep_managed ) {
			if ( ! is_array( $plugins ) ) {
				return $plugins;
			}
			if ( null === State::$active ) {
				State::$active = compute( $plugins, $managed, $protected, $keep_managed );
			}
			return State::$active;
		},
		1
	);

	// Multisite: network-active plugins are a keyed map (file => activation_ts).
	add_filter(
		'site_option_active_sitewide_plugins',
		static function ( $plugins ) use ( $managed, $protected, $keep_managed ) {
			if ( ! is_array( $plugins ) ) {
				return $plugins;
			}
			foreach ( array_keys( $plugins ) as $file ) {
				if ( in_array( $file, $protected, true ) ) {
					continue;
				}
				if ( in_array( $file, $managed, true ) && ! in_array( $file, $keep_managed, true ) ) {
					unset( $plugins[ $file ] );
				}
			}
			return $plugins;
		},
		1
	);

	// Keep the preview honest: don't let WP 301 away our query var before the
	// page renders. We only reach here for an authenticated preview request.
	add_filter( 'redirect_canonical', '__return_false' );

	// Debug aid for the agent / curl: echo the computed set as a header.
	if ( isset( $_GET['acm_debug'] ) ) {
		add_action(
			'send_headers',
			static function () {
				if ( is_array( State::$active ) ) {
					header( 'X-ACM-Profile: ' . State::$profile );
					header( 'X-ACM-Active-Plugins: ' . implode( ',', State::$active ) );
				}
			}
		);
	}
}

boot();
