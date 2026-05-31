<?php
/**
 * Shell command execution via proc_open().
 *
 * @package AgentConnectorForWp
 */

declare( strict_types=1 );

namespace AgentConnectorForWp\Services;

use AgentConnectorForWp\Support\Config;
use AgentConnectorForWp\Support\Helpers;

defined( 'ABSPATH' ) || exit;

/**
 * Runs a command through the system shell, capturing stdout/stderr/exit code
 * with a wall-clock timeout and per-stream output cap. No command filtering —
 * the agent is trusted to have shell-equivalent access.
 */
final class ShellExecutor {

	/**
	 * @param string   $command    The command line to execute.
	 * @param ?string  $cwd        Working directory (resolved if relative).
	 * @param ?int     $timeout_ms Override the default timeout.
	 *
	 * @return array{stdout:string,stderr:string,exit_code:int,duration_ms:int,timed_out:bool,truncated:bool,stdout_encoding:string,stderr_encoding:string}|\WP_Error
	 */
	public function run( string $command, ?string $cwd = null, ?int $timeout_ms = null ) {
		if ( ! function_exists( 'proc_open' ) ) {
			return new \WP_Error( 'agent_connector_for_wp_proc_open_unavailable', 'proc_open() is disabled on this server.' );
		}
		if ( '' === trim( $command ) ) {
			return new \WP_Error( 'agent_connector_for_wp_empty_command', 'No command provided.' );
		}

		$timeout_ms = null === $timeout_ms ? Config::default_timeout_ms() : max( 1000, $timeout_ms );
		$max_bytes  = Config::max_output_bytes();
		$cwd        = ( null !== $cwd && '' !== trim( $cwd ) ) ? Helpers::resolve_path( $cwd ) : null;

		$descriptors = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);

		$start   = microtime( true );
		$process = @proc_open( $command, $descriptors, $pipes, $cwd ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! is_resource( $process ) ) {
			return new \WP_Error( 'agent_connector_for_wp_proc_open_failed', 'Failed to start the process.' );
		}

		fclose( $pipes[0] );
		stream_set_blocking( $pipes[1], false );
		stream_set_blocking( $pipes[2], false );

		$stdout    = '';
		$stderr    = '';
		$truncated = false;
		$timed_out = false;
		$exit_code = null;
		$deadline  = $start + ( $timeout_ms / 1000 );

		while ( true ) {
			$status = proc_get_status( $process );

			$read   = array( $pipes[1], $pipes[2] );
			$write  = null;
			$except = null;
			// Short select so we can re-check the timeout and process status.
			if ( @stream_select( $read, $write, $except, 0, 200000 ) > 0 ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
				foreach ( $read as $stream ) {
					$chunk = fread( $stream, 8192 );
					if ( false === $chunk || '' === $chunk ) {
						continue;
					}
					if ( $stream === $pipes[1] ) {
						if ( strlen( $stdout ) < $max_bytes ) {
							$stdout .= $chunk;
						} else {
							$truncated = true;
						}
					} elseif ( strlen( $stderr ) < $max_bytes ) {
							$stderr .= $chunk;
					} else {
						$truncated = true;
					}
				}
			}

			if ( ! $status['running'] ) {
				// Capture the exit code now: proc_get_status() reports the real
				// exitcode only on the first call after termination, and a later
				// proc_close() would return -1.
				$exit_code = (int) $status['exitcode'];
				break;
			}

			if ( microtime( true ) >= $deadline ) {
				$timed_out = true;
				// Terminate the whole process group where supported, else the pid.
				if ( function_exists( 'proc_terminate' ) ) {
					proc_terminate( $process, 9 );
				}
				break;
			}
		}

		// Drain anything left in the pipes after exit/termination.
		foreach ( array( 1 => &$stdout, 2 => &$stderr ) as $i => &$buf ) {
			while ( ! feof( $pipes[ $i ] ) ) {
				$chunk = fread( $pipes[ $i ], 8192 );
				if ( false === $chunk || '' === $chunk ) {
					break;
				}
				if ( strlen( $buf ) < $max_bytes ) {
					$buf .= $chunk;
				} else {
					$truncated = true;
					break;
				}
			}
			fclose( $pipes[ $i ] );
		}
		unset( $buf );

		proc_close( $process ); // Clean up; its return is unreliable after proc_get_status().
		if ( $timed_out ) {
			$exit_code = 124; // Conventional timeout exit status.
		} elseif ( null === $exit_code ) {
			$exit_code = -1;
		}

		list( $stdout, $st_trunc ) = Helpers::truncate_bytes( $stdout, $max_bytes );
		list( $stderr, $se_trunc ) = Helpers::truncate_bytes( $stderr, $max_bytes );
		$truncated                 = $truncated || $st_trunc || $se_trunc;

		$out = Helpers::encode_output( $stdout );
		$err = Helpers::encode_output( $stderr );

		return array(
			'stdout'          => $out['data'],
			'stdout_encoding' => $out['encoding'],
			'stderr'          => $err['data'],
			'stderr_encoding' => $err['encoding'],
			'exit_code'       => (int) $exit_code,
			'duration_ms'     => (int) round( ( microtime( true ) - $start ) * 1000 ),
			'timed_out'       => $timed_out,
			'truncated'       => $truncated,
		);
	}
}
