<?php
/**
 * Per-IP rate limiting for the public OAuth endpoints.
 *
 * @package AgentConnectorForWp
 */

declare( strict_types=1 );

namespace AgentConnectorForWp\OAuth;

use AgentConnectorForWp\Support\Helpers;

defined( 'ABSPATH' ) || exit;

/**
 * Fixed-window per-IP throttle backed by transients.
 *
 * The OAuth protocol endpoints (/register, /token, /revoke) are public by
 * spec, so nothing authenticates a caller before they cost us DB work. Token
 * entropy already makes brute force pointless (256-bit values); what this
 * limits is resource abuse — an anonymous flood filling the clients table via
 * DCR or tying PHP up in token-endpoint crypto and queries.
 *
 * Deliberately best-effort: the read-increment-write is not atomic, so a burst
 * racing on one uncached-transient window can overshoot the limit by a few
 * requests. That is fine for abuse control and avoids paying for a lock on
 * every legitimate request.
 */
final class RateLimiter {

	/**
	 * Transient key prefix. Full key: prefix + bucket + '_' + sha256(ip).
	 */
	private const KEY_PREFIX = 'acfw_oauth_rl_';

	/**
	 * Whether a request from the current caller is within the bucket's limit,
	 * counting this request against the window when it is.
	 *
	 * An empty client IP (a proxy stripping REMOTE_ADDR) is never throttled:
	 * the request cannot be attributed to a source, and failing closed would
	 * take the whole OAuth flow down for everyone behind that proxy.
	 *
	 * @param string $bucket Endpoint bucket ('register', 'token', 'revoke').
	 * @param int    $limit  Maximum requests per window.
	 * @param int    $window Window length in seconds.
	 */
	public static function allow( string $bucket, int $limit, int $window ): bool {
		$ip = Helpers::client_ip();
		if ( '' === $ip ) {
			return true;
		}

		/**
		 * Filters the per-IP request limit for one OAuth endpoint bucket.
		 *
		 * @param int    $limit  Maximum requests per window (0 disables the limit).
		 * @param string $bucket The endpoint bucket: 'register', 'token', or 'revoke'.
		 */
		$limit = (int) apply_filters( 'agent_connector_for_wp_oauth_rate_limit', $limit, $bucket );
		if ( $limit <= 0 ) {
			return true;
		}

		// The IP is hashed so the key stays fixed-length (IPv6 + prefix would
		// near the transient name limit) and raw IPs stay out of wp_options.
		$key   = self::KEY_PREFIX . $bucket . '_' . hash( 'sha256', $ip );
		$count = (int) get_transient( $key );

		if ( $count >= $limit ) {
			return false;
		}

		set_transient( $key, $count + 1, $window );

		return true;
	}
}
