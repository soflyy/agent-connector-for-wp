<?php
/**
 * Shared configuration: the option keys read by the mu-plugin and written by the
 * abilities, plus helpers to read/normalize profiles and the installed-plugin
 * list.
 *
 * @package MigrationModeAbilities
 */

declare( strict_types=1 );

namespace MigrationMode\Abilities;

defined( 'ABSPATH' ) || exit;

final class Config {

	// Must match the constants in mu/migration-mode-switch.php.
	public const OPT_ENABLED   = 'acm_migration_enabled';
	public const OPT_SECRET    = 'acm_migration_secret';
	public const OPT_PROFILES  = 'acm_migration_profiles';
	public const OPT_MANAGED   = 'acm_migration_managed';
	public const OPT_PROTECTED = 'acm_migration_protected';

	/**
	 * Plugins that must never be disabled by a profile, so that MCP and this tool
	 * keep working under any profile. Includes Agent Connector, this plugin, and
	 * the default-abilities pack when present.
	 *
	 * @return string[]
	 */
	public static function default_protected(): array {
		return array(
			'agent-connector-for-wp/agent-connector-for-wp.php',
			ACM_BASENAME,
			'universal-abilities-plugin/default-abilities-plugin.php',
		);
	}

	public static function seed_defaults(): void {
		if ( '' === (string) get_option( self::OPT_SECRET, '' ) ) {
			update_option( self::OPT_SECRET, self::generate_secret(), false );
		}
		add_option( self::OPT_ENABLED, true, '', false );
		add_option( self::OPT_PROFILES, array(), '', false );
		add_option( self::OPT_MANAGED, array(), '', false );
		// Only seed protected if unset, so operator edits survive reactivation.
		if ( false === get_option( self::OPT_PROTECTED, false ) ) {
			update_option( self::OPT_PROTECTED, self::default_protected(), false );
		}
	}

	public static function generate_secret(): string {
		// URL-safe, no special chars (query-string + cookie friendly).
		return wp_generate_password( 40, false, false );
	}

	public static function secret(): string {
		return (string) get_option( self::OPT_SECRET, '' );
	}

	public static function enabled(): bool {
		return (bool) get_option( self::OPT_ENABLED, false );
	}

	/**
	 * @return array<string,array{label:string,active:string[]}>
	 */
	public static function profiles(): array {
		$p = get_option( self::OPT_PROFILES, array() );
		return is_array( $p ) ? $p : array();
	}

	/** @return string[] */
	public static function managed(): array {
		$m = get_option( self::OPT_MANAGED, array() );
		return is_array( $m ) ? array_values( $m ) : array();
	}

	/** @return string[] */
	public static function protected_plugins(): array {
		$p = get_option( self::OPT_PROTECTED, array() );
		return is_array( $p ) ? array_values( $p ) : self::default_protected();
	}

	/**
	 * All installed plugins keyed by file, with active state. Loads the admin
	 * plugin helpers if needed (abilities can run in a REST context).
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function installed_plugins(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all            = get_plugins();
		$active         = (array) get_option( 'active_plugins', array() );
		$network_active = is_multisite() ? array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) : array();

		$out = array();
		foreach ( $all as $file => $data ) {
			$out[ $file ] = array(
				'file'           => $file,
				'name'           => isset( $data['Name'] ) ? (string) $data['Name'] : $file,
				'version'        => isset( $data['Version'] ) ? (string) $data['Version'] : '',
				'active'         => in_array( $file, $active, true ),
				'network_active' => in_array( $file, $network_active, true ),
			);
		}
		return $out;
	}
}
