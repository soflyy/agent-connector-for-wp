<?php
/**
 * Supplemental commands that round out WP-CLI coverage: a native-PHP database
 * dump (`wp db export`), salt rotation (`wp config shuffle-salts`), the rest of
 * `wp language core`, core checksum verification, and the *-generate verbs for
 * users and comments. All native PHP — no shell, no `wp` binary, no mysqldump.
 *
 * @package AcfwAbilityPackWpCli
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/*
 * --------------------------------------------------------------------------
 * wp db export — native-PHP mysqldump replacement.
 * --------------------------------------------------------------------------
 */
ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/db-export',
		'label'            => 'Export Database (SQL dump)',
		'description'      => 'Dump the database to SQL in pure PHP (like `wp db export`), with no mysqldump binary or shell required — the whole point of this pack. Each table is emitted as DROP TABLE + CREATE TABLE + INSERT rows. By default writes the dump to a file under uploads and returns its server path; pass return_content=true to get the SQL inline instead (only for small DBs — capped). Optionally restrict to specific `tables`.',
		'category'         => 'wp-cli-data',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'tables'         => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Only export these tables (exact names). Default: all tables with the site prefix.' ),
				'all_tables'     => array( 'type' => 'boolean', 'description' => 'Export every table in the database, not just prefixed ones. Default false.' ),
				'return_content' => array( 'type' => 'boolean', 'description' => 'Return the SQL inline instead of writing a file. Only honored when the dump is under ~5MB.' ),
				'file_path'      => array( 'type' => 'string', 'description' => 'Absolute server path to write the dump to. Default: a timestamped .sql file in the uploads directory.' ),
			),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'sql'          => array( 'type' => 'string', 'description' => 'The dump (only when return_content and small enough).' ),
				'file_path'    => array( 'type' => 'string' ),
				'bytes'        => array( 'type' => 'integer' ),
				'table_count'  => array( 'type' => 'integer' ),
				'tables'       => array( 'type' => 'array' ),
			),
		),
		'annotations'      => array( 'readonly' => true, 'idempotent' => false ),
		'execute_callback' => static function ( array $input ) {
			global $wpdb;
			$all_db   = $wpdb->get_col( 'SHOW TABLES' );
			$all_db   = is_array( $all_db ) ? $all_db : array();
			$wanted   = ( ! empty( $input['tables'] ) && is_array( $input['tables'] ) ) ? array_map( 'strval', $input['tables'] ) : array();
			$prefixed = ! empty( $input['all_tables'] ) ? $all_db : array_values( array_filter( $all_db, static fn( $t ) => 0 === strpos( (string) $t, $wpdb->prefix ) ) );
			$tables   = $wanted ? array_values( array_intersect( $all_db, $wanted ) ) : $prefixed;
			if ( empty( $tables ) ) {
				return wpcli_pack_err( 'no_tables', 'No matching tables to export.' );
			}

			$out   = "-- WP-CLI ability pack native DB export\n-- Generated " . gmdate( 'Y-m-d H:i:s' ) . " GMT\n\nSET FOREIGN_KEY_CHECKS=0;\n\n";
			$names = array();
			foreach ( $tables as $table ) {
				$table   = (string) $table;
				$names[] = $table;
				$create  = $wpdb->get_row( 'SHOW CREATE TABLE `' . str_replace( '`', '', $table ) . '`', ARRAY_N ); // phpcs:ignore WordPress.DB
				$out    .= "\n-- Table: {$table}\nDROP TABLE IF EXISTS `{$table}`;\n";
				if ( is_array( $create ) && isset( $create[1] ) ) {
					$out .= $create[1] . ";\n";
				}
				$rows = $wpdb->get_results( 'SELECT * FROM `' . str_replace( '`', '', $table ) . '`', ARRAY_A ); // phpcs:ignore WordPress.DB
				if ( $rows ) {
					$cols    = array_keys( $rows[0] );
					$collist = '`' . implode( '`,`', array_map( static fn( $c ) => str_replace( '`', '', (string) $c ), $cols ) ) . '`';
					foreach ( $rows as $row ) {
						$vals = array();
						foreach ( $row as $val ) {
							if ( null === $val ) {
								$vals[] = 'NULL';
							} else {
								$vals[] = "'" . esc_sql( (string) $val ) . "'";
							}
						}
						$out .= "INSERT INTO `{$table}` ({$collist}) VALUES (" . implode( ',', $vals ) . ");\n";
					}
				}
			}
			$out .= "\nSET FOREIGN_KEY_CHECKS=1;\n";

			$bytes = strlen( $out );
			if ( ! empty( $input['return_content'] ) && $bytes <= 5 * 1024 * 1024 ) {
				return array( 'sql' => $out, 'file_path' => '', 'bytes' => $bytes, 'table_count' => count( $names ), 'tables' => $names );
			}

			$path = isset( $input['file_path'] ) && '' !== (string) $input['file_path']
				? (string) $input['file_path']
				: trailingslashit( (string) ( wp_get_upload_dir()['basedir'] ?? sys_get_temp_dir() ) ) . 'wpcli-db-export-' . gmdate( 'Ymd-His' ) . '.sql';
			$written = file_put_contents( $path, $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( false === $written ) {
				return wpcli_pack_err( 'write_failed', sprintf( 'Could not write dump to %s.', $path ) );
			}
			return array( 'sql' => '', 'file_path' => $path, 'bytes' => $bytes, 'table_count' => count( $names ), 'tables' => $names );
		},
	)
);

