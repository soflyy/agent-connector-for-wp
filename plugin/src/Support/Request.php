<?php
/**
 * Request helpers.
 *
 * @package AgentConnectorForWp
 */

declare( strict_types=1 );

namespace AgentConnectorForWp\Support;

defined( 'ABSPATH' ) || exit;

final class Request {

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
