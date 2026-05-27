<?php
/**
 * Append-only audit logging for every ability invocation.
 *
 * @package RootForAgents
 */

declare( strict_types=1 );

namespace RootForAgents\Services;

use RootForAgents\Support\Config;
use RootForAgents\Support\Helpers;

defined( 'ABSPATH' ) || exit;

/**
 * Writes one JSON line per invocation. This is an audit trail, not a security
 * control — the plugin is high-trust by design — but it makes after-the-fact
 * review of what an agent did possible.
 */
final class AuditLogger {

	/**
	 * Record an ability invocation.
	 *
	 * @param string               $ability  Ability name, e.g. root-for-agents/shell-exec.
	 * @param array<string,mixed>  $summary  Non-sensitive summary of the input.
	 * @param string               $status   'ok' or 'error'.
	 * @param int                  $duration Milliseconds.
	 */
	public static function log( string $ability, array $summary, string $status, int $duration = 0 ): void {
		$user = function_exists( 'wp_get_current_user' ) ? wp_get_current_user() : null;

		$entry = array(
			'ts'          => gmdate( 'c' ),
			'ability'     => $ability,
			'status'      => $status,
			'duration_ms' => $duration,
			'user_id'     => $user ? (int) $user->ID : 0,
			'user_login'  => $user && $user->exists() ? (string) $user->user_login : '',
			'ip'          => Helpers::client_ip(),
			'summary'     => $summary,
		);

		$line = wp_json_encode( $entry );
		if ( false === $line ) {
			return;
		}

		$path = Config::audit_log_path();
		// Suppress errors: logging must never break the operation it records.
		@file_put_contents( $path, $line . "\n", FILE_APPEND | LOCK_EX ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
	}

	/**
	 * Wrap an ability handler so every invocation is timed and audit-logged.
	 *
	 * @param string        $ability    Ability name.
	 * @param callable      $handler    fn(array $input): mixed|WP_Error
	 * @param ?callable     $summarizer fn(array $input): array — a redacted summary for the log.
	 * @return callable The wrapped execute_callback.
	 */
	public static function wrap( string $ability, callable $handler, ?callable $summarizer = null ): callable {
		return static function ( $input = array() ) use ( $ability, $handler, $summarizer ) {
			$input   = is_array( $input ) ? $input : array();
			$start   = microtime( true );
			$result  = $handler( $input );
			$status  = is_wp_error( $result ) ? 'error' : 'ok';
			$summary = $summarizer ? (array) $summarizer( $input ) : array();
			self::log( $ability, $summary, $status, (int) round( ( microtime( true ) - $start ) * 1000 ) );
			return $result;
		};
	}
}