/*
 * --------------------------------------------------------------------------
 * wp config shuffle-salts
 * --------------------------------------------------------------------------
 */
ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/config-shuffle-salts',
		'label'            => 'Shuffle Security Salts',
		'description'      => 'Regenerate the eight WordPress secret keys/salts (AUTH_KEY, SECURE_AUTH_KEY, LOGGED_IN_KEY, NONCE_KEY and their *_SALT) in wp-config.php (like `wp config shuffle-salts`). This logs out every user (existing cookies/sessions become invalid). Fetches fresh values from the WordPress.org secret-key service when reachable, otherwise generates them locally.',
		'category'         => 'wp-cli-system',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'success' => array( 'type' => 'boolean' ),
				'keys'    => array( 'type' => 'array' ),
			),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => true, 'idempotent' => false ),
		'execute_callback' => static function ( array $input ) {
			$keys = array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT' );

			// Try the official generator; fall back to local randomness.
			$generated = array();
			$resp      = wp_remote_get( 'https://api.wordpress.org/secret-key/1.1/salt/' );
			if ( ! is_wp_error( $resp ) && 200 === (int) wp_remote_retrieve_response_code( $resp ) ) {
				$body = wp_remote_retrieve_body( $resp );
				foreach ( $keys as $key ) {
					if ( preg_match( "/define\(\s*'" . $key . "',\s*'([^']*)'\s*\)/", $body, $m ) ) {
						$generated[ $key ] = $m[1];
					}
				}
			}
			foreach ( $keys as $key ) {
				if ( empty( $generated[ $key ] ) ) {
					$generated[ $key ] = wp_generate_password( 64, true, true );
				}
			}

			$config = wpcli_pack_config_path();
			if ( null === $config || ! is_writable( $config ) ) {
				return wpcli_pack_err( 'config_unwritable', 'wp-config.php not found or not writable.' );
			}
			$contents = (string) file_get_contents( $config ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			$changed  = array();
			foreach ( $generated as $key => $value ) {
				$line    = "define( '" . $key . "', '" . addcslashes( $value, "'\\" ) . "' );";
				$pattern = "/define\(\s*'" . $key . "'\s*,\s*'(?:[^'\\\\]|\\\\.)*'\s*\)\s*;/";
				if ( preg_match( $pattern, $contents ) ) {
					$contents = preg_replace( $pattern, $line, $contents, 1 );
				} else {
					// No existing definition — insert before the stop-editing marker.
					$contents = preg_replace( "/(\\/\\* That's all, stop editing)/", $line . "\n\n$1", $contents, 1 );
				}
				$changed[] = $key;
			}
			if ( false === strpos( $contents, 'wp-settings.php' ) ) {
				return wpcli_pack_err( 'sanity_failed', 'Refusing to write: result no longer references wp-settings.php.' );
			}
			if ( false === file_put_contents( $config, $contents ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
				return wpcli_pack_err( 'write_failed', 'Could not write wp-config.php.' );
			}
			return array( 'success' => true, 'keys' => $changed );
		},
		'summarize'        => static function ( array $input ): array {
			return array(); // Never log salt values.
		},
	)
);

