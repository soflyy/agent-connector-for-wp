<?php
/**
 * WP-CLI command execution.
 *
 * @package AgentConnectorForWp
 */

declare( strict_types=1 );

namespace AgentConnectorForWp\DefaultAbilities\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Runs a WP-CLI command and captures its result.
 *
 * This is a thin convenience layer over ShellExecutor: it resolves the `wp`
 * binary, runs the command from the WordPress root so WP-CLI finds the install,
 * and transparently adds --allow-root when the PHP process is running as root
 * (WP-CLI refuses to run as root otherwise). The command string is passed
 * through verbatim — like shell-exec, the agent is trusted to have
 * shell-equivalent access, so there is intentionally no argument escaping or
 * command filtering.
 */
final class WpCliRunner {

	/**
	 * @param string  $command    The WP-CLI command, i.e. everything after `wp`
	 *                            (e.g. "plugin list --status=active --format=json").
	 * @param ?string $cwd        Working directory. Defaults to ABSPATH so WP-CLI
	 *                            resolves the current install. Relative paths
	 *                            resolve against ABSPATH.
	 * @param ?int    $timeout_ms Override the default timeout.
	 *
	 * @return array{stdout:string,stderr:string,exit_code:int,duration_ms:int,timed_out:bool,truncated:bool,stdout_encoding:string,stderr_encoding:string}|\WP_Error
	 */
	public function run( string $command, ?string $cwd = null, ?int $timeout_ms = null ) {
		$command = trim( $command );
		if ( '' === $command ) {
			return new \WP_Error( 'agent_connector_for_wp_empty_wp_cli', 'No WP-CLI command provided.' );
		}

		$binary = $this->resolve_binary();
		if ( null === $binary ) {
			return new \WP_Error(
				'agent_connector_for_wp_wp_cli_unavailable',
				'WP-CLI (the `wp` binary) was not found on this server. Install WP-CLI or use the shell-exec ability with an absolute path.'
			);
		}

		// Tolerate a leading "wp " if the agent included it; we add the binary.
		if ( 0 === stripos( $command, 'wp ' ) ) {
			$command = ltrim( substr( $command, 3 ) );
		}

		$full = $binary . ' ' . $command;

		// WP-CLI aborts when run as root unless --allow-root is set. Add it only
		// when actually root and the caller hasn't already specified it.
		if ( $this->is_root() && false === strpos( $command, '--allow-root' ) ) {
			$full .= ' --allow-root';
		}

		// Default to ABSPATH so `wp` finds the WordPress install.
		$cwd = ( null !== $cwd && '' !== trim( $cwd ) ) ? $cwd : ABSPATH;

		return ( new ShellExecutor() )->run( $full, $cwd, $timeout_ms );
	}

	/**
	 * Locate the `wp` executable: prefer PATH, then common install locations.
	 */
	private function resolve_binary(): ?string {
		if ( function_exists( 'shell_exec' ) ) {
			$out = @shell_exec( 'command -v wp 2>/dev/null' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.PHP.DiscouragedPHPFunctions
			if ( is_string( $out ) && '' !== trim( $out ) ) {
				return trim( (string) strtok( $out, "\n" ) );
			}
		}

		foreach ( array( '/usr/local/bin/wp', '/usr/bin/wp', '/bin/wp' ) as $candidate ) {
			if ( is_executable( $candidate ) ) {
				return $candidate;
			}
		}

		return null;
	}

	/**
	 * Whether the PHP process is running with effective UID 0.
	 */
	private function is_root(): bool {
		return function_exists( 'posix_geteuid' ) && 0 === posix_geteuid();
	}
}
