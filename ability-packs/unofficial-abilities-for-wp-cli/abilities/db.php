<?php
/**
 * `wp db` / `wp search-replace` — direct database operations via native $wpdb.
 *
 * Pure PHP: no mysql/mysqldump CLI, no shell, no WP-CLI binary. Every ability
 * talks to the global $wpdb so it works when no command line is available.
 *
 * @package AcfwAbilityPackWpCli
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'wpcli_pack_db_wp_tables' ) ) {

	/**
	 * The set of real, existing tables that belong to this WordPress install.
	 *
	 * Combines $wpdb->tables('all') (core + multisite) with any table sharing
	 * the install prefix, then intersects with SHOW TABLES so callers only ever
	 * operate on identifiers that actually exist (no injection via user input).
	 *
	 * @return array<int,string> Existing WP table names.
	 */
	function wpcli_pack_db_wp_tables(): array {
		global $wpdb;
		$declared = array_values( (array) $wpdb->tables( 'all' ) );
		$like     = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix ) . '%' ) ); // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.DirectDatabaseQuery
		$like     = is_array( $like ) ? $like : array();
		$wp_set   = array_values( array_unique( array_merge( $declared, $like ) ) );

		$all = wpcli_pack_db_all_tables();
		return array_values( array_intersect( $wp_set, $all ) );
	}

	/**
	 * Every table the current DB connection can see (SHOW TABLES).
	 *
	 * @return array<int,string>
	 */
	function wpcli_pack_db_all_tables(): array {
		global $wpdb;
		$rows = $wpdb->get_col( 'SHOW TABLES' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Format a byte count into a human-readable string (B/KB/MB/GB/TB).
	 */
	function wpcli_pack_db_human_size( float $bytes ): string {
		$units = array( 'B', 'KB', 'MB', 'GB', 'TB', 'PB' );
		$i     = 0;
		while ( $bytes >= 1024 && $i < count( $units ) - 1 ) {
			$bytes /= 1024;
			++$i;
		}
		return ( 0 === $i ? (string) (int) $bytes : number_format( $bytes, 2 ) ) . ' ' . $units[ $i ];
	}

	/**
	 * Whether a column type holds text the search/replace abilities should walk.
	 */
	function wpcli_pack_db_is_text_type( string $type ): bool {
		$type = strtolower( $type );
		foreach ( array( 'char', 'text', 'varchar', 'tinytext', 'mediumtext', 'longtext', 'enum', 'set' ) as $needle ) {
			if ( false !== strpos( $type, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Read columns for a verified-existing table. Returns associative SHOW COLUMNS rows.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	function wpcli_pack_db_table_columns( string $table ): array {
		global $wpdb;
		// $table is verified against SHOW TABLES by callers; backtick-quote it.
		$rows = $wpdb->get_results( 'SHOW COLUMNS FROM `' . str_replace( '`', '', $table ) . '`', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.DirectDatabaseQuery
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Best-effort primary-key column name for a verified-existing table.
	 */
	function wpcli_pack_db_primary_key( string $table ): ?string {
		foreach ( wpcli_pack_db_table_columns( $table ) as $col ) {
			if ( isset( $col['Key'] ) && 'PRI' === $col['Key'] ) {
				return (string) $col['Field'];
			}
		}
		return null;
	}

	/**
	 * Serialization-safe recursive string replace. Mirrors WP-CLI search-replace:
	 * descends arrays/objects, re-serializes serialized PHP, and counts hits.
	 *
	 * @param mixed   $data  Value to walk.
	 * @param string  $from  Needle.
	 * @param string  $to    Replacement.
	 * @param int     $count Running replacement count (by reference).
	 * @return mixed Replaced value.
	 */
	function wpcli_pack_db_replace_recursive( $data, string $from, string $to, int &$count ) {
		if ( is_string( $data ) ) {
			$unser = is_serialized( $data ) ? maybe_unserialize( $data ) : null;
			if ( null !== $unser && ( is_array( $unser ) || is_object( $unser ) ) ) {
				$replaced = wpcli_pack_db_replace_recursive( $unser, $from, $to, $count );
				return maybe_serialize( $replaced );
			}
			if ( '' === $from ) {
				return $data;
			}
			$hits = substr_count( $data, $from );
			if ( $hits > 0 ) {
				$count += $hits;
				return str_replace( $from, $to, $data );
			}
			return $data;
		}
		if ( is_array( $data ) ) {
			foreach ( $data as $key => $value ) {
				$data[ $key ] = wpcli_pack_db_replace_recursive( $value, $from, $to, $count );
			}
			return $data;
		}
		if ( is_object( $data ) ) {
			foreach ( get_object_vars( $data ) as $key => $value ) {
				$data->$key = wpcli_pack_db_replace_recursive( $value, $from, $to, $count );
			}
			return $data;
		}
		return $data;
	}
}

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/db-query',
		'label'            => 'Run SQL Query',
		'description'      => 'Execute an arbitrary SQL statement against the WordPress database (like `wp db query`). Read statements (SELECT/SHOW/DESCRIBE/EXPLAIN/PRAGMA) return matching rows; write statements return rows_affected and insert_id. DANGEROUS: runs raw SQL with no sandbox — it can read or destroy any data.',
		'category'         => 'wp-cli-data',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'sql' => array( 'type' => 'string', 'description' => 'The SQL statement to run, e.g. "SELECT * FROM wp_options LIMIT 5".' ),
			),
			'required'             => array( 'sql' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'rows'          => array( 'type' => 'array', 'description' => 'Result rows (read statements only).' ),
				'num_rows'      => array( 'type' => 'integer' ),
				'rows_affected' => array( 'type' => 'integer' ),
				'insert_id'     => array( 'type' => 'integer' ),
				'last_error'    => array( 'type' => 'string' ),
			),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => true, 'idempotent' => false ),
		'summarize'        => static function ( array $input ) {
			$sql = isset( $input['sql'] ) ? (string) $input['sql'] : '';
			return 'Run SQL: ' . ( strlen( $sql ) > 500 ? substr( $sql, 0, 500 ) . '…' : $sql );
		},
		'execute_callback' => static function ( array $input ) {
			global $wpdb;
			$sql     = trim( (string) $input['sql'] );
			if ( '' === $sql ) {
				return wpcli_pack_err( 'empty_sql', 'sql must not be empty.' );
			}
			$is_read = (bool) preg_match( '/^\s*\(?\s*(SELECT|SHOW|DESCRIBE|DESC|EXPLAIN|PRAGMA)\b/i', $sql );
			if ( $is_read ) {
				$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.DirectDatabaseQuery
				$err  = (string) $wpdb->last_error;
				if ( '' !== $err ) {
					return wpcli_pack_err( 'db_error', $err );
				}
				$rows = is_array( $rows ) ? $rows : array();
				return array(
					'rows'       => $rows,
					'num_rows'   => count( $rows ),
					'last_error' => '',
				);
			}
			$affected = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.DirectDatabaseQuery
			$err      = (string) $wpdb->last_error;
			if ( '' !== $err ) {
				return wpcli_pack_err( 'db_error', $err );
			}
			return array(
				'rows_affected' => (int) $affected,
				'insert_id'     => (int) $wpdb->insert_id,
				'last_error'    => '',
			);
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/db-tables',
		'label'            => 'List Database Tables',
		'description'      => 'List the database tables for this WordPress install (like `wp db tables`). By default only tables matching the install prefix are returned; set all_tables to list every table the connection can see. `scope` narrows to global vs blog tables on multisite.',
		'category'         => 'wp-cli-data',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'scope'      => array( 'type' => 'string', 'enum' => array( 'all', 'global', 'blog' ), 'description' => 'Which WP table set to return. Default "all".' ),
				'all_tables' => array( 'type' => 'boolean', 'description' => 'Return every table on the connection, not just this install\'s. Default false.' ),
			),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'count'  => array( 'type' => 'integer' ),
				'tables' => array( 'type' => 'array' ),
			),
		),
		'annotations'      => array( 'readonly' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			global $wpdb;
			if ( ! empty( $input['all_tables'] ) ) {
				$tables = wpcli_pack_db_all_tables();
				return array( 'count' => count( $tables ), 'tables' => array_values( $tables ) );
			}
			$scope     = isset( $input['scope'] ) ? (string) $input['scope'] : 'all';
			$scope     = in_array( $scope, array( 'all', 'global', 'blog' ), true ) ? $scope : 'all';
			$declared  = array_values( (array) $wpdb->tables( $scope ) );
			$existing  = wpcli_pack_db_all_tables();
			$tables    = array_values( array_intersect(
				array_values( array_unique( array_merge( $declared, wpcli_pack_db_wp_tables() ) ) ),
				$existing
			) );
			if ( 'all' !== $scope ) {
				$tables = array_values( array_intersect( $declared, $existing ) );
			}
			sort( $tables );
			return array( 'count' => count( $tables ), 'tables' => $tables );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/db-size',
		'label'            => 'Database Size',
		'description'      => 'Report the database size, optionally broken down per table (like `wp db size [--tables]`). Sizes come from information_schema (data_length + index_length). Set human_readable to format sizes as KB/MB/GB.',
		'category'         => 'wp-cli-data',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'tables'         => array( 'type' => 'boolean', 'description' => 'Include a per-table size breakdown. Default false.' ),
				'human_readable' => array( 'type' => 'boolean', 'description' => 'Add human-formatted size strings. Default false.' ),
			),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'size_bytes' => array( 'type' => 'integer' ),
				'size'       => array( 'type' => 'string' ),
				'tables'     => array( 'type' => 'array' ),
			),
		),
		'annotations'      => array( 'readonly' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			global $wpdb;
			$human = ! empty( $input['human_readable'] );
			$rows  = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT TABLE_NAME AS name, (DATA_LENGTH + INDEX_LENGTH) AS size_bytes, TABLE_ROWS AS rows_est'
					. ' FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s',
					DB_NAME
				),
				ARRAY_A
			); // phpcs:ignore WordPress.DB.PreparedSQL
			$err = (string) $wpdb->last_error;
			if ( '' !== $err ) {
				return wpcli_pack_err( 'db_error', $err );
			}
			$rows  = is_array( $rows ) ? $rows : array();
			$total = 0;
			$per   = array();
			foreach ( $rows as $row ) {
				$bytes  = (int) $row['size_bytes'];
				$total += $bytes;
				if ( ! empty( $input['tables'] ) ) {
					$entry = array(
						'name'       => (string) $row['name'],
						'size_bytes' => $bytes,
						'rows'       => (int) $row['rows_est'],
					);
					if ( $human ) {
						$entry['size'] = wpcli_pack_db_human_size( (float) $bytes );
					}
					$per[] = $entry;
				}
			}
			$out = array( 'size_bytes' => $total );
			if ( $human ) {
				$out['size'] = wpcli_pack_db_human_size( (float) $total );
			}
			if ( ! empty( $input['tables'] ) ) {
				$out['tables'] = $per;
			}
			return $out;
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/db-columns',
		'label'            => 'Show Table Columns',
		'description'      => 'List the columns of a database table (like `wp db columns <table>`). Returns the SHOW COLUMNS definition (Field, Type, Null, Key, Default, Extra). The table must exist in this database.',
		'category'         => 'wp-cli-data',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'table' => array( 'type' => 'string', 'description' => 'Table name, e.g. "wp_posts".' ),
			),
			'required'             => array( 'table' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'columns' => array( 'type' => 'array' ),
			),
		),
		'annotations'      => array( 'readonly' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$table = (string) $input['table'];
			if ( ! in_array( $table, wpcli_pack_db_all_tables(), true ) ) {
				return wpcli_pack_err( 'table_not_found', sprintf( 'Table "%s" does not exist.', $table ), 404 );
			}
			return array( 'columns' => wpcli_pack_db_table_columns( $table ) );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/db-optimize',
		'label'            => 'Optimize Tables',
		'description'      => 'Run OPTIMIZE TABLE on all of this install\'s tables (like `wp db optimize`). Reclaims unused space and defragments storage.',
		'category'         => 'wp-cli-data',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'optimized' => array( 'type' => 'array' ),
				'results'   => array( 'type' => 'array' ),
			),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => false, 'idempotent' => true ),
		'execute_callback' => static function () {
			global $wpdb;
			$tables    = wpcli_pack_db_wp_tables();
			$optimized = array();
			$results   = array();
			foreach ( $tables as $table ) {
				$rows = $wpdb->get_results( 'OPTIMIZE TABLE `' . str_replace( '`', '', $table ) . '`', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.DirectDatabaseQuery
				$optimized[] = $table;
				$msg         = '';
				if ( is_array( $rows ) ) {
					foreach ( $rows as $r ) {
						if ( isset( $r['Msg_text'] ) ) {
							$msg = (string) $r['Msg_text'];
						}
					}
				}
				$results[] = array( 'table' => $table, 'msg' => $msg );
			}
			return array( 'optimized' => $optimized, 'results' => $results );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/db-repair',
		'label'            => 'Repair Tables',
		'description'      => 'Run REPAIR TABLE on all of this install\'s tables (like `wp db repair`). Attempts to fix corrupted tables (MyISAM and similar engines).',
		'category'         => 'wp-cli-data',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'repaired' => array( 'type' => 'array' ),
				'results'  => array( 'type' => 'array' ),
			),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => false, 'idempotent' => true ),
		'execute_callback' => static function () {
			global $wpdb;
			$tables   = wpcli_pack_db_wp_tables();
			$repaired = array();
			$results  = array();
			foreach ( $tables as $table ) {
				$rows = $wpdb->get_results( 'REPAIR TABLE `' . str_replace( '`', '', $table ) . '`', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.DirectDatabaseQuery
				$repaired[] = $table;
				$msg        = '';
				if ( is_array( $rows ) ) {
					foreach ( $rows as $r ) {
						if ( isset( $r['Msg_text'] ) ) {
							$msg = (string) $r['Msg_text'];
						}
					}
				}
				$results[] = array( 'table' => $table, 'msg' => $msg );
			}
			return array( 'repaired' => $repaired, 'results' => $results );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/db-check',
		'label'            => 'Check Tables',
		'description'      => 'Run CHECK TABLE on all of this install\'s tables (like `wp db check`). Reports the integrity status of each table without modifying data.',
		'category'         => 'wp-cli-data',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'results' => array( 'type' => 'array' ),
			),
		),
		'annotations'      => array( 'readonly' => true, 'idempotent' => true ),
		'execute_callback' => static function () {
			global $wpdb;
			$tables  = wpcli_pack_db_wp_tables();
			$results = array();
			foreach ( $tables as $table ) {
				$rows = $wpdb->get_results( 'CHECK TABLE `' . str_replace( '`', '', $table ) . '`', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.DirectDatabaseQuery
				$msg  = '';
				if ( is_array( $rows ) ) {
					foreach ( $rows as $r ) {
						if ( isset( $r['Msg_text'] ) ) {
							$msg = (string) $r['Msg_text'];
						}
					}
				}
				$results[] = array( 'table' => $table, 'msg' => $msg );
			}
			return array( 'results' => $results );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/db-prefix',
		'label'            => 'Database Table Prefix',
		'description'      => 'Return the table prefix for this WordPress install (like `wp db prefix`).',
		'category'         => 'wp-cli-data',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'prefix' => array( 'type' => 'string' ),
			),
		),
		'annotations'      => array( 'readonly' => true, 'idempotent' => true ),
		'execute_callback' => static function () {
			global $wpdb;
			return array( 'prefix' => (string) $wpdb->prefix );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/db-search',
		'label'            => 'Search Database',
		'description'      => 'Search the text columns of this install\'s tables for a string (like `wp db search <search>`). Only varchar/text-type columns are scanned. Results are bounded by `limit` (and a per-table LIMIT). Restrict to specific tables with `tables`.',
		'category'         => 'wp-cli-data',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'search' => array( 'type' => 'string', 'description' => 'Substring to search for.' ),
				'tables' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Restrict the search to these tables. Default: all WP tables.' ),
				'limit'  => array( 'type' => 'integer', 'description' => 'Max total matches to return. Default 100.' ),
			),
			'required'             => array( 'search' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'count'   => array( 'type' => 'integer' ),
				'matches' => array( 'type' => 'array' ),
			),
		),
		'annotations'      => array( 'readonly' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			global $wpdb;
			$search = (string) $input['search'];
			if ( '' === $search ) {
				return wpcli_pack_err( 'empty_search', 'search must not be empty.' );
			}
			$limit  = isset( $input['limit'] ) ? max( 1, (int) $input['limit'] ) : 100;
			$wp_set = wpcli_pack_db_wp_tables();
			if ( ! empty( $input['tables'] ) ) {
				$requested = array_map( 'strval', (array) $input['tables'] );
				$tables    = array_values( array_intersect( $requested, wpcli_pack_db_all_tables() ) );
			} else {
				$tables = $wp_set;
			}
			$like    = '%' . $wpdb->esc_like( $search ) . '%';
			$matches = array();
			foreach ( $tables as $table ) {
				if ( count( $matches ) >= $limit ) {
					break;
				}
				$safe_table = '`' . str_replace( '`', '', $table ) . '`';
				$pk         = wpcli_pack_db_primary_key( $table );
				$per_table  = max( 1, $limit - count( $matches ) );
				foreach ( wpcli_pack_db_table_columns( $table ) as $col ) {
					if ( count( $matches ) >= $limit ) {
						break;
					}
					if ( ! wpcli_pack_db_is_text_type( (string) ( $col['Type'] ?? '' ) ) ) {
						continue;
					}
					$field      = str_replace( '`', '', (string) $col['Field'] );
					$select_pk  = $pk ? ( '`' . str_replace( '`', '', $pk ) . '` AS row_pk, ' ) : '';
					$sql        = $wpdb->prepare(
						'SELECT ' . $select_pk . '`' . $field . '` AS value FROM ' . $safe_table
						. ' WHERE `' . $field . '` LIKE %s LIMIT %d',
						$like,
						$per_table
					); // phpcs:ignore WordPress.DB.PreparedSQL
					$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
					if ( ! is_array( $rows ) ) {
						continue;
					}
					foreach ( $rows as $row ) {
						if ( count( $matches ) >= $limit ) {
							break;
						}
						$value     = (string) ( $row['value'] ?? '' );
						$matches[] = array(
							'table'         => $table,
							'column'        => $field,
							'row_pk'        => isset( $row['row_pk'] ) ? $row['row_pk'] : null,
							'value_excerpt' => strlen( $value ) > 200 ? substr( $value, 0, 200 ) . '…' : $value,
						);
					}
				}
			}
			return array( 'count' => count( $matches ), 'matches' => $matches );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/db-search-replace',
		'label'            => 'Search and Replace',
		'description'      => 'Search and replace a string across the database, serialization-safe (like `wp search-replace <old> <new>`). Walks the text columns of every WP table (or only `tables`), unserializing serialized PHP so nested values are handled correctly. DEFAULTS TO DRY RUN: set dry_run=false to actually write changes. DANGEROUS when dry_run=false — it mutates rows in place.',
		'category'         => 'wp-cli-data',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'old'             => array( 'type' => 'string', 'description' => 'The string to search for.' ),
				'new'             => array( 'type' => 'string', 'description' => 'The replacement string.' ),
				'tables'          => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Restrict to these tables. Default: all WP tables.' ),
				'dry_run'         => array( 'type' => 'boolean', 'description' => 'Count replacements without writing. Default TRUE (safe). Set false to apply.' ),
				'include_columns' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Only process these column names (across all tables). Default: all text columns.' ),
				'precise'         => array( 'type' => 'boolean', 'description' => 'Always route through PHP serialization-safe replace (no fast SQL path). Default true here.' ),
			),
			'required'             => array( 'old', 'new' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'tables'             => array( 'type' => 'array' ),
				'total_replacements' => array( 'type' => 'integer' ),
				'dry_run'            => array( 'type' => 'boolean' ),
			),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => true, 'idempotent' => false ),
		'summarize'        => static function ( array $input ) {
			$old = isset( $input['old'] ) ? (string) $input['old'] : '';
			$new = isset( $input['new'] ) ? (string) $input['new'] : '';
			$old = strlen( $old ) > 500 ? substr( $old, 0, 500 ) . '…' : $old;
			$new = strlen( $new ) > 500 ? substr( $new, 0, 500 ) . '…' : $new;
			$dry = array_key_exists( 'dry_run', $input ) ? (bool) $input['dry_run'] : true;
			return sprintf( 'Search-replace "%s" → "%s"%s', $old, $new, $dry ? ' (dry run)' : '' );
		},
		'execute_callback' => static function ( array $input ) {
			global $wpdb;
			$from    = (string) $input['old'];
			$to      = (string) $input['new'];
			if ( '' === $from ) {
				return wpcli_pack_err( 'empty_old', 'old must not be empty.' );
			}
			$dry_run = array_key_exists( 'dry_run', $input ) ? (bool) $input['dry_run'] : true;
			$only    = ! empty( $input['include_columns'] ) ? array_map( 'strval', (array) $input['include_columns'] ) : array();

			if ( ! empty( $input['tables'] ) ) {
				$requested = array_map( 'strval', (array) $input['tables'] );
				$tables    = array_values( array_intersect( $requested, wpcli_pack_db_all_tables() ) );
			} else {
				$tables = wpcli_pack_db_wp_tables();
			}

			$total   = 0;
			$report  = array(); // Keyed "table\0column" => replacement count, flattened at the end.
			foreach ( $tables as $table ) {
				$safe_table = '`' . str_replace( '`', '', $table ) . '`';
				$pk         = wpcli_pack_db_primary_key( $table );
				$columns    = wpcli_pack_db_table_columns( $table );
				$text_cols  = array();
				foreach ( $columns as $col ) {
					$field = (string) $col['Field'];
					if ( ! wpcli_pack_db_is_text_type( (string) ( $col['Type'] ?? '' ) ) ) {
						continue;
					}
					if ( $only && ! in_array( $field, $only, true ) ) {
						continue;
					}
					$text_cols[] = $field;
				}
				if ( empty( $text_cols ) || null === $pk ) {
					continue;
				}
				$safe_pk    = '`' . str_replace( '`', '', $pk ) . '`';
				$select_str = $safe_pk;
				foreach ( $text_cols as $field ) {
					$select_str .= ', `' . str_replace( '`', '', $field ) . '`';
				}
				$like_clauses = array();
				foreach ( $text_cols as $field ) {
					$like_clauses[] = '`' . str_replace( '`', '', $field ) . '` LIKE %s';
				}
				$like   = '%' . $wpdb->esc_like( $from ) . '%';
				$params = array_fill( 0, count( $like_clauses ), $like );
				$sql    = $wpdb->prepare(
					'SELECT ' . $select_str . ' FROM ' . $safe_table . ' WHERE ' . implode( ' OR ', $like_clauses ),
					$params
				); // phpcs:ignore WordPress.DB.PreparedSQL
				$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
				if ( ! is_array( $rows ) ) {
					continue;
				}
				foreach ( $rows as $row ) {
					$updates = array();
					foreach ( $text_cols as $field ) {
						$count   = 0;
						$new_val = wpcli_pack_db_replace_recursive( $row[ $field ], $from, $to, $count );
						if ( $count > 0 ) {
							$total += $count;
							$key            = $table . "\0" . $field;
							$report[ $key ] = ( $report[ $key ] ?? 0 ) + $count;
							if ( ! $dry_run ) {
								$updates[ $field ] = $new_val;
							}
						}
					}
					if ( ! $dry_run && ! empty( $updates ) ) {
						$wpdb->update( $table, $updates, array( $pk => $row[ $pk ] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					}
				}
			}
			$tables_out = array();
			foreach ( $report as $key => $reps ) {
				list( $rt, $rc ) = explode( "\0", $key, 2 );
				$tables_out[]    = array(
					'table'        => $rt,
					'column'       => $rc,
					'replacements' => $reps,
				);
			}
			return array(
				'tables'             => $tables_out,
				'total_replacements' => $total,
				'dry_run'            => $dry_run,
			);
		},
	)
);
