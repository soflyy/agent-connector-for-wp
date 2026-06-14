<?php
/**
 * Environment gates and runtime configuration.
 *
 * @package AgentConnectorForWp
 */

declare( strict_types=1 );

namespace AgentConnectorForWp\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Centralizes the mandatory opt-in gates and tunables.
 *
 * Nothing initializes unless the operator flicked the Enable toggle on the
 * Settings screen. As a second safety gate, if the site reports a production
 * environment type, enabling alone is not enough — the operator must also tick
 * an explicit production-override box. Off means off until a human says yes.
 */
final class Config {

	public const CAP = 'manage_options';

	/**
	 * Option storing the Settings-screen enable toggle (boolean).
	 */
	public const ENABLED_OPTION = 'agent_connector_for_wp_enabled';

	/**
	 * Option storing the explicit "run on production anyway" override (boolean).
	 *
	 * Only consulted when the site reports a production environment type; on
	 * non-production environments the Enable toggle alone is sufficient.
	 */
	public const PRODUCTION_OVERRIDE_OPTION = 'agent_connector_for_wp_allow_production';

	/**
	 * Option toggling the debug log of MCP traffic (boolean). When on, every MCP
	 * event — including raw JSON-RPC request/response bodies — is written to the
	 * `{prefix}acfw_mcp_events` table and surfaced on the "MCP Events" admin
	 * page. Default OFF: bodies can contain sensitive data, so logging is a
	 * deliberate opt-in.
	 */
	public const MCP_DEBUG_OPTION = 'agent_connector_for_wp_mcp_debug';

	/**
	 * Option storing the host the plugin was last enabled / reconnected on.
	 *
	 * This is the domain lock: abilities refuse to run if the site's declared
	 * home host no longer matches this value. Empty means "never locked", in
	 * which case the lock is inert.
	 */
	public const LOCKED_HOST_OPTION = 'agent_connector_for_wp_locked_host';

	/**
	 * The Enable toggle (box 1). This is the operator's intent to turn the
	 * plugin on; whether it actually boots also depends on the environment gate.
	 */
	public static function is_enabled(): bool {
		return (bool) get_option( self::ENABLED_OPTION, false );
	}

	/**
	 * Whether this site reports a non-production environment type.
	 *
	 * wp_get_environment_type() is one of 'local', 'development', 'staging', or
	 * 'production', defaulting to 'production' when WP_ENVIRONMENT_TYPE is unset.
	 * Anything other than 'production' is treated as safe to enable without the
	 * extra override — and a site that never configured its environment type is
	 * therefore (correctly) treated as production.
	 */
	public static function is_non_production_env(): bool {
		if ( ! function_exists( 'wp_get_environment_type' ) ) {
			return false; // Can't prove it's non-production → require the override.
		}
		return 'production' !== wp_get_environment_type();
	}

	/**
	 * The production-override toggle (box 2). Only meaningful on production.
	 */
	public static function production_override_enabled(): bool {
		return (bool) get_option( self::PRODUCTION_OVERRIDE_OPTION, false );
	}

	/**
	 * Master boot decision. Two gates:
	 *   1. The operator enabled the plugin (box 1), AND
	 *   2. the environment is non-production, OR the operator explicitly ticked
	 *      the production override (box 2).
	 */
	public static function can_boot(): bool {
		if ( ! self::is_enabled() ) {
			return false;
		}
		if ( self::is_non_production_env() ) {
			return true;
		}
		return self::production_override_enabled();
	}

	/**
	 * Enabled (box 1) but held back by the production gate (no override).
	 */
	public static function is_blocked_by_production(): bool {
		return self::is_enabled() && ! self::is_non_production_env() && ! self::production_override_enabled();
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
	 * The host the domain lock is pinned to, or '' if never locked.
	 */
	public static function locked_host(): string {
		return (string) get_option( self::LOCKED_HOST_OPTION, '' );
	}

	/**
	 * Pin (or re-pin) the domain lock to the site's current declared host.
	 *
	 * Called whenever the operator enables the plugin or clicks "Reconnect".
	 */
	public static function lock_to_current_host(): void {
		update_option( self::LOCKED_HOST_OPTION, self::declared_host(), false );
	}

	/**
	 * Whether the domain lock is satisfied. True when no lock is set, or when the
	 * pinned host still matches the declared host.
	 */
	public static function domain_lock_ok(): bool {
		return null === self::lock_mismatch_reason();
	}

	/**
	 * Agent-actionable explanation of a domain-lock mismatch, or null when the
	 * lock is satisfied (or inert).
	 *
	 * The message is written for the agent calling an ability over MCP: it tells
	 * the agent exactly which human action unblocks it.
	 */
	public static function lock_mismatch_reason(): ?string {
		$locked   = self::locked_host();
		$declared = self::declared_host();

		if ( '' === $locked || $locked === $declared ) {
			return null;
		}

		return sprintf(
			'Agent Connector for WP is domain-locked. It was enabled on "%1$s" but this site now reports its home as "%2$s", so all abilities are blocked. An administrator must open wp-admin → Agent Connector for WP → Settings and click "Reconnect to this domain" to re-enable abilities here.',
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
	 * Abilities should only execute for administrators/super admins.
	 */
	public static function has_admin_access(): bool {
		return current_user_can( self::CAP ) && function_exists( 'is_super_admin' ) && is_super_admin();
	}

	/**
	 * Absolute path to the audit log. Override with AGENT_CONNECTOR_FOR_WP_AUDIT_LOG.
	 */
	public static function audit_log_path(): string {
		if ( defined( 'AGENT_CONNECTOR_FOR_WP_AUDIT_LOG' ) && is_string( AGENT_CONNECTOR_FOR_WP_AUDIT_LOG ) ) {
			return AGENT_CONNECTOR_FOR_WP_AUDIT_LOG;
		}
		return rtrim( WP_CONTENT_DIR, '/\\' ) . '/agent-connector-for-wp-audit.log';
	}

	/**
	 * Admin notice shown when the plugin is installed but not currently active,
	 * pointing the operator at the always-reachable Settings screen.
	 */
	public static function render_gate_notice(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}

		$settings_url = admin_url( 'admin.php?page=agent-connector-for-wp' );

		if ( self::is_blocked_by_production() ) {
			$message = sprintf(
				'It is enabled, but this is a <code>production</code> environment, so it stays inactive until you confirm the production override on the <a href="%s">Settings screen</a>.',
				esc_url( $settings_url )
			);
		} else {
			$message = sprintf(
				'Enable it on the <a href="%s">Settings screen</a>.',
				esc_url( $settings_url )
			);
		}

		printf(
			'<div class="notice notice-warning"><p><strong>Agent Connector for WP</strong> is installed but inactive. %s</p></div>',
			wp_kses(
				$message,
				array(
					'a'    => array( 'href' => array() ),
					'code' => array(),
				)
			)
		);
	}
}
