<?php
/**
 * Database-backed MCP observability handler.
 *
 * @package AgentConnectorForWp
 */

declare( strict_types=1 );

namespace AgentConnectorForWp\Observability;

use WP\MCP\Infrastructure\Observability\Contracts\McpObservabilityHandlerInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Persists MCP events into the `{prefix}acfw_mcp_events` table. The MCP Events
 * admin page reads this table to render the event list and per-event details.
 *
 * Designed to never throw: DB failures are swallowed and logged so a broken
 * observability layer can't break an MCP request.
 */
final class DatabaseObservabilityHandler implements McpObservabilityHandlerInterface {

	/**
	 * Maximum number of rows retained in the events table.
	 */
	private const MAX_ROWS = 1000;

	/**
	 * Trim the oldest rows every N inserts — a cheap heuristic that avoids a
	 * COUNT + DELETE on every single insert.
	 */
	private const TRIM_EVERY = 50;

	/**
	 * Flush deferred `mcp.request` rows once the REST response is captured, and
	 * again at shutdown as a safety net if `rest_post_dispatch` never fires.
	 *
	 * Priority 11 so it runs *after* {@see RequestCapture::capture_response}
	 * (registered at the default 10) populated the output body.
	 */
	public static function register(): void {
		add_filter( 'rest_post_dispatch', array( self::class, 'flush_pending_filter' ), 11, 3 );
		add_action( 'shutdown', array( self::class, 'flush_pending_row' ) );
	}

	/**
	 * `rest_post_dispatch` filter wrapper that flushes the pending row.
	 *
	 * @param mixed                                  $result  REST response (passed through).
	 * @param \WP_REST_Server                        $server  REST server.
	 * @param \WP_REST_Request<array<string, mixed>> $request The request.
	 * @return mixed
	 */
	public static function flush_pending_filter( $result, $server, $request ) {
		self::flush_pending_row();

		return $result;
	}

	/**
	 * Record an MCP event.
	 *
	 * @param string     $event       The event name (e.g. `mcp.request`).
	 * @param array      $tags        Tags merged + sanitized by the adapter.
	 * @param float|null $duration_ms Optional duration in milliseconds.
	 */
	public function record_event( string $event, array $tags = array(), ?float $duration_ms = null ): void {
		try {
			$tags_json = wp_json_encode( $tags );
			if ( false === $tags_json ) {
				$tags_json = '';
			}

			$row = array(
				'event'          => substr( $event, 0, 64 ),
				'method'         => self::extract_string_tag( $tags, array( 'method' ) ),
				'server_id'      => self::extract_string_tag( $tags, array( 'server_id' ) ),
				'transport'      => self::extract_string_tag( $tags, array( 'transport' ) ),
				'status'         => self::extract_string_tag( $tags, array( 'status' ) ),
				'tool_name'      => self::extract_string_tag( $tags, array( 'tool_name', 'name' ) ),
				'prompt_name'    => self::extract_string_tag( $tags, array( 'prompt_name' ) ),
				'resource_uri'   => self::extract_string_tag( $tags, array( 'uri', 'resource_uri' ) ),
				'session_id'     => self::extract_string_tag( $tags, array( 'session_id' ) ),
				'request_id'     => self::extract_string_tag( $tags, array( 'request_id' ) ),
				'user_id'        => self::extract_int_tag( $tags, 'user_id' ),
				'error_code'     => self::extract_string_tag( $tags, array( 'error_code' ) ),
				'error_category' => self::extract_string_tag( $tags, array( 'error_category', 'error_type' ) ),
				'failure_reason' => self::extract_string_tag( $tags, array( 'failure_reason' ) ),
				'duration_ms'    => null === $duration_ms ? null : (float) $duration_ms,
				'tags_json'      => $tags_json,
				'input_body'     => null,
				'output_body'    => null,
				'created_at'     => gmdate( 'Y-m-d H:i:s' ),
			);

			// For `mcp.request` events the response body isn't captured yet
			// (we're still inside the REST callback). Stash the row and let
			// flush_pending_row() insert it from `rest_post_dispatch` once both
			// input AND output are known.
			if ( 'mcp.request' === $event ) {
				$row['input_body'] = RequestCapture::get_input();
				RequestCapture::set_pending_row( $row );
				return;
			}

			self::insert_row( $row );
		} catch ( \Throwable $exception ) {
			error_log( '[ACFW MCP Observability] Failed to record event: ' . $exception->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	/**
	 * Insert the deferred `mcp.request` row (if any) once the REST response body
	 * has been captured. Called from `rest_post_dispatch` / `shutdown`.
	 */
	public static function flush_pending_row(): void {
		$row = RequestCapture::take_pending_row();
		if ( null === $row ) {
			return;
		}

		try {
			$row['output_body'] = RequestCapture::get_output();
			self::insert_row( $row );
		} catch ( \Throwable $exception ) {
			error_log( '[ACFW MCP Observability] Failed to flush pending row: ' . $exception->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	/**
	 * Run the INSERT and the periodic LRU trim.
	 *
	 * @param array<string, mixed> $row Row to insert.
	 */
	private static function insert_row( array $row ): void {
		global $wpdb;

		$table = EventsTable::name();

		$inserted = $wpdb->insert( $table, $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		if ( false === $inserted ) {
			return;
		}

		$insert_id = (int) $wpdb->insert_id;

		if ( $insert_id > 0 && 0 === $insert_id % self::TRIM_EVERY ) {
			self::trim_oldest_rows( $table );
		}
	}

	/**
	 * Delete every row outside the most recent MAX_ROWS.
	 *
	 * @param string $table Fully-prefixed table name.
	 */
	private static function trim_oldest_rows( string $table ): void {
		global $wpdb;

		// Subquery alias `t` is required so MySQL doesn't refuse the
		// self-referencing DELETE … (SELECT … FROM same_table).
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- bespoke log table, no core API covers it.
			$wpdb->prepare(
				'DELETE FROM %i WHERE `id` NOT IN (
					SELECT id FROM (
						SELECT `id` FROM %i ORDER BY `id` DESC LIMIT %d
					) AS t
				)',
				$table,
				$table,
				self::MAX_ROWS
			)
		);
	}

	/**
	 * Read the first present key from $tags, coerce to string, truncate to 255.
	 *
	 * @param array         $tags Tag map.
	 * @param array<string> $keys Keys to try, in order.
	 */
	private static function extract_string_tag( array $tags, array $keys ): ?string {
		foreach ( $keys as $key ) {
			if ( ! array_key_exists( $key, $tags ) ) {
				continue;
			}

			$value = $tags[ $key ];
			if ( null === $value || '' === $value ) {
				continue;
			}

			if ( is_scalar( $value ) ) {
				return substr( (string) $value, 0, 255 );
			}
		}

		return null;
	}

	/**
	 * Read an integer-valued tag.
	 *
	 * @param array  $tags Tag map.
	 * @param string $key  Tag key.
	 */
	private static function extract_int_tag( array $tags, string $key ): ?int {
		if ( ! array_key_exists( $key, $tags ) ) {
			return null;
		}

		$value = $tags[ $key ];
		if ( null === $value || '' === $value ) {
			return null;
		}

		if ( is_numeric( $value ) ) {
			return (int) $value;
		}

		return null;
	}
}
