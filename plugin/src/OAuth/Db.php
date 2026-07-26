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
 *                          A confidential client's secret is stored as a
 *                          SHA-256 hash; the raw value is returned once at
 *                          registration.
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
	private const SCHEMA_VERSION = 2;

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
			granted_at datetime DEFAULT NULL,
			last_used_at datetime DEFAULT NULL,
			last_ip varchar(100) DEFAULT NULL,
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
				// Hashed at rest, like the tokens below. The raw secret is
				// returned to the registering client once (see the return
				// value) and never persisted.
				'client_secret'              => null === $client_secret ? null : self::hash_token( $client_secret ),
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
	 * Atomically claim an authorization code (single-use enforcement).
	 *
	 * The UPDATE is conditional on `used = 0` and the return value reports
	 * rows-affected, so exactly one of N concurrent exchanges of the same code
	 * can win. An unconditional UPDATE would not do: reading `used` and writing
	 * it are separate statements, so two requests could both observe `used = 0`
	 * and both mint a token pair.
	 *
	 * @param string $code The authorization code.
	 * @return bool True if this caller claimed the code; false if it was
	 *              already used (or the write failed).
	 */
	public static function mark_code_used( string $code ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom OAuth tables, no WP cache API applicable.
		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET used = 1 WHERE code = %s AND used = 0',
				self::table_codes(),
				$code
			)
		);

		return 1 === (int) $updated;
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
	 * @param array<string,mixed> $data Token data: client_id, user_id, scope, and
	 *                                  optionally granted_at (see below).
	 * @return array<string,mixed>|false Raw tokens + expires_in on success, false on failure.
	 */
	public static function insert_token( array $data ): array|false {
		global $wpdb;

		$access_token  = bin2hex( random_bytes( 32 ) );
		$refresh_token = bin2hex( random_bytes( 32 ) );

		$scope = (string) ( $data['scope'] ?? 'mcp:tools' );

		// When the client rotates its refresh token the old row is revoked and
		// this new one replaces it, so `created_at` only ever reports the last
		// rotation. granted_at is carried across rotations by the caller so the
		// admin UI can show when the connection was actually authorized.
		$granted_at = (string) ( $data['granted_at'] ?? '' );
		if ( '' === $granted_at ) {
			$granted_at = gmdate( 'Y-m-d H:i:s' );
		}

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
				'granted_at'         => $granted_at,
			),
			array( '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
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

	/**
	 * Record that a token was just used, for the "last active" column.
	 *
	 * Throttled in SQL rather than PHP: the WHERE clause means the row is only
	 * rewritten once per $throttle seconds, so a chatty MCP client doesn't turn
	 * every tool call into a row update.
	 *
	 * @param int    $token_id The token row ID.
	 * @param string $ip       Client IP, best effort ('' when unknown).
	 * @param int    $throttle Minimum seconds between writes for one token.
	 */
	public static function touch_token( int $token_id, string $ip, int $throttle = 60 ): void {
		global $wpdb;

		if ( $token_id <= 0 ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom OAuth tables, no WP cache API applicable.
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET last_used_at = %s, last_ip = %s WHERE id = %d AND ( last_used_at IS NULL OR last_used_at < %s )',
				self::table_tokens(),
				gmdate( 'Y-m-d H:i:s' ),
				substr( $ip, 0, 100 ),
				$token_id,
				gmdate( 'Y-m-d H:i:s', time() - max( 0, $throttle ) )
			)
		);
	}

	/**
	 * Every registered client, newest first, each with a summary of its live
	 * grant (or null when the client holds no usable token).
	 *
	 * Deliberately grouped by client rather than returned as token rows: an
	 * always-on connection rotates its refresh token roughly hourly, so listing
	 * rows would show hundreds of entries for what a user thinks of as one
	 * connected app.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function list_clients(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom OAuth tables, no WP cache API applicable.
		$clients = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT client_id, client_name, redirect_uris, token_endpoint_auth_method, created_at FROM %i ORDER BY created_at DESC',
				self::table_clients()
			),
			ARRAY_A
		);

		if ( ! is_array( $clients ) || array() === $clients ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom OAuth tables, no WP cache API applicable.
		$tokens = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT client_id, user_id, scope, granted_at, created_at, last_used_at, last_ip FROM %i WHERE revoked = 0 AND refresh_expires_at > %s',
				self::table_tokens(),
				gmdate( 'Y-m-d H:i:s' )
			),
			ARRAY_A
		);
		$tokens = is_array( $tokens ) ? $tokens : array();

		$connections = array();

		foreach ( $tokens as $token ) {
			$cid = (string) $token['client_id'];

			// granted_at is null on rows written before the column existed;
			// created_at is the closest stand-in.
			$granted   = (string) ( $token['granted_at'] ?? '' );
			$granted   = '' !== $granted ? $granted : (string) $token['created_at'];
			$last_used = (string) ( $token['last_used_at'] ?? '' );

			if ( ! isset( $connections[ $cid ] ) ) {
				$connections[ $cid ] = array(
					'user_id'      => (int) $token['user_id'],
					'scope'        => (string) $token['scope'],
					'granted_at'   => $granted,
					'last_used_at' => $last_used,
					'last_ip'      => (string) ( $token['last_ip'] ?? '' ),
					'token_count'  => 0,
				);
			}

			++$connections[ $cid ]['token_count'];

			// Datetime strings in this format sort lexicographically, so plain
			// comparison gives earliest-grant / latest-use without parsing.
			if ( $granted < $connections[ $cid ]['granted_at'] ) {
				$connections[ $cid ]['granted_at'] = $granted;
			}
			if ( $last_used > $connections[ $cid ]['last_used_at'] ) {
				$connections[ $cid ]['last_used_at'] = $last_used;
				$connections[ $cid ]['last_ip']      = (string) ( $token['last_ip'] ?? '' );
			}
		}

		$out = array();

		foreach ( $clients as $client ) {
			$cid  = (string) $client['client_id'];
			$uris = json_decode( (string) $client['redirect_uris'], true );

			$out[] = array(
				'client_id'     => $cid,
				'client_name'   => (string) $client['client_name'],
				'redirect_uris' => is_array( $uris ) ? $uris : array(),
				'confidential'  => 'none' !== (string) $client['token_endpoint_auth_method'],
				'registered_at' => (string) $client['created_at'],
				'connection'    => $connections[ $cid ] ?? null,
			);
		}

		return $out;
	}

	/**
	 * Delete a client registration. Revoke its tokens separately — this only
	 * removes the client's identity, not its access.
	 *
	 * @param string $client_id The OAuth client ID.
	 */
	public static function delete_client( string $client_id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom OAuth tables, no WP cache API applicable.
		$deleted = $wpdb->delete( self::table_clients(), array( 'client_id' => $client_id ), array( '%s' ) );

		return is_int( $deleted ) && $deleted > 0;
	}

	/**
	 * Count client registrations that never received a token.
	 *
	 * Registration is unauthenticated by design (RFC 7591), so anyone can put a
	 * row in the clients table; only an administrator approving the consent
	 * screen turns one into access. These rows are inert, but showing the count
	 * gives an operator a way to notice — and clear — registration spam.
	 */
	public static function count_clients_without_tokens(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom OAuth tables, no WP cache API applicable.
		$count = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i c LEFT JOIN %i t ON t.client_id = c.client_id WHERE t.id IS NULL',
				self::table_clients(),
				self::table_tokens()
			)
		);

		return (int) $count;
	}

	/**
	 * Delete every client registration that never received a token.
	 *
	 * @return int Number of registrations removed.
	 */
	public static function delete_clients_without_tokens(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom OAuth tables, no WP cache API applicable.
		$deleted = $wpdb->query(
			$wpdb->prepare(
				'DELETE c FROM %i c LEFT JOIN %i t ON t.client_id = c.client_id WHERE t.id IS NULL',
				self::table_clients(),
				self::table_tokens()
			)
		);

		return (int) $deleted;
	}

	/**
	 * Delete token rows that can no longer authenticate anything.
	 *
	 * Refresh rotation revokes a row and writes a replacement on every refresh,
	 * so without this the table grows by a row per hour per connected client,
	 * forever. Revoked rows are kept for a day so a compromise is still
	 * visible in the table shortly after it happens.
	 *
	 * @return int Number of rows deleted.
	 */
	public static function cleanup_expired_tokens(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom OAuth tables, no WP cache API applicable.
		$deleted = $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE refresh_expires_at < %s OR ( revoked = 1 AND created_at < %s )',
				self::table_tokens(),
				gmdate( 'Y-m-d H:i:s' ),
				gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS )
			)
		);

		return (int) $deleted;
	}
}
