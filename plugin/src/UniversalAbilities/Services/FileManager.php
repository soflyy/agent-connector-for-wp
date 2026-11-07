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
use AgentConnectorForWp\Support\Sandbox;

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
	 * @param string $encoding 'utf8' (default) treats $contents as text;
	 *                         'base64' decodes first (binary-safe writes).
	 * @return array{path:string,bytes_written:int,created:bool}|\WP_Error
	 */
	public function write( string $path, string $contents, string $encoding = 'utf8', bool $append = false ) {
		$resolved = Helpers::resolve_path( $path );
		if ( '' === $resolved ) {
			return new \WP_Error( 'agent_connector_for_wp_bad_path', 'No path provided.' );
		}

		// PHP-execution boundary: .php files may only be written inside the
		// sandbox directory, so generated code that runs each request has a
		// single, recoverable home, and execution-control files (.htaccess,
		// .user.ini, php.ini, web.config) can't be written at all. Non-PHP
		// writes elsewhere stay allowed by design.
		$sandbox_check = Sandbox::check_php_execution_boundary( $resolved );
		if ( is_wp_error( $sandbox_check ) ) {
			return $sandbox_check;
		}

		// Never write *through* a symlinked final path.
		$symlink_check = Sandbox::reject_final_symlink( $resolved );
		if ( is_wp_error( $symlink_check ) ) {
			return $symlink_check;
		}

		$payload = 'base64' === $encoding ? base64_decode( $contents, true ) : $contents;
		if ( false === $payload ) {
			return new \WP_Error( 'agent_connector_for_wp_bad_base64', 'contents was not valid base64.' );
		}

		$existed = is_file( $resolved );

		// The sandbox is inside the web root, so its PHP must stop when requested
		// directly; judge the file as it will be after this write.
		if ( Sandbox::path_requires_sandbox( $resolved ) ) {
			// Sandbox PHP runs on every request, so writing it is editing the
			// site's code: refused where DISALLOW_FILE_EDIT or DISALLOW_FILE_MODS
			// turn off the plugin editor, as WordPress does for edit_plugins.
			if ( ! current_user_can( 'edit_plugins' ) ) {
				return new \WP_Error(
					'agent_connector_for_wp_php_edits_disabled',
					'PHP files cannot be written on this site: code changes from inside WordPress are turned off (DISALLOW_FILE_EDIT or DISALLOW_FILE_MODS).',
					array( 'status' => 403 )
				);
			}
			$existing = $append && $existed ? (string) file_get_contents( $resolved ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions
			$guard    = Sandbox::check_direct_access_guard( $existing . $payload );
			if ( is_wp_error( $guard ) ) {
				return $guard;
			}
			Sandbox::ensure_dir();
		}

		$dir = dirname( $resolved );
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return new \WP_Error( 'agent_connector_for_wp_mkdir_failed', sprintf( 'Could not create directory: %s', $dir ) );
		}

		$flags   = LOCK_EX | ( $append ? FILE_APPEND : 0 );
		$written = file_put_contents( $resolved, $payload, $flags ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $written ) {
			return new \WP_Error( 'agent_connector_for_wp_write_failed', sprintf( 'Failed to write: %s', $path ) );
		}

		return array(
			'path'          => $resolved,
			'bytes_written' => (int) $written,
			'created'       => ! $existed,
		);
	}

	/**
	 * @return array{path:string,deleted:bool}|\WP_Error
	 */
	public function delete( string $path, bool $recursive = false ) {
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