/*
 * --------------------------------------------------------------------------
 * wp language core uninstall / update
 * --------------------------------------------------------------------------
 */
ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/language-core-uninstall',
		'label'            => 'Uninstall Core Language',
		'description'      => 'Remove an installed core translation (like `wp language core uninstall <locale>`). Deletes the locale\'s .mo/.po/.l10n.php files from wp-content/languages. Refuses to remove the currently active site language.',
		'category'         => 'wp-cli-extend',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'language' => array( 'type' => 'string', 'description' => 'Locale to remove, e.g. "fr_FR".' ),
			),
			'required'             => array( 'language' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'success'       => array( 'type' => 'boolean' ),
				'deleted_files' => array( 'type' => 'integer' ),
			),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$locale = (string) $input['language'];
			if ( $locale === (string) get_locale() ) {
				return wpcli_pack_err( 'is_active', 'Cannot uninstall the active site language. Activate another language first.', 409 );
			}
			$dir = defined( 'WP_LANG_DIR' ) ? WP_LANG_DIR : WP_CONTENT_DIR . '/languages';
			if ( ! is_dir( $dir ) ) {
				return array( 'success' => true, 'deleted_files' => 0 );
			}
			$deleted = 0;
			foreach ( glob( $dir . '/' . $locale . '.*' ) ?: array() as $file ) {
				if ( @unlink( $file ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
					++$deleted;
				}
			}
			// admin/continents/etc. variants live as admin-<locale>.* too.
			foreach ( glob( $dir . '/admin-' . $locale . '.*' ) ?: array() as $file ) {
				if ( @unlink( $file ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
					++$deleted;
				}
			}
			return array( 'success' => true, 'deleted_files' => $deleted );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/language-core-update',
		'label'            => 'Update Core Languages',
		'description'      => 'Download the latest translations for all installed core languages (like `wp language core update`). Requires outbound network access to WordPress.org.',
		'category'         => 'wp-cli-extend',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'updated' => array( 'type' => 'array' ),
				'errors'  => array( 'type' => 'array' ),
			),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => false, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			require_once ABSPATH . 'wp-admin/includes/translation-install.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
			$updated = array();
			$errors  = array();
			foreach ( (array) get_available_languages() as $locale ) {
				$res = wp_download_language_pack( (string) $locale );
				if ( $res ) {
					$updated[] = (string) $locale;
				} else {
					$errors[] = (string) $locale;
				}
			}
			return array( 'updated' => $updated, 'errors' => $errors );
		},
	)
);

