<?php
/**
 * OAuth 2.1 database layer: clients, authorization codes, and tokens.
 *
 * @package AgentConnectorForWp
 */

declare( strict_types=1 );

namespace AgentConnectorForWp\OAuth;

defined( 'ABSPATH' ) || exit;

/**
 * Owns the three OAuth tables and all CRUD against them.
 *
 * Tables (all `{$wpdb->prefix}` + base):
 *   - acfw_oauth_clients : dynamically registered OAuth clients (RFC 7591).
 *   - acfw_oauth_codes   : single-use, 60-second authorization codes.
 *   - acfw_oauth_tokens  : access/refresh token pairs, stored ONLY as
 *                          SHA-256 hashes — the raw values are returned to the
 *                          client exactly once and never persisted.
 *
 * Creation is idempotent and version-gated, mirroring
 * {@see \AgentConnectorForWp\Observability\EventsTable}.
 */
final class Db {

	/**
	 * Schema version stored in wp_options. Bump when the columns change so
	 * existing installs run dbDelta again on next load.
	 */
	private const SCHEMA_VERSION = 1;

	private const SCHEMA_VERSION_OPTION = 'agent_connector_for_wp_oauth_schema_version';

	/**
	 * Access token lifetime in seconds (1 hour).
	 */
	public const ACCESS_TOKEN_TTL = 3600;

	/**
	 * Refresh token lifetime in seconds (30 days).
	 */
	public const REFRESH_TOKEN_TTL = 30 * DAY_IN_SECONDS;

	/**
	 * Authorization code lifetime in seconds.
	 */
	public const CODE_TTL = 60;

	/**
	 * Fully-prefixed clients table name.
	 */
	public static function table_clients(): string {
		global $wpdb;

		return $wpdb->prefix . 'acfw_oauth_clients';
	}

	/**
	 * Fully-prefixed authorization codes table name.
	 */
	public static function table_codes(): string {
		global $wpdb;

		return $wpdb->prefix . 'acfw_oauth_codes';
	}

	/**
	 * Fully-prefixed tokens table name.
	 */
	public static function table_tokens(): string {
		global $wpdb;

		return $wpdb->prefix . 'acfw_oauth_tokens';
	}

	/**
	 * Create the tables at most once per schema bump per install.
	 */
	public static function maybe_create(): void {
		$current = (int) get_option( self::SCHEMA_VERSION_OPTION, 0 );
		if ( $current >= self::SCHEMA_VERSION ) {
			return;
		}

		self::create_tables();

		update_option( self::SCHEMA_VERSION_OPTION, self::SCHEMA_VERSION, true );
	}

	/**
	 * Create / update the table schemas. Idempotent (uses dbDelta).
	 */
	public static function create_tables(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$t_clients       = self::table_clients();
		$t_codes         = self::table_codes();
		$t_tokens        = self::table_tokens();

		$sql_clients = "CREATE TABLE {$t_clients} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			client_id varchar(64) NOT NULL,
			client_secret varchar(255) DEFAULT NULL,
			client_name varchar(255) NOT NULL,
			redirect_uris text NOT NULL,
			grant_types text NOT NULL,
			token_endpoint_auth_method varchar(50) NOT NULL DEFAULT 'none',
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY client_id (client_id)
		) {$charset_collate};";

