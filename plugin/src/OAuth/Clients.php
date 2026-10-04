<?php
/**
 * OAuth client resolution: Client ID Metadata Documents, with Dynamic Client
 * Registration as the fallback.
 *
 * @package AgentConnectorForWp
 */

declare( strict_types=1 );

namespace AgentConnectorForWp\OAuth;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a client_id from a request into the client it names.
 *
 * Two kinds of client_id reach this server:
 *
 *   - An HTTPS URL: a Client ID Metadata Document (CIMD,
 *     draft-ietf-oauth-client-id-metadata-document), the client identification
 *     method MCP 2025-11-25 prefers. The client hosts a JSON document at that
 *     URL describing itself (name, redirect_uris), and this server fetches it
 *     on demand. Nothing is registered up front, and the client's identity is
 *     bound to the domain serving the document rather than to whatever name it
 *     chose to send.
 *   - Anything else: a client that registered itself through Dynamic Client
 *     Registration (RFC 7591, {@see Server::handle_register()}), looked up in
 *     the clients table. Kept as a fallback for clients that don't support
 *     CIMD yet.
 *
 * A metadata-document client is written to the clients table only once an
 * administrator approves it on the consent page ({@see self::remember()}), so
 * the token endpoint and the Connections screen see it like any other client.
 * Until then an authorization request leaves no trace in the database.
 */
final class Clients {

	/**
	 * Longest client_id URL accepted. Matches the width of the client_id
	 * columns, which stay indexable under utf8mb4 on older MySQL.
	 */
	public const MAX_CLIENT_ID_LENGTH = 191;

	/**
	 * Largest metadata document accepted, in bytes. The draft recommends
	 * capping documents at 5 KB.
	 */
	private const MAX_DOCUMENT_BYTES = 5120;

	/**
	 * How long a fetched document is cached when its response says nothing
	 * about caching, and the most any response can ask for.
	 */
	private const DEFAULT_CACHE_TTL = HOUR_IN_SECONDS;
	private const MAX_CACHE_TTL     = DAY_IN_SECONDS;

	/**
	 * Transient key prefix for cached metadata documents.
	 */
	private const CACHE_PREFIX = 'acfw_oauth_cimd_';

	/**
	 * Resolve a client_id to its client.
	 *
	 * @param string $client_id A client_id from a request, as returned by {@see self::sanitize_id()}.
	 * @return array<string,mixed>|WP_Error The client (client_id, client_name,
	 *         redirect_uris, grant_types, token_endpoint_auth_method), or an
	 *         `invalid_client` error saying why it can't be used.
	 */
	public static function get( string $client_id ): array|WP_Error {
		if ( self::is_metadata_url( $client_id ) ) {
			return self::from_metadata_document( $client_id );
		}

		$client = '' !== $client_id ? Db::get_client_by_id( $client_id ) : null;
		if ( null === $client ) {
			return new WP_Error( 'invalid_client', 'Unknown client_id.', array( 'status' => 400 ) );
		}

		return $client;
	}

	/**
	 * Store an approved client so the token endpoint and the Connections
	 * screen can find it. A no-op for a registered (DCR) client, which already
	 * has its row.
	 *
	 * @param array<string,mixed> $client A client returned by {@see self::get()}.
	 * @return bool False when the client could not be stored.
	 */
	public static function remember( array $client ): bool {
		if ( ! self::is_metadata_url( (string) $client['client_id'] ) ) {
			return true;
		}

		return Db::save_metadata_client( $client );
	}

	/**
	 * A client_id from a request, returned verbatim when it is one this
	 * server could recognise, or '' otherwise.
	 *
	 * Not run through sanitize_text_field(): a metadata URL must be compared
	 * byte for byte with the client_id inside its document, and that function
	 * strips percent-encoded octets.
	 *
	 * @param mixed $client_id The raw request parameter.
	 */
	public static function sanitize_id( $client_id ): string {
		if ( ! is_string( $client_id ) ) {
			return '';
		}

		if ( self::is_metadata_url( $client_id ) ) {
			return $client_id;
		}

		// Registered clients get a 32-character hex id; allow some slack
		// without letting arbitrary text through.
		return 1 === preg_match( '/^[A-Za-z0-9_-]{1,64}$/', $client_id ) ? $client_id : '';
	}

