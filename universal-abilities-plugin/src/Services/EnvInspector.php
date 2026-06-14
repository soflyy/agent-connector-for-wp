<?php
/**
 * Structured environment metadata for agents.
 *
 * @package AgentConnectorForWp
 */

declare( strict_types=1 );

namespace AgentConnectorForWp\DefaultAbilities\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Gathers the kind of orientation data an agent wants before acting: versions,
 * paths, active plugins/theme, debug flags, and which CLI tools are available.
 */
final class EnvInspector {

	/**
	 * @return array<string,mixed>
	 */
	public function inspect(): array {
		global $wp_version;

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$uploads = wp_get_upload_dir();
		$theme   = wp_get_theme();

		return array(
			'wordpress_version' => (string) $wp_version,
			'php_version'       => PHP_VERSION,
			'environment_type'  => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'unknown',
			'wp_debug'          => defined( 'WP_DEBUG' ) && WP_DEBUG,
			'is_multisite'      => is_multisite(),
			'abspath'           => ABSPATH,
			'wp_content_dir'    => WP_CONTENT_DIR,
			'uploads_dir'       => isset( $uploads['basedir'] ) ? (string) $uploads['basedir'] : '',
			'home_url'          => home_url(),
			'site_url'          => site_url(),
			'active_theme'      => array(
				'name'    => (string) $theme->get( 'Name' ),
				'version' => (string) $theme->get( 'Version' ),
				'stylesheet' => (string) $theme->get_stylesheet(),
			),
			'active_plugins'    => $this->active_plugins(),
			'tools'             => $this->tools(),
			'os'                => array(
				'php_os'   => PHP_OS,
				'uname'    => function_exists( 'php_uname' ) ? php_uname() : '',
				'sapi'     => PHP_SAPI,
				'user'     => $this->process_user(),
			),
			'permissions'       => array(
				'abspath_writable'     => is_writable( ABSPATH ),
				'wp_content_writable'  => is_writable( WP_CONTENT_DIR ),
				'uploads_writable'     => isset( $uploads['basedir'] ) && is_writable( $uploads['basedir'] ),
			),
		);
	}

	/**
	 * @return array<int,string>
	 */
	private function active_plugins(): array {
		$active = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}
		sort( $active );
		return array_values( array_unique( array_map( 'strval', $active ) ) );
	}

	/**
	 * Probe for common CLI tooling. Returns version strings or null.
	 *
	 * @return array<string,?string>
	 */
	private function tools(): array {
		return array(
			'wp_cli'   => $this->probe( 'wp --version --allow-root 2>/dev/null' ),
			'node'     => $this->probe( 'node --version 2>/dev/null' ),
			'npm'      => $this->probe( 'npm --version 2>/dev/null' ),
			'composer' => $this->probe( 'composer --version 2>/dev/null' ),
			'git'      => $this->probe( 'git --version 2>/dev/null' ),
			'php_cli'  => $this->probe( 'php --version 2>/dev/null' ),
		);
	}

	/**
	 * Returns the name of the effective process user (runtime user, not script-file owner).
	 */
	private function process_user(): string {
		if ( function_exists( 'posix_geteuid' ) && function_exists( 'posix_getpwuid' ) ) {
			$info = posix_getpwuid( posix_geteuid() );
			if ( is_array( $info ) && isset( $info['name'] ) ) {
				return (string) $info['name'];
			}
		}
		// Fallback: ask the shell.
		if ( function_exists( 'shell_exec' ) ) {
			$out = @shell_exec( 'id -un 2>/dev/null' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.PHP.DiscouragedPHPFunctions
			if ( is_string( $out ) && '' !== trim( $out ) ) {
				return trim( $out );
			}
		}
		return '';
	}

	private function probe( string $command ): ?string {
		if ( ! function_exists( 'shell_exec' ) ) {
			return null;
		}
		$out = @shell_exec( $command ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.PHP.DiscouragedPHPFunctions
		if ( ! is_string( $out ) || '' === trim( $out ) ) {
			return null;
		}
		// First non-empty line is enough.
		return trim( strtok( $out, "\n" ) );
	}
}