		$sql_codes = "CREATE TABLE {$t_codes} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			code varchar(128) NOT NULL,
			client_id varchar(64) NOT NULL,
			user_id bigint(20) unsigned NOT NULL,
			redirect_uri text NOT NULL,
			scope varchar(255) NOT NULL DEFAULT 'mcp:tools',
			code_challenge varchar(128) NOT NULL,
			code_challenge_method varchar(10) NOT NULL DEFAULT 'S256',
			expires_at datetime NOT NULL,
			used tinyint(1) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY code (code),
			KEY client_id (client_id)
		) {$charset_collate};";

		$sql_tokens = "CREATE TABLE {$t_tokens} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			access_token_hash varchar(64) NOT NULL,
			refresh_token_hash varchar(64) NOT NULL,
			client_id varchar(64) NOT NULL,
			user_id bigint(20) unsigned NOT NULL,
			scope varchar(255) NOT NULL DEFAULT 'mcp:tools',
			access_expires_at datetime NOT NULL,
			refresh_expires_at datetime NOT NULL,
			revoked tinyint(1) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY access_token_hash (access_token_hash),
			UNIQUE KEY refresh_token_hash (refresh_token_hash),
			KEY client_id (client_id),
			KEY user_id (user_id)
		) {$charset_collate};";

		dbDelta( $sql_clients );
		dbDelta( $sql_codes );
		dbDelta( $sql_tokens );
	}

	/**
	 * Drop all OAuth tables (uninstall path).
	 */
	public static function drop_tables(): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Intentional DDL for uninstall.
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}acfw_oauth_tokens" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}acfw_oauth_codes" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}acfw_oauth_clients" );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange

		delete_option( self::SCHEMA_VERSION_OPTION );
	}

	// -------------------------------------------------------------------
	// Clients.
	// -------------------------------------------------------------------

	/**
	 * Insert a new OAuth client (Dynamic Client Registration).
	 *
	 * @param array<string,mixed> $data Client registration data.
	 * @return array<string,mixed>|false Client data on success, false on failure.
	 */
	public static function insert_client( array $data ): array|false {
		global $wpdb;

		$client_id = bin2hex( random_bytes( 16 ) );

		$client_secret = null;
		$auth_method   = sanitize_text_field( (string) ( $data['token_endpoint_auth_method'] ?? 'none' ) );
		if ( 'none' !== $auth_method ) {
			$client_secret = bin2hex( random_bytes( 32 ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom OAuth tables, no WP cache API applicable.
		$inserted = $wpdb->insert(
			self::table_clients(),
			array(
				'client_id'                  => $client_id,
				'client_secret'              => $client_secret,
				'client_name'                => sanitize_text_field( (string) $data['client_name'] ),
				'redirect_uris'              => wp_json_encode( $data['redirect_uris'] ),
				'grant_types'                => wp_json_encode( $data['grant_types'] ?? array( 'authorization_code', 'refresh_token' ) ),
				'token_endpoint_auth_method' => $auth_method,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( ! $inserted ) {
			return false;
		}

		return array(
			'client_id'                  => $client_id,
			'client_secret'              => $client_secret,
			'client_name'                => $data['client_name'],
			'redirect_uris'              => $data['redirect_uris'],
			'grant_types'                => $data['grant_types'] ?? array( 'authorization_code', 'refresh_token' ),
			'token_endpoint_auth_method' => $auth_method,
		);
	}

	/**
	 * Get a client by its client_id.
	 *
	 * @param string $client_id The OAuth client ID.
	 * @return array<string,mixed>|null Client row (redirect_uris/grant_types decoded) or null.
	 */
	public static function get_client_by_id( string $client_id ): array|null {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom OAuth tables, no WP cache API applicable.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE client_id = %s',
				self::table_clients(),
				$client_id
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		$redirect_uris        = json_decode( (string) $row['redirect_uris'], true );
		$grant_types          = json_decode( (string) $row['grant_types'], true );
		$row['redirect_uris'] = is_array( $redirect_uris ) ? $redirect_uris : array();
		$row['grant_types']   = is_array( $grant_types ) ? $grant_types : array();

		return $row;
	}

	// -------------------------------------------------------------------
	// Authorization codes.
	// -------------------------------------------------------------------

	/**
	 * Insert a new single-use authorization code (expires in CODE_TTL seconds).
	 *
	 * @param array<string,mixed> $data Code data: client_id, user_id, redirect_uri, scope, code_challenge, code_challenge_method.
	 * @return string|false The raw code on success, false on failure.
	 */
	public static function insert_code( array $data ): string|false {
		global $wpdb;

		$code       = bin2hex( random_bytes( 32 ) );
		$expires_at = gmdate( 'Y-m-d H:i:s', time() + self::CODE_TTL );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom OAuth tables, no WP cache API applicable.
		$inserted = $wpdb->insert(
			self::table_codes(),
			array(
				'code'                  => $code,
				'client_id'             => (string) $data['client_id'],
				'user_id'               => (int) $data['user_id'],
				'redirect_uri'          => (string) $data['redirect_uri'],
				'scope'                 => (string) ( $data['scope'] ?? 'mcp:tools' ),
				'code_challenge'        => (string) $data['code_challenge'],
				'code_challenge_method' => (string) ( $data['code_challenge_method'] ?? 'S256' ),
				'expires_at'            => $expires_at,
			),
			array( '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		return $inserted ? $code : false;
	}

	/**
	 * Get an authorization code row (used or not — the caller checks `used`).
	 *
	 * @param string $code The authorization code.
	 * @return array<string,mixed>|null Code row or null if not found.
	 */
	public static function get_code( string $code ): array|null {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom OAuth tables, no WP cache API applicable.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE code = %s',
				self::table_codes(),
				$code
			),
			ARRAY_A
		);

		return $row ? $row : null;
	}

	/**
	 * Mark an authorization code as used (single-use enforcement).
	 *
	 * @param string $code The authorization code.
	 */
	public static function mark_code_used( string $code ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom OAuth tables, no WP cache API applicable.
		$updated = $wpdb->update(
			self::table_codes(),
			array( 'used' => 1 ),
			array( 'code' => $code ),
			array( '%d' ),
			array( '%s' )
		);

		return false !== $updated;
	}

	/**
	 * Delete expired authorization codes (opportunistic cleanup).
	 *
	 * @return int Number of deleted rows.
	 */
	public static function cleanup_expired_codes(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom OAuth tables, no WP cache API applicable.
		$deleted = $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE expires_at < %s',
				self::table_codes(),
				gmdate( 'Y-m-d H:i:s' )
			)
		);

		return (int) $deleted;
	}

	// -------------------------------------------------------------------
	// Tokens.
	// -------------------------------------------------------------------

	/**
	 * Hash a token for storage/lookup. Raw tokens are never persisted.
	 *
	 * @param string $token The raw token string.
	 */
	public static function hash_token( string $token ): string {
		return hash( 'sha256', $token );
	}

	/**
	 * Insert a new access + refresh token pair.
	 *
	 * @param array<string,mixed> $data Token data: client_id, user_id, scope.
	 * @return array<string,mixed>|false Raw tokens + expires_in on success, false on failure.
	 */
	public static function insert_token( array $data ): array|false {
		global $wpdb;

		$access_token  = bin2hex( random_bytes( 32 ) );
		$refresh_token = bin2hex( random_bytes( 32 ) );

		$scope = (string) ( $data['scope'] ?? 'mcp:tools' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom OAuth tables, no WP cache API applicable.
		$inserted = $wpdb->insert(
			self::table_tokens(),
			array(
				'access_token_hash'  => self::hash_token( $access_token ),
				'refresh_token_hash' => self::hash_token( $refresh_token ),
				'client_id'          => (string) $data['client_id'],
				'user_id'            => (int) $data['user_id'],
				'scope'              => $scope,
				'access_expires_at'  => gmdate( 'Y-m-d H:i:s', time() + self::ACCESS_TOKEN_TTL ),
				'refresh_expires_at' => gmdate( 'Y-m-d H:i:s', time() + self::REFRESH_TOKEN_TTL ),
			),
			array( '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
		);

		if ( ! $inserted ) {
			return false;
		}

		return array(
			'access_token'  => $access_token,
			'refresh_token' => $refresh_token,
			'expires_in'    => self::ACCESS_TOKEN_TTL,
			'scope'         => $scope,
		);
	}

	/**
	 * Get a live (non-revoked, non-expired) token row by access token hash.
	 *
	 * @param string $access_hash The hashed access token.
	 * @return array<string,mixed>|null
	 */
	public static function get_token_by_access_hash( string $access_hash ): array|null {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom OAuth tables, no WP cache API applicable.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE access_token_hash = %s AND revoked = 0 AND access_expires_at > %s',
				self::table_tokens(),
				$access_hash,
				gmdate( 'Y-m-d H:i:s' )
			),
			ARRAY_A
		);

		return $row ? $row : null;
	}

	/**
	 * Get a live (non-revoked, non-expired) token row by refresh token hash.
	 *
	 * @param string $refresh_hash The hashed refresh token.
	 * @return array<string,mixed>|null
	 */
	public static function get_token_by_refresh_hash( string $refresh_hash ): array|null {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom OAuth tables, no WP cache API applicable.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE refresh_token_hash = %s AND revoked = 0 AND refresh_expires_at > %s',
				self::table_tokens(),
				$refresh_hash,
				gmdate( 'Y-m-d H:i:s' )
			),
			ARRAY_A
		);

		return $row ? $row : null;
	}

	/**
	 * Revoke a token pair by access token hash.
	 *
	 * @param string $access_hash The hashed access token.
	 * @return bool True if a token was revoked.
	 */
	public static function revoke_token_by_access_hash( string $access_hash ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom OAuth tables, no WP cache API applicable.
		$updated = $wpdb->update(
			self::table_tokens(),
			array( 'revoked' => 1 ),
			array( 'access_token_hash' => $access_hash ),
			array( '%d' ),
			array( '%s' )
		);

		return $updated > 0;
	}

	/**
	 * Revoke a token pair by refresh token hash.
	 *
	 * @param string $refresh_hash The hashed refresh token.
	 * @return bool True if a token was revoked.
	 */
	public static function revoke_token_by_refresh_hash( string $refresh_hash ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom OAuth tables, no WP cache API applicable.
		$updated = $wpdb->update(
			self::table_tokens(),
			array( 'revoked' => 1 ),
			array( 'refresh_token_hash' => $refresh_hash ),
			array( '%d' ),
			array( '%s' )
		);

		return $updated > 0;
	}

	/**
	 * Revoke ALL tokens for a client. Invoked when an authorization code is
	 * replayed (RFC 6749 §4.1.2: code reuse = assume compromise).
	 *
	 * @param string $client_id The OAuth client ID.
	 * @return int Number of tokens revoked.
	 */
	public static function revoke_all_for_client( string $client_id ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom OAuth tables, no WP cache API applicable.
		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET revoked = 1 WHERE client_id = %s AND revoked = 0',
				self::table_tokens(),
				$client_id
			)
		);

		return (int) $updated;
	}
}
