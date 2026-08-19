<?php
/**
 * Runtime configuration and the optional protection gates.
 *
 * @package AgentConnectorForWp
 */

declare( strict_types=1 );

namespace AgentConnectorForWp\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Centralizes the plugin's toggles and tunables.
 *
 * The plugin is ON by default: activating it runs the MCP server. Instead of
 * blocking the operator up front, we surface a site-wide admin warning (see
 * Admin\ProductionNotice) whenever it runs on a production environment, and
 * offer opt-in protections — "Block on production environments" and the domain
 * lock — under Settings → Protection for operators who want hard gates.
 *
 * The one non-configurable gate is authentication: abilities only execute for
 * super admins (see {@see has_admin_access()}, enforced by Support\Governance).
 */
final class Config {

	public const CAP = 'manage_options';

	/**
	 * Option storing the Settings-screen enable toggle (boolean). Default ON —
	 * activating the plugin is the opt-in.
	 */
	public const ENABLED_OPTION = 'agent_connector_for_wp_enabled';

	/**
	 * Option for the opt-in "Block on production environments" protection
	 * (boolean, default off). When on, the plugin refuses to boot while
	 * wp_get_environment_type() reports 'production'.
	 */
	public const BLOCK_PRODUCTION_OPTION = 'agent_connector_for_wp_block_production';

	/**
	 * Option for the opt-in "Enable domain lock" protection (boolean, default
	 * off). The host is pinned regardless (at activation / reconnect); this
	 * toggle decides whether a mismatch actually blocks abilities.
	 */
	public const DOMAIN_LOCK_OPTION = 'agent_connector_for_wp_domain_lock_enabled';

	/**
	 * Option hiding the site-wide production warning notice (boolean, default
	 * off = notice shows). Site-wide: one admin dismissing it hides it for all.
	 */
	public const HIDE_PRODUCTION_WARNING_OPTION = 'agent_connector_for_wp_hide_production_warning';

	/**
	 * Option hiding the "install Universal Abilities" nudge notice (boolean,
	 * default off = notice shows while the pack is missing). Site-wide.
	 */
	public const HIDE_UAP_NOTICE_OPTION = 'agent_connector_for_wp_hide_uap_notice';

	/**
	 * Legacy option from the old opt-out model ("allow use on production").
	 * Production use is now allowed by default, so this is deleted on
	 * activation and never read.
	 */
	public const LEGACY_PRODUCTION_OVERRIDE_OPTION = 'agent_connector_for_wp_allow_production';

	/**
	 * Option toggling the debug log of MCP traffic (boolean). When on, every MCP
	 * event — including raw JSON-RPC request/response bodies — is written to the
	 * `{prefix}acfw_mcp_events` table and surfaced on the "MCP Events" admin
	 * page. Default OFF: bodies can contain sensitive data, so logging is a
	 * deliberate opt-in.
	 */
	public const MCP_DEBUG_OPTION = 'agent_connector_for_wp_mcp_debug';

	/**
	 * Option storing the host the plugin was last activated / reconnected on.
	 *
	 * This is the domain lock's pin: when the lock is enabled, abilities refuse
	 * to run if the site's declared home host no longer matches this value.
	 * Empty means "never pinned".
	 */
	public const LOCKED_HOST_OPTION = 'agent_connector_for_wp_locked_host';

	/**
	 * Option gating the OAuth 2.1 authorization server (boolean, default OFF).
	 *
	 * Temporary rollout flag while the OAuth path settles. Off means the server
	 * never boots: no discovery documents, no client registration, no consent
	 * screen, and no Bearer interceptor, so tokens already issued stop
	 * authenticating. The application-password path is untouched either way.
	 */
	public const OAUTH_ENABLED_OPTION = 'agent_connector_for_wp_oauth_enabled';

	/**
	 * The Enable toggle. Default ON: installing and activating the plugin is
	 * the operator's opt-in, so it works out of the box.
	 */
	public static function is_enabled(): bool {
		return (bool) get_option( self::ENABLED_OPTION, true );
	}

	/**
	 * Whether this site reports a non-production environment type.
	 *
	 * wp_get_environment_type() is one of 'local', 'development', 'staging', or
	 * 'production', defaulting to 'production' when WP_ENVIRONMENT_TYPE is unset
	 * — so a site that never configured its environment type is treated as
	 * production (and gets the production warning notice).
	 */
	public static function is_non_production_env(): bool {
		if ( ! function_exists( 'wp_get_environment_type' ) ) {
			return false; // Can't prove it's non-production → treat as production.
		}
		return 'production' !== wp_get_environment_type();
	}

	/**
	 * Whether the OAuth authorization server may boot. Default OFF: OAuth is
	 * opt-in for now (see OAUTH_ENABLED_OPTION).
	 */
	public static function is_oauth_enabled(): bool {
		return (bool) get_option( self::OAUTH_ENABLED_OPTION, false );
	}