/*
 * --------------------------------------------------------------------------
 * wp core verify-checksums
 * --------------------------------------------------------------------------
 */
ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/core-verify-checksums',
		'label'            => 'Verify Core Checksums',
		'description'      => 'Verify that core WordPress files match the official checksums for the installed version (like `wp core verify-checksums`). Fetches checksums from the WordPress.org API (network required) and reports any modified or missing core files.',
		'category'         => 'wp-cli-system',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'verified' => array( 'type' => 'boolean' ),
				'version'  => array( 'type' => 'string' ),
				'modified' => array( 'type' => 'array' ),
				'missing'  => array( 'type' => 'array' ),
			),
		),
		'annotations'      => array( 'readonly' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			global $wp_version;
			$locale = (string) get_locale();
			$url    = 'https://api.wordpress.org/core/checksums/1.0/?version=' . rawurlencode( (string) $wp_version ) . '&locale=' . rawurlencode( $locale );
			$resp   = wp_remote_get( $url );
			if ( is_wp_error( $resp ) ) {
				return wpcli_pack_err( 'network_error', 'Could not fetch checksums: ' . $resp->get_error_message() );
			}
			$body = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
			if ( empty( $body['checksums'] ) || ! is_array( $body['checksums'] ) ) {
				return wpcli_pack_err( 'no_checksums', 'No checksums returned for this version/locale.' );
			}
			$modified = array();
			$missing  = array();
			foreach ( $body['checksums'] as $file => $expected ) {
				if ( 0 === strpos( $file, 'wp-content/' ) ) {
					continue; // Core does not own wp-content.
				}
				$path = ABSPATH . $file;
				if ( ! file_exists( $path ) ) {
					$missing[] = $file;
					continue;
				}
				if ( md5_file( $path ) !== $expected ) {
					$modified[] = $file;
				}
			}
			return array(
				'verified' => empty( $modified ) && empty( $missing ),
				'version'  => (string) $wp_version,
				'modified' => $modified,
				'missing'  => $missing,
			);
		},
	)
);

/*
 * --------------------------------------------------------------------------
 * wp user generate / wp comment generate (parity with post/term generate)
 * --------------------------------------------------------------------------
 */
ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/user-generate',
		'label'            => 'Generate Users',
		'description'      => 'Create N dummy users for testing (like `wp user generate`). Logins are auto-numbered. Returns the created user IDs.',
		'category'         => 'wp-cli-users',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'count' => array( 'type' => 'integer', 'description' => 'How many users to create. Default 5.' ),
				'role'  => array( 'type' => 'string', 'description' => 'Role for the new users. Default "subscriber". Empty string = no role.' ),
			),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array( 'created' => array( 'type' => 'array' ) ),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ),
		'execute_callback' => static function ( array $input ) {
			$count   = isset( $input['count'] ) ? max( 1, (int) $input['count'] ) : 5;
			$role    = array_key_exists( 'role', $input ) ? (string) $input['role'] : 'subscriber';
			$created = array();
			$base    = time();
			for ( $i = 0; $i < $count; $i++ ) {
				$login = 'user_' . $base . '_' . $i;
				$id    = wp_insert_user(
					array(
						'user_login' => $login,
						'user_pass'  => wp_generate_password( 24 ),
						'user_email' => $login . '@example.test',
						'role'       => $role,
					)
				);
				if ( ! is_wp_error( $id ) ) {
					$created[] = (int) $id;
				}
			}
			return array( 'created' => $created );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/comment-generate',
		'label'            => 'Generate Comments',
		'description'      => 'Create N dummy comments for testing (like `wp comment generate`). Attaches them to the given post (or post 1 by default). Returns the created comment IDs.',
		'category'         => 'wp-cli-content',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'count'   => array( 'type' => 'integer', 'description' => 'How many comments to create. Default 5.' ),
				'post_id' => array( 'type' => 'integer', 'description' => 'Post to attach the comments to. Default 1.' ),
			),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array( 'created' => array( 'type' => 'array' ) ),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ),
		'execute_callback' => static function ( array $input ) {
			$count   = isset( $input['count'] ) ? max( 1, (int) $input['count'] ) : 5;
			$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 1;
			$created = array();
			for ( $i = 0; $i < $count; $i++ ) {
				$id = wp_insert_comment(
					array(
						'comment_post_ID'      => $post_id,
						'comment_author'       => 'Generated User ' . ( $i + 1 ),
						'comment_author_email' => 'gen' . $i . '@example.test',
						'comment_content'      => 'Generated comment ' . ( $i + 1 ),
						'comment_approved'     => 1,
					)
				);
				if ( $id ) {
					$created[] = (int) $id;
				}
			}
			return array( 'created' => $created );
		},
	)
);
