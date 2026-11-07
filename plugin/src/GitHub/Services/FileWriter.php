<?php
/**
 * file-write: writes files for agents, with PHP confined to the sandbox.
 * GitHub build only.
 *
 * @package AgentConnectorForWp
 */

declare( strict_types=1 );

namespace AgentConnectorForWp\GitHub\Services;

use AgentConnectorForWp\Support\Helpers;
use AgentConnectorForWp\UniversalAbilities\Services\FileManager;

defined( 'ABSPATH' ) || exit;

final class FileWriter {

	/**
	 * @param string $encoding 'utf8' (default) treats $contents as text;
	 *                         'base64' decodes first (binary-safe writes).
	 * @return array{path:string,bytes_written:int,created:bool}|\WP_Error
	 */
	public function write( string $path, string $contents, string $encoding = 'utf8', bool $append = false ) {
		$mods = FileManager::check_file_mods_allowed( 'agent_connector_for_wp_file_write' );
		if ( is_wp_error( $mods ) ) {
			return $mods;
		}

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
}