	/**
	 * HTTPS, or a local environment — the transport predicate core uses to gate
	 * Application Passwords ({@see wp_is_application_passwords_supported()} on
	 * WP 6.7+; the same check inlined on older WP). Reimplemented here (rather
	 * than calling the core function, which doesn't exist pre-6.7) so we can
	 * tell "transport too weak" apart from "a plugin filtered availability
	 * off" when Application Passwords are unavailable.
	 */
	public static function is_secure_transport(): bool {
		return is_ssl()
			|| ( function_exists( 'wp_get_environment_type' ) && 'local' === wp_get_environment_type() );
	}

	/**
	 * Whether the transport is fit for the OAuth server: HTTPS, or a local
	 * environment.
	 *
	 * Same predicate core uses to gate Application Passwords: over plain HTTP
	 * on a non-local site, authorization codes and Bearer tokens — which
	 * front root-equivalent abilities — would cross the wire in cleartext on
	 * every MCP call. Without this gate OAuth would be the plugin's only
	 * credential path that still works where core already refuses to mint
	 * app passwords.
	 */
	public static function oauth_transport_allowed(): bool {
		$allowed = self::is_secure_transport();

		/**
		 * Filters whether the OAuth server may run on this transport.
		 *
		 * The same escape hatch core offers for Application Passwords
		 * (wp_is_application_passwords_available): a site terminating TLS at a
		 * proxy in a way is_ssl() can't see can force this on.
		 *
		 * @param bool $allowed True when the site is HTTPS or a local environment.
		 */
		return (bool) apply_filters( 'agent_connector_for_wp_oauth_transport_allowed', $allowed );
	}

	/**
	 * The opt-in "Block on production environments" protection.
	 */
	public static function block_production_enabled(): bool {
		return (bool) get_option( self::BLOCK_PRODUCTION_OPTION, false );
	}

	/**
	 * Master boot decision: enabled, and not held back by the (opt-in)
	 * production protection.
	 */
	public static function can_boot(): bool {
		return self::is_enabled() && ! self::is_blocked_by_production();
	}

	/**
	 * Enabled, but held back because the operator opted into "Block on
	 * production environments" and this is a production environment.
	 */
	public static function is_blocked_by_production(): bool {
		return self::is_enabled() && ! self::is_non_production_env() && self::block_production_enabled();
	}

	/**
	 * Whether the site-wide production warning notice should render: the plugin
	 * is running on a production environment and no one has dismissed the
	 * warning (or checked "Disable production warning" in Settings).
	 */
	public static function should_show_production_warning(): bool {
		return self::can_boot() && ! self::is_non_production_env() && ! self::production_warning_hidden();
	}

	/**
	 * Whether the production warning notice has been hidden (site-wide).
	 */
	public static function production_warning_hidden(): bool {
		return (bool) get_option( self::HIDE_PRODUCTION_WARNING_OPTION, false );
	}

	/**
	 * Whether the "install Universal Abilities" nudge has been dismissed
	 * (site-wide).
	 */
	public static function uap_notice_hidden(): bool {
		return (bool) get_option( self::HIDE_UAP_NOTICE_OPTION, false );
	}

	/**
	 * Whether the operator opted in to MCP event logging (the Debug setting).
	 * Default OFF — see MCP_DEBUG_OPTION.
	 */
	public static function mcp_debug_enabled(): bool {
		return (bool) get_option( self::MCP_DEBUG_OPTION, false );
	}

	/**
	 * The site's *declared* home URL, read straight from the options table.
	 *
	 * We deliberately bypass get_option()/home_url(), because the
	 * _config_wp_home filter substitutes the WP_HOME constant when it is defined
	 * — and dev environments (e.g. the Docker sandbox this plugin targets) define
	 * WP_HOME dynamically from $_SERVER['HTTP_HOST'] per request, so home_url()
	 * returns whatever host the caller used. That makes it useless as an identity
	 * for the domain lock: the agent (http://wordpress) and the operator's
	 * browser (http://localhost:PORT) would see different values for one install.
	 * The stored option is the single stable identity, set once at install.
	 */
	public static function declared_home_url(): string {
		global $wpdb;

		$value = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", 'home' )
		);

		if ( ! is_string( $value ) || '' === $value ) {
			// Fallback for unusual setups (e.g. constant-defined options table).
			$value = (string) get_option( 'home' );
		}

