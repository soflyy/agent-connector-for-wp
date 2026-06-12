<?php
/**
 * The MCP events table: name resolution and schema creation.
 *
 * @package AgentConnectorForWp
 */

declare( strict_types=1 );

namespace AgentConnectorForWp\Observability;

defined( 'ABSPATH' ) || exit;

/**
 * Owns the `{prefix}acfw_mcp_events` table that backs the MCP Events admin
 * page. Creation is idempotent and version-gated so existing installs re-run
 * dbDelta only when the columns actually change.
 */
final class EventsTable {

	/**
	 * Unprefixed table base name. Resolves to `{$wpdb->prefix}acfw_mcp_events`.
	 */
	private const TABLE_BASE = 'acfw_mcp_events';

	/**
	 * Schema version stored in wp_options. Bump when the columns change so
	 * existing installs run dbDelta again on next load.
	 */
	private const SCHEMA_VERSION = 1;

	private const SCHEMA_VERSION_OPTION = 'agent_connector_for_wp_mcp_events_schema_version';

	/**
	 * Wire the one-time, version-gated table creation onto `init`.
	 *
	 * `init` covers admin loads, REST requests (the MCP traffic we log) and CLI
	 * alike, and the autoloaded option read makes the steady-state cost a single
	 * integer comparison.
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'maybe_create' ) );
	}

	/**
	 * Fully-prefixed name of the MCP events table.
	 */
	public static function name(): string {
		global $wpdb;

		return $wpdb->prefix . self::TABLE_BASE;
	}

	/**
	 * Create the table at most once per schema bump per install.
	 */
	public static function maybe_create(): void {
		$current = (int) get_option( self::SCHEMA_VERSION_OPTION, 0 );
		if ( $current >= self::SCHEMA_VERSION ) {
			return;
		}

		self::create();

		update_option( self::SCHEMA_VERSION_OPTION, self::SCHEMA_VERSION, true );
	}

	/**
	 * Create / update the table schema. Idempotent (uses dbDelta).
	 */
	public static function create(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = self::name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE `{$table}` (
			`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			`event` VARCHAR(64) NOT NULL,
			`method` VARCHAR(64) NULL,
			`server_id` VARCHAR(64) NULL,
			`transport` VARCHAR(32) NULL,
			`status` VARCHAR(16) NULL,
			`tool_name` VARCHAR(128) NULL,
			`prompt_name` VARCHAR(128) NULL,
			`resource_uri` TEXT NULL,
			`session_id` VARCHAR(64) NULL,
			`request_id` VARCHAR(64) NULL,
			`user_id` BIGINT NULL,
			`error_code` VARCHAR(32) NULL,
			`error_category` VARCHAR(32) NULL,
			`failure_reason` TEXT NULL,
			`duration_ms` FLOAT NULL,
			`tags_json` LONGTEXT NULL,
			`input_body` LONGTEXT NULL,
			`output_body` LONGTEXT NULL,
			`created_at` DATETIME NOT NULL,
			PRIMARY KEY  (`id`),
			KEY `event_idx` (`event`),
			KEY `status_idx` (`status`),
			KEY `created_idx` (`created_at`)
		) {$charset_collate};";

		dbDelta( $sql );
	}
}
