<?php
/**
 * Installs / refreshes the must-use plugin that performs the per-request switch.
 *
 * The switch logic MUST live in mu-plugins to run before the active-plugin list
 * loads. We ship it as a self-contained file (no dependency on this plugin's
 * autoloader) and copy it into WPMU_PLUGIN_DIR, re-copying when the bundled
 * version is newer than the installed one.
 *
 * @package MigrationModeAbilities
 */

declare( strict_types=1 );

namespace MigrationMode\Abilities;

defined( 'ABSPATH' ) || exit;

final class MuInstaller {

	private const FILENAME = 'migration-mode-switch.php';

	public static function source_path(): string {
		return ACM_DIR . 'mu/' . self::FILENAME;
	}

	public static function target_dir(): string {
		return defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
	}

	public static function target_path(): string {
		return self::target_dir() . '/' . self::FILENAME;
	}

	public static function is_installed(): bool {
		return is_file( self::target_path() );
	}

	public static function installed_version(): string {
		return self::is_installed() ? self::version_in( (string) @file_get_contents( self::target_path() ) ) : '';
	}

	public static function bundled_version(): string {
		return self::version_in( (string) @file_get_contents( self::source_path() ) );
	}

	private static function version_in( string $src ): string {
		return preg_match( '/ACM-MU-VERSION:\s*([0-9.]+)/', $src, $m ) ? $m[1] : '0';
	}

	/**
	 * Ensure the mu-plugin is present and up to date. Idempotent and cheap.
	 *
	 * @return array{installed:bool,action:string,message:string}
	 */
	public static function ensure(): array {
		$dir = self::target_dir();
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return array(
				'installed' => false,
				'action'    => 'error',
				'message'   => "Could not create mu-plugins directory: {$dir}",
			);
		}

		$installed = self::installed_version();
		$bundled   = self::bundled_version();

		if ( '' !== $installed && version_compare( $installed, $bundled, '>=' ) ) {
			return array(
				'installed' => true,
				'action'    => 'noop',
				'message'   => "mu-plugin already current (v{$installed}).",
			);
		}

		$src = (string) @file_get_contents( self::source_path() );
		if ( '' === $src ) {
			return array(
				'installed' => self::is_installed(),
				'action'    => 'error',
				'message'   => 'Bundled mu-plugin source is missing or unreadable.',
			);
		}

		$ok = (bool) @file_put_contents( self::target_path(), $src );

		return array(
			'installed' => $ok,
			'action'    => $ok ? ( '' === $installed ? 'installed' : 'updated' ) : 'error',
			'message'   => $ok
				? sprintf( 'mu-plugin %s at %s (v%s).', '' === $installed ? 'installed' : 'updated', self::target_path(), $bundled )
				: 'Failed to write the mu-plugin file (permissions?).',
		);
	}
}
