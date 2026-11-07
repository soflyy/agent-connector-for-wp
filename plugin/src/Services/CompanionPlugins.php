<?php
/**
 * Lookups for the plugins Agent Connector works alongside.
 *
 * @package AgentConnectorForWp
 */

declare( strict_types=1 );

namespace AgentConnectorForWp\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Finds the plugins Agent Connector works alongside on this site: the MCP
 * Adapter plugin it requires, and the old standalone Universal Abilities plugin
 * (now built in — see UniversalAbilities\LegacyPlugin).
 */
final class CompanionPlugins {

	/**
	 * The old standalone Universal Abilities plugin's folder slug.
	 */
	public const UNIVERSAL_ABILITIES_SLUG = 'universal-abilities-plugin';

	/**
	 * The canonical MCP Adapter plugin's folder slug, as declared in this
	 * plugin's `Requires Plugins` header and as wordpress.org installs it.
	 */
	public const MCP_ADAPTER_SLUG = 'mcp-adapter';

	/**
	 * Resolve the old Universal Abilities plugin's installed file via the
	 * tolerant slug matcher (so it's found regardless of its main-file name), or
	 * null when it isn't installed.
	 */
	public static function universal_abilities_file(): ?string {
		return self::installed_file_for_slug( self::UNIVERSAL_ABILITIES_SLUG );
	}

	/**
	 * Whether the old Universal Abilities plugin is installed AND active.
	 */
	public static function is_universal_abilities_active(): bool {
		$file = self::universal_abilities_file();

		return null !== $file && self::is_active( $file );
	}

	/**
	 * Resolve the MCP Adapter plugin's installed file via the tolerant slug
	 * matcher, or null when it isn't installed.
	 */
	public static function mcp_adapter_file(): ?string {
		return self::installed_file_for_slug( self::MCP_ADAPTER_SLUG );
	}

	/**
	 * All installed plugins, keyed by plugin file (e.g. "woocommerce/woocommerce.php").
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function installed_plugins(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return (array) get_plugins();
	}

	/**
	 * Resolve a plugin slug to its installed plugin file, or null when it isn't
	 * installed.
	 */
	private static function installed_file_for_slug( string $slug ): ?string {
		return self::find_installed_key( $slug, self::installed_plugins() );
	}

	/**
	 * Resolve a slug to an installed plugin file, tolerating slug format
	 * differences (full "folder/file.php" path vs bare folder slug).
	 *
	 * @param string                            $slug      Plugin file or folder slug.
	 * @param array<string,array<string,mixed>> $installed get_plugins() output.
	 *
	 * @return string|null The matching plugin file key, or null when not installed.
	 */
	private static function find_installed_key( string $slug, array $installed ): ?string {
		$slug = trim( $slug );
		if ( '' === $slug ) {
			return null;
		}

		// 1. Exact "folder/file.php" match.
		if ( isset( $installed[ $slug ] ) ) {
			return $slug;
		}

		// 2. Treat the supplied value as a folder slug and match on dirname,
		//    or on the file stem for single-file plugins ("hello.php").
		$needle = self::folder_slug( $slug );
		foreach ( array_keys( $installed ) as $file ) {
			if ( self::folder_slug( $file ) === $needle ) {
				return $file;
			}
		}

		return null;
	}

	/**
	 * Reduce a plugin file or slug to its comparable folder slug.
	 *
	 * "woocommerce/woocommerce.php" → "woocommerce"
	 * "woocommerce"                 → "woocommerce"
	 * "hello.php"                   → "hello"
	 */
	private static function folder_slug( string $value ): string {
		$value = trim( $value );
		if ( false !== strpos( $value, '/' ) ) {
			return dirname( $value );
		}

		// Single-file plugin or bare slug: strip a trailing ".php".
		return preg_replace( '/\.php$/', '', $value ) ?? $value;
	}

	/**
	 * Whether a plugin file is active (site-wide or, on multisite, network-active).
	 */
	private static function is_active( string $plugin_file ): bool {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return is_plugin_active( $plugin_file );
	}
}
