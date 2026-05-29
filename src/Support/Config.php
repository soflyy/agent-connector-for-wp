<?php
/**
 * Environment gates and runtime configuration.
 *
 * @package RootForAgents
 */

declare( strict_types=1 );

namespace RootForAgents\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Centralizes the mandatory opt-in gates and tunables.
 *
 * The plugin is dangerous by design, so nothing initializes unless the
 * operator has explicitly defined the enabling constants (typically in
 * wp-config.php).
 */
final class Config {

	/**
	 * Master switch. The plugin does nothing unless this is true.
	 */
	public static function is_enabled(): bool {
		return defined( 'ROOT_FOR_AGENTS_ENABLED' ) && true === ROOT_FOR_AGENTS_ENABLED;
	}

	/**
	 * Production is blocked unless explicitly overridden.
	 */
	public static function allow_production(): bool {
		return defined( 'ROOT_FOR_AGENTS_ALLOW_PRODUCTION' ) && true === ROOT_FOR_AGENTS_ALLOW_PRODUCTION;
	}

	public static function is_production(): bool {
		return function_exists( 'wp_get_environment_type' ) && 'production' === wp_get_environment_type();
	}

	/**
	 * Master boot decision: enabled, not blocked by the production guard, and
	 * the Abilities API is actually present to register against.
	 */
	public static function can_boot(): bool {
		if ( ! self::is_enabled() ) {
			return false;
		}
		if ( self::is_production() && ! self::allow_production() ) {
			return false;
		}
		return true;
	}

	/**
	 * Default command/eval timeout in milliseconds. Override with the
	 * ROOT_FOR_AGENTS_TIMEOUT_MS constant.
	 */
	public static function default_timeout_ms(): int {
		if ( defined( 'ROOT_FOR_AGENTS_TIMEOUT_MS' ) && is_int( ROOT_FOR_AGENTS_TIMEOUT_MS ) ) {
			return max( 1000, ROOT_FOR_AGENTS_TIMEOUT_MS );
		}
		return 60000;
	}

	/**
	 * Maximum bytes captured per output stream before truncation. Override with
	 * the ROOT_FOR_AGENTS_MAX_OUTPUT_BYTES constant.
	 */
	public static function max_output_bytes(): int {
		if ( defined( 'ROOT_FOR_AGENTS_MAX_OUTPUT_BYTES' ) && is_int( ROOT_FOR_AGENTS_MAX_OUTPUT_BYTES ) ) {
			return max( 4096, ROOT_FOR_AGENTS_MAX_OUTPUT_BYTES );
		}

		/**
		 * Abilities should only execute for authenticated users.
		 */
		public static function has_authenticated_user(): bool {
			return function_exists( 'is_user_logged_in' ) && is_user_logged_in();
		}
		return 2 * 1024 * 1024; // 2 MiB.
	}

	/**
	 * Absolute path to the audit log. Override with ROOT_FOR_AGENTS_AUDIT_LOG.
	 */
	public static function audit_log_path(): string {
		if ( defined( 'ROOT_FOR_AGENTS_AUDIT_LOG' ) && is_string( ROOT_FOR_AGENTS_AUDIT_LOG ) ) {
			return ROOT_FOR_AGENTS_AUDIT_LOG;
		}
		return rtrim( WP_CONTENT_DIR, '/\\' ) . '/root-for-agents-audit.log';
	}

	/**
	 * Admin notice explaining which gate kept the plugin inert.
	 */
	public static function render_gate_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! self::is_enabled() ) {
			$reason = "Define <code>ROOT_FOR_AGENTS_ENABLED</code> as <code>true</code> in wp-config.php to enable it.";
		} elseif ( self::is_production() && ! self::allow_production() ) {
			$reason = "The environment type is <code>production</code>. Set a non-production <code>WP_ENVIRONMENT_TYPE</code>, or define <code>ROOT_FOR_AGENTS_ALLOW_PRODUCTION</code> as <code>true</code> to override (not recommended).";
		} else {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p><strong>Root for Agents</strong> is installed but inactive. %s</p></div>',
			wp_kses( $reason, array( 'code' => array() ) )
		);
	}
}