	/**
	 * Whether a client_id is a Client ID Metadata Document URL.
	 *
	 * Per the draft: an https URL with a path, no fragment, no credentials,
	 * and no dot segments. Like redirect URIs, it must also come through
	 * sanitization unchanged so it can be stored and compared verbatim.
	 *
	 * @param string $client_id The client_id to test.
	 */
	public static function is_metadata_url( string $client_id ): bool {
		if ( strlen( $client_id ) > self::MAX_CLIENT_ID_LENGTH || 0 !== strpos( $client_id, 'https://' ) ) {
			return false;
		}

		$parts = wp_parse_url( $client_id );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return false;
		}

		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['fragment'] ) ) {
			return false;
		}

		$path = (string) ( $parts['path'] ?? '' );
		if ( '' === $path || '/' === $path ) {
			return false;
		}

		foreach ( explode( '/', $path ) as $segment ) {
			if ( '.' === $segment || '..' === $segment ) {
				return false;
			}
		}

		return esc_url_raw( $client_id, array( 'https' ) ) === $client_id;
	}

	/**
	 * The host serving a metadata document, for display on the consent page;
	 * '' for a registered (DCR) client.
	 *
	 * @param string $client_id The client_id.
	 */
	public static function metadata_host( string $client_id ): string {
		if ( ! self::is_metadata_url( $client_id ) ) {
			return '';
		}

		return strtolower( (string) wp_parse_url( $client_id, PHP_URL_HOST ) );
	}

	/**
	 * The client described by the metadata document at $url, from cache when
	 * possible.
	 *
	 * @param string $url The client_id, already known to be a metadata URL.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function from_metadata_document( string $url ): array|WP_Error {
		$cache_key = self::CACHE_PREFIX . md5( $url );

		$cached = get_transient( $cache_key );
		if ( is_array( $cached ) && ( $cached['client_id'] ?? '' ) === $url ) {
			return $cached;
		}

		// wp_safe_remote_get() refuses URLs that resolve to private or
		// loopback addresses, so a client_id can't be used to make this site
		// probe its own network. Redirects aren't followed: the document has
		// to live at the URL that names it.
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 5,
				'redirection'         => 0,
				'limit_response_size' => self::MAX_DOCUMENT_BYTES,
				'headers'             => array( 'Accept' => 'application/json' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return self::invalid( 'The client metadata document could not be fetched: ' . $response->get_error_message() );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status ) {
			return self::invalid( sprintf( 'The client metadata document could not be fetched (HTTP %d).', $status ) );
		}

		// limit_response_size truncates rather than failing, so a body that
		// fills the limit is assumed to have been cut off.
		$body = wp_remote_retrieve_body( $response );
		if ( strlen( $body ) >= self::MAX_DOCUMENT_BYTES ) {
			return self::invalid( 'The client metadata document is too large.' );
		}

		$document = json_decode( $body, true );
		if ( ! is_array( $document ) || array_is_list( $document ) ) {
			return self::invalid( 'The client metadata document is not a JSON object.' );
		}

		$client = self::client_from_document( $url, $document );
		if ( is_wp_error( $client ) ) {
			return $client;
		}

		$ttl = self::cache_ttl( (string) wp_remote_retrieve_header( $response, 'cache-control' ) );
		if ( $ttl > 0 ) {
			set_transient( $cache_key, $client, $ttl );
		}

		return $client;
	}

	/**
	 * Validate a metadata document and reduce it to the client fields this
	 * server uses.
	 *
	 * @param string              $url      The URL the document was fetched from.
	 * @param array<string,mixed> $document The decoded document.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function client_from_document( string $url, array $document ): array|WP_Error {
		// The document vouches for the URL it is served from and no other.
		if ( ! isset( $document['client_id'] ) || $url !== $document['client_id'] ) {
			return self::invalid( 'The client_id in the client metadata document does not match its URL.' );
		}

		// There's no registration step to hand out a shared secret, and a
		// published document can't hold one.
		if ( isset( $document['client_secret'] ) || isset( $document['client_secret_expires_at'] ) ) {
			return self::invalid( 'A client metadata document must not contain a client secret.' );
		}

		$auth_method = $document['token_endpoint_auth_method'] ?? 'none';
		if ( 'none' !== $auth_method ) {
			return self::invalid( 'Only token_endpoint_auth_method "none" is supported for clients identified by a metadata document.' );
		}

		$redirect_uris = $document['redirect_uris'] ?? null;
		if ( ! is_array( $redirect_uris ) || array() === $redirect_uris ) {
			return self::invalid( 'The client metadata document must list its redirect_uris.' );
		}

		foreach ( $redirect_uris as $uri ) {
			if ( ! is_string( $uri ) || ! Server::is_valid_redirect_uri( $uri ) ) {
				return self::invalid( 'The client metadata document lists a redirect_uri this server does not accept.' );
			}
		}

		// client_name is optional; without one, the host serving the document
		// is the most honest label available.
		$client_name = is_string( $document['client_name'] ?? null ) ? sanitize_text_field( $document['client_name'] ) : '';
		if ( '' === $client_name ) {
			$client_name = self::metadata_host( $url );
		}

		return array(
			'client_id'                  => $url,
			'client_secret'              => null,
			'client_name'                => mb_substr( $client_name, 0, 255 ),
			'redirect_uris'              => array_values( $redirect_uris ),
			'grant_types'                => array( 'authorization_code', 'refresh_token' ),
			'token_endpoint_auth_method' => 'none',
		);
	}

	/**
	 * Seconds to cache a document for, from its Cache-Control header.
	 *
	 * @param string $cache_control The response's Cache-Control header, or ''.
	 */
	private static function cache_ttl( string $cache_control ): int {
		$cache_control = strtolower( $cache_control );

		if ( false !== strpos( $cache_control, 'no-store' ) || false !== strpos( $cache_control, 'no-cache' ) ) {
			return 0;
		}

		if ( preg_match( '/(?:^|[\s,])max-age\s*=\s*"?(\d+)/', $cache_control, $matches ) ) {
			return min( (int) $matches[1], self::MAX_CACHE_TTL );
		}

		return self::DEFAULT_CACHE_TTL;
	}

	/**
	 * An `invalid_client` error for an unusable metadata document.
	 *
	 * @param string $description Why the client was rejected.
	 */
	private static function invalid( string $description ): WP_Error {
		return new WP_Error( 'invalid_client', $description, array( 'status' => 400 ) );
	}
}
