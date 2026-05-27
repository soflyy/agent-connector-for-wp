<?php
/**
 * Small, dependency-free helpers shared across services.
 *
 * @package RootForAgents
 */

declare( strict_types=1 );

namespace RootForAgents\Support;

defined( 'ABSPATH' ) || exit;

final class Helpers {

	/**
	 * Resolve a caller-supplied path. Relative paths are resolved against
	 * ABSPATH; absolute paths are taken as-is. No sandboxing is applied
	 * (by design) — this just normalizes separators.
	 */
	public static function resolve_path( string $path ): string {
		$path = trim( $path );
		if ( '' === $path ) {
			return $path;
		}
		// Absolute (unix or windows) paths pass through unchanged.
		if ( '/' === $path[0] || preg_match( '#^[A-Za-z]:[\\\\/]#', $path ) ) {
			return $path;
		}
		return rtrim( ABSPATH, '/\\' ) . '/' . ltrim( $path, '/\\' );
	}

	/**
	 * Coerce a byte string into something safe to embed in a JSON response.
	 *
	 * Valid UTF-8 is returned untouched. Anything else is reported as base64
	 * so binary data round-trips losslessly instead of corrupting the payload.
	 *
	 * @return array{encoding:string,data:string} 'utf8' or 'base64'.
	 */
	public static function encode_output( string $bytes ): array {
		if ( '' === $bytes || self::is_valid_utf8( $bytes ) ) {
			return array(
				'encoding' => 'utf8',
				'data'     => $bytes,
			);
		}
		return array(
			'encoding' => 'base64',
			'data'     => base64_encode( $bytes ),
		);
	}

	public static function is_valid_utf8( string $bytes ): bool {
		// preg with /u fails fast on invalid sequences; mb_check_encoding is the
		// authoritative check when mbstring is present.
		if ( function_exists( 'mb_check_encoding' ) ) {
			return mb_check_encoding( $bytes, 'UTF-8' );
		}
		return (bool) preg_match( '//u', $bytes );
	}

	/**
	 * Truncate to a byte budget, returning the (possibly shortened) string and
	 * whether truncation occurred.
	 *
	 * @return array{0:string,1:bool}
	 */
	public static function truncate_bytes( string $bytes, int $max ): array {
		if ( strlen( $bytes ) <= $max ) {
			return array( $bytes, false );
		}
		return array( substr( $bytes, 0, $max ), true );
	}

	/**
	 * Best-effort current client IP for the audit trail.
	 */
	public static function client_ip(): string {
		foreach ( array( 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' ) as $key ) {
			if ( ! empty( $_SERVER[ $key ] ) ) {
				$value = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
				// X-Forwarded-For may be a list; take the first entry.
				return trim( explode( ',', $value )[0] );
			}
		}
		return '';
	}
}