		return (string) $value;
	}

	/**
	 * Host component of the declared home URL (no scheme, no port-stripping).
	 */
	public static function declared_host(): string {
		$host = wp_parse_url( self::declared_home_url(), PHP_URL_HOST );
		return is_string( $host ) ? $host : '';
	}

	/**
	 * The opt-in "Enable domain lock" protection. The pin is always maintained;
	 * this decides whether a mismatch blocks abilities.
	 */
	public static function domain_lock_enabled(): bool {
		return (bool) get_option( self::DOMAIN_LOCK_OPTION, false );
	}

	/**
	 * The host the domain lock is pinned to, or '' if never pinned.
	 */
	public static function locked_host(): string {
		return (string) get_option( self::LOCKED_HOST_OPTION, '' );
	}

	/**
	 * Pin (or re-pin) the domain lock to the site's current declared host.
	 *
	 * Called on plugin activation and when the operator clicks "Reconnect".
	 */
	public static function lock_to_current_host(): void {
		update_option( self::LOCKED_HOST_OPTION, self::declared_host(), false );
	}

	/**
	 * Whether the domain lock is satisfied. True when the lock is disabled, no
	 * host was ever pinned, or the pinned host still matches the declared host.
	 */
	public static function domain_lock_ok(): bool {
		return null === self::lock_mismatch_reason();
	}

	/**
	 * Agent-actionable explanation of a domain-lock mismatch, or null when the
	 * lock is disabled, unpinned, or satisfied.
	 *
	 * The message is written for the agent calling an ability over MCP: it tells
	 * the agent exactly which human action unblocks it.
	 */
	public static function lock_mismatch_reason(): ?string {
		if ( ! self::domain_lock_enabled() ) {
			return null;
		}

		$locked   = self::locked_host();
		$declared = self::declared_host();

		if ( '' === $locked || $locked === $declared ) {
			return null;
		}

		return sprintf(
			'Agent Connector for WP is domain-locked. It was locked to "%1$s" but this site now reports its home as "%2$s", so all abilities are blocked. An administrator must open wp-admin → Agent Connector → Settings → Protection and click "Reconnect to this domain" to re-enable abilities here.',
			$locked,
			'' === $declared ? '(unknown)' : $declared
		);
	}

	/**
	 * Default command/eval timeout in milliseconds. Override with the
	 * AGENT_CONNECTOR_FOR_WP_TIMEOUT_MS constant.
	 */
	public static function default_timeout_ms(): int {
		if ( defined( 'AGENT_CONNECTOR_FOR_WP_TIMEOUT_MS' ) && is_int( AGENT_CONNECTOR_FOR_WP_TIMEOUT_MS ) ) {
			return max( 1000, AGENT_CONNECTOR_FOR_WP_TIMEOUT_MS );
		}
		return 60000;
	}

	/**
	 * Maximum bytes captured per output stream before truncation. Override with
	 * the AGENT_CONNECTOR_FOR_WP_MAX_OUTPUT_BYTES constant.
	 */
	public static function max_output_bytes(): int {
		if ( defined( 'AGENT_CONNECTOR_FOR_WP_MAX_OUTPUT_BYTES' ) && is_int( AGENT_CONNECTOR_FOR_WP_MAX_OUTPUT_BYTES ) ) {
			return max( 4096, AGENT_CONNECTOR_FOR_WP_MAX_OUTPUT_BYTES );
		}
		return 2 * 1024 * 1024; // 2 MiB.
	}

	/**
	 * Abilities should only execute for administrators/super admins. This gate
	 * is not configurable — it is the plugin's authentication boundary, forced
	 * onto every MCP-exposed ability by Support\Governance.
	 */
	public static function has_admin_access(): bool {
		return current_user_can( self::CAP ) && function_exists( 'is_super_admin' ) && is_super_admin();
	}

	/**
	 * Absolute path to the audit log file, or '' when audit logging is off.
	 *
	 * Defining the AGENT_CONNECTOR_FOR_WP_AUDIT_LOG constant IS the opt-in: there
	 * is no default path and no setting. Undefined (the default) means nothing is
	 * ever written — the log records user logins, client IPs, and input
	 * summaries, so it only exists when an operator deliberately configured a
	 * destination (ideally outside the web root).
	 */
	public static function audit_log_path(): string {
		if ( defined( 'AGENT_CONNECTOR_FOR_WP_AUDIT_LOG' ) && is_string( AGENT_CONNECTOR_FOR_WP_AUDIT_LOG ) ) {
			return trim( AGENT_CONNECTOR_FOR_WP_AUDIT_LOG );
		}
		return '';
	}

	/**
	 * One-time setup on plugin activation: switch the plugin on, pin the domain
	 * lock to the current host (only if never pinned — an explicit Reconnect is
	 * the way to re-pin after a domain change), and drop the legacy
	 * production-override option from the old opt-out model.
	 */
	public static function activate(): void {
		update_option( self::ENABLED_OPTION, true, true );

		if ( '' === self::locked_host() ) {
			self::lock_to_current_host();
		}

		delete_option( self::LEGACY_PRODUCTION_OVERRIDE_OPTION );
	}
}
