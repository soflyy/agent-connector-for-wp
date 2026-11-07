<?php
/**
 * Filesystem read / write / delete / list.
 *
 * @package AgentConnectorForWp
 */

declare( strict_types=1 );

namespace AgentConnectorForWp\UniversalAbilities\Services;

use AgentConnectorForWp\Support\Config;
use AgentConnectorForWp\Support\Helpers;

defined( 'ABSPATH' ) || exit;

/**
 * Direct, unrestricted filesystem access. Binary-safe via base64 on both read
 * and write. Paths are resolved relative to ABSPATH when not absolute.
 */
final class FileManager {

	/**
	 * @return array{path:string,encoding:string,contents:string,size:int,truncated:bool}|\WP_Error
	 */
	public function read( string $path, string $encoding = 'auto' ) {
		$resolved = Helpers::resolve_path( $path );
		if ( '' === $resolved || ! is_file( $resolved ) ) {
			return new \WP_Error( 'agent_connector_for_wp_not_found', sprintf( 'File not found: %s', $path ) );
		}
		if ( ! is_readable( $resolved ) ) {
			return new \WP_Error( 'agent_connector_for_wp_unreadable', sprintf( 'File not readable: %s', $path ) );
		}

		$max   = Config::max_output_bytes();
		$bytes = file_get_contents( $resolved, false, null, 0, $max + 1 ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $bytes ) {
			return new \WP_Error( 'agent_connector_for_wp_read_failed', sprintf( 'Failed to read: %s', $path ) );
		}

		list( $bytes, $truncated ) = Helpers::truncate_bytes( $bytes, $max );

		if ( 'base64' === $encoding ) {
			$out = array(
				'encoding' => 'base64',
				'data'     => base64_encode( $bytes ),
			);
		} else {
			$out = Helpers::encode_output( $bytes );
		}

		return array(
			'path'      => $resolved,
			'encoding'  => $out['encoding'],
			'contents'  => $out['data'],
			'size'      => (int) ( @filesize( $resolved ) ?: strlen( $bytes ) ), // phpcs:ignore WordPress.PHP.NoSilencedErrors
			'truncated' => $truncated,
		);
	}

	/**
	 * @return array{path:string,deleted:bool}|\WP_Error
	 */
	public function delete( string $path, bool $recursive = false ) {
		$mods = self::check_file_mods_allowed( 'agent_connector_for_wp_file_delete' );
		if ( is_wp_error( $mods ) ) {
			return $mods;
		}

		$resolved = Helpers::resolve_path( $path );
		if ( '' === $resolved || ! file_exists( $resolved ) ) {
			return new \WP_Error( 'agent_connector_for_wp_not_found', sprintf( 'Path not found: %s', $path ) );
		}

		if ( is_dir( $resolved ) ) {
			if ( ! self::filesystem()->rmdir( $resolved, $recursive ) ) {
				return new \WP_Error( 'agent_connector_for_wp_delete_failed', sprintf( 'Failed to delete directory (use recursive for non-empty): %s', $path ) );
			}
		} else {
			wp_delete_file( $resolved );
			if ( file_exists( $resolved ) ) {
				return new \WP_Error( 'agent_connector_for_wp_delete_failed', sprintf( 'Failed to delete: %s', $path ) );
			}
		}

		return array(
			'path'    => $resolved,
			'deleted' => true,
		);
	}

	/**
	 * @return array{path:string,entries:array<int,array<string,mixed>>}|\WP_Error
	 */
	public function list( string $path, bool $recursive = false, int $max_depth = 5 ) {
		$resolved = Helpers::resolve_path( $path );
		if ( '' === $resolved || ! is_dir( $resolved ) ) {
			return new \WP_Error( 'agent_connector_for_wp_not_dir', sprintf( 'Not a directory: %s', $path ) );
		}

		$entries = array();
		$this->scan( $resolved, $recursive, max( 1, $max_depth ), 0, $entries );

		return array(
			'path'    => $resolved,
			'entries' => $entries,
		);
	}

	/**
	 * @param array<int,array<string,mixed>> $entries Accumulator (by reference).
	 */
	private function scan( string $dir, bool $recursive, int $max_depth, int $depth, array &$entries ): void {
		$items = @scandir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( false === $items ) {
			return;
		}
		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$full   = $dir . '/' . $item;
			$is_dir = is_dir( $full );
			$entries[] = array(
				'path'     => $full,
				'name'     => $item,
				'type'     => $is_dir ? 'dir' : 'file',
				'size'     => $is_dir ? 0 : (int) ( @filesize( $full ) ?: 0 ), // phpcs:ignore WordPress.PHP.NoSilencedErrors
				'modified' => (int) ( @filemtime( $full ) ?: 0 ), // phpcs:ignore WordPress.PHP.NoSilencedErrors
				'perms'    => substr( sprintf( '%o', @fileperms( $full ) ?: 0 ), -4 ), // phpcs:ignore WordPress.PHP.NoSilencedErrors
			);
			if ( $is_dir && $recursive && $depth + 1 < $max_depth ) {
				$this->scan( $full, $recursive, $max_depth, $depth + 1, $entries );
			}
		}
	}

	/**
	 * Writing and deleting files is refused under DISALLOW_FILE_MODS, the
	 * site owner's switch for "no file changes from inside WordPress". Uses
	 * core's wp_is_file_mod_allowed(), so the file_mod_allowed filter can
	 * still make an exception for these contexts.
	 *
	 * @param string $context Context passed to the file_mod_allowed filter.
	 * @return true|\WP_Error
	 */
	public static function check_file_mods_allowed( string $context ) {
		if ( wp_is_file_mod_allowed( $context ) ) {
			return true;
		}
		return new \WP_Error(
			'agent_connector_for_wp_file_mods_disabled',
			'Files cannot be written or deleted on this site: file changes from inside WordPress are turned off (DISALLOW_FILE_MODS). Reading and listing files still work.',
			array( 'status' => 403 )
		);
	}

	/**
	 * WordPress's direct filesystem API. Agent Connector works on the local
	 * filesystem as the web server user, so the direct transport is the right
	 * one regardless of the site's FS_METHOD (which governs updates).
	 */
	private static function filesystem(): \WP_Filesystem_Direct {
		static $filesystem = null;
		if ( null === $filesystem ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
			$filesystem = new \WP_Filesystem_Direct( null );
		}
		return $filesystem;
	}
}
