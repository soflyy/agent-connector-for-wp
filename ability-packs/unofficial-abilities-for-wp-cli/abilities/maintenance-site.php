<?php
/**
 * `wp maintenance-mode` and `wp site` — maintenance flag and site-level info/ops.
 *
 * @package AcfwAbilityPackWpCli
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/maintenance-mode-status',
		'label'            => 'Maintenance Mode Status',
		'description'      => 'Report whether maintenance mode is active (like `wp maintenance-mode status`). Active means the WordPress-native ABSPATH/.maintenance file exists.',
		'category'         => 'wp-cli-system',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array( 'active' => array( 'type' => 'boolean' ) ),
		),
		'annotations'      => array( 'readonly' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			return array( 'active' => file_exists( ABSPATH . '.maintenance' ) );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/maintenance-mode-activate',
		'label'            => 'Activate Maintenance Mode',
		'description'      => 'Activate maintenance mode (like `wp maintenance-mode activate`). Writes the WordPress-native ABSPATH/.maintenance file so the site shows the maintenance screen. ⚠️ CRITICAL: WordPress returns HTTP 503 to EVERY request while active — INCLUDING the REST/MCP endpoint these abilities run over. You will NOT be able to call wp-cli/maintenance-mode-deactivate (or any other ability) until it clears. WordPress auto-clears it ~10 minutes after activation; before that, recovery requires deleting ABSPATH/.maintenance from the filesystem (e.g. agent-connector-for-wp/file-delete or wp-cli on the host). Because of this self-lockout you MUST pass confirm=true.',
		'category'         => 'wp-cli-system',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'confirm' => array( 'type' => 'boolean', 'description' => 'Required acknowledgement that activating will lock you out of the MCP/REST channel until maintenance clears. Must be true.' ),
				'force'   => array( 'type' => 'boolean', 'description' => 'Re-activate even if maintenance mode is already active. Default false.' ),
			),
			'required'             => array( 'confirm' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'success' => array( 'type' => 'boolean' ),
				'warning' => array( 'type' => 'string' ),
			),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => true, 'idempotent' => false ),
		'execute_callback' => static function ( array $input ) {
			if ( empty( $input['confirm'] ) ) {
				return wpcli_pack_err( 'confirm_required', 'Refusing to activate: this blocks the MCP/REST channel (HTTP 503) until maintenance clears, so you could not deactivate it via MCP. Pass confirm=true only if you have filesystem/host access to remove ABSPATH/.maintenance, or are willing to wait the ~10 minutes WordPress takes to auto-clear it.', 400 );
			}
			$file  = ABSPATH . '.maintenance';
			$force = ! empty( $input['force'] );
			if ( file_exists( $file ) && ! $force ) {
				return wpcli_pack_err( 'already_active', 'Maintenance mode is already active. Pass force=true to re-activate.', 409 );
			}
			$ok = file_put_contents( $file, '<?php $upgrading = ' . time() . ';' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( false === $ok ) {
				return wpcli_pack_err( 'write_failed', sprintf( 'Could not write %s.', $file ) );
			}
			return array(
				'success' => true,
				'warning' => 'Maintenance mode is now ON. The MCP/REST channel will return HTTP 503 until WordPress auto-clears it (~10 min) or ABSPATH/.maintenance is removed from the filesystem. You likely cannot call deactivate via MCP right now.',
			);
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/maintenance-mode-deactivate',
		'label'            => 'Deactivate Maintenance Mode',
		'description'      => 'Deactivate maintenance mode (like `wp maintenance-mode deactivate`). Deletes the WordPress-native ABSPATH/.maintenance file. Succeeds even if it was not active.',
		'category'         => 'wp-cli-system',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array( 'success' => array( 'type' => 'boolean' ) ),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => false, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$file = ABSPATH . '.maintenance';
			if ( file_exists( $file ) && ! unlink( $file ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
				return wpcli_pack_err( 'delete_failed', sprintf( 'Could not delete %s.', $file ) );
			}
			return array( 'success' => true );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/site-url',
		'label'            => 'Get or Set Site URL',
		'description'      => 'Read the site address options (mirrors reading `siteurl`/`home`). Returns the WordPress address (`siteurl`) and Site address (`home`). Optionally pass `siteurl` and/or `home` to update them (mirrors `wp option update siteurl <url>` / `home`).',
		'category'         => 'wp-cli-system',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'siteurl' => array( 'type' => 'string', 'description' => 'New WordPress address (siteurl) to set. Omit to leave unchanged.' ),
				'home'    => array( 'type' => 'string', 'description' => 'New Site address (home) to set. Omit to leave unchanged.' ),
			),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'siteurl' => array( 'type' => 'string' ),
				'home'    => array( 'type' => 'string' ),
			),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => false, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			if ( array_key_exists( 'siteurl', $input ) && '' !== (string) $input['siteurl'] ) {
				update_option( 'siteurl', esc_url_raw( (string) $input['siteurl'] ) );
			}
			if ( array_key_exists( 'home', $input ) && '' !== (string) $input['home'] ) {
				update_option( 'home', esc_url_raw( (string) $input['home'] ) );
			}
			return array(
				'siteurl' => (string) get_option( 'siteurl' ),
				'home'    => (string) get_option( 'home' ),
			);
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/site-info',
		'label'            => 'Site Info',
		'description'      => 'Return a summary of core site settings (a convenience combining several option reads). Includes blog name, both addresses, admin email, WordPress version, language, timezone, and whether this is a multisite install.',
		'category'         => 'wp-cli-system',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'blogname'     => array( 'type' => 'string' ),
				'siteurl'      => array( 'type' => 'string' ),
				'home'         => array( 'type' => 'string' ),
				'admin_email'  => array( 'type' => 'string' ),
				'wp_version'   => array( 'type' => 'string' ),
				'language'     => array( 'type' => 'string' ),
				'timezone'     => array( 'type' => 'string' ),
				'is_multisite' => array( 'type' => 'boolean' ),
			),
		),
		'annotations'      => array( 'readonly' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$wp_version = get_bloginfo( 'version' );
			$language   = (string) get_option( 'WPLANG' );
			if ( '' === $language ) {
				$language = function_exists( 'get_locale' ) ? get_locale() : 'en_US';
			}
			return array(
				'blogname'     => (string) get_option( 'blogname' ),
				'siteurl'      => (string) get_option( 'siteurl' ),
				'home'         => (string) get_option( 'home' ),
				'admin_email'  => (string) get_option( 'admin_email' ),
				'wp_version'   => (string) $wp_version,
				'language'     => (string) $language,
				'timezone'     => (string) get_option( 'timezone_string' ),
				'is_multisite' => is_multisite(),
			);
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/site-empty',
		'label'            => 'Empty Site Content',
		'description'      => 'DELETE ALL CONTENT — every post, page, comment, term, and their meta/relationships (like `wp site empty`). This truncates wp_posts, wp_postmeta, wp_comments, wp_commentmeta, wp_term_relationships, wp_term_taxonomy, wp_terms, wp_termmeta and then re-creates the default terms. EXTREMELY DESTRUCTIVE AND IRREVERSIBLE: requires `yes`=true. Set `uploads`=true to also delete files in the uploads directory.',
		'category'         => 'wp-cli-system',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'yes'     => array( 'type' => 'boolean', 'description' => 'Confirmation. MUST be true or the operation is refused.' ),
				'uploads' => array( 'type' => 'boolean', 'description' => 'Also delete all files in the uploads directory. Default false.' ),
			),
			'required'             => array( 'yes' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'success'        => array( 'type' => 'boolean' ),
				'deleted_tables' => array( 'type' => 'array' ),
				'uploads_purged' => array( 'type' => 'boolean' ),
			),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => true, 'idempotent' => true ),
		'summarize'        => static function ( array $input ) {
			$uploads = ! empty( $input['uploads'] ) ? ' (and uploads)' : '';
			return 'Delete ALL site content — posts, comments, terms, meta' . $uploads . '. Irreversible.';
		},
		'execute_callback' => static function ( array $input ) {
			if ( empty( $input['yes'] ) ) {
				return wpcli_pack_err( 'confirmation_required', 'Refusing to empty the site. Pass yes=true to confirm this irreversible deletion.', 412 );
			}
			global $wpdb;
			$tables = array(
				$wpdb->posts,
				$wpdb->postmeta,
				$wpdb->comments,
				$wpdb->commentmeta,
				$wpdb->term_relationships,
				$wpdb->term_taxonomy,
				$wpdb->terms,
				$wpdb->termmeta,
			);
			$deleted = array();
			foreach ( $tables as $table ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$wpdb->query( 'TRUNCATE TABLE ' . $table );
				$deleted[] = $table;
			}

			$uploads_purged = false;
			if ( ! empty( $input['uploads'] ) ) {
				$dir = wp_get_upload_dir();
				if ( empty( $dir['error'] ) && ! empty( $dir['basedir'] ) && is_dir( $dir['basedir'] ) ) {
					$basedir  = (string) $dir['basedir'];
					$iterator = new RecursiveIteratorIterator(
						new RecursiveDirectoryIterator( $basedir, FilesystemIterator::SKIP_DOTS ),
						RecursiveIteratorIterator::CHILD_FIRST
					);
					foreach ( $iterator as $item ) {
						if ( $item->isDir() ) {
							@rmdir( $item->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
						} else {
							@unlink( $item->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
						}
					}
					$uploads_purged = true;
				}
			}

			// Recreate ONLY the default category so WordPress still has a valid
			// default term — matching `wp site empty`, which leaves the site
			// genuinely empty of posts/pages/comments (it does NOT re-seed the
			// "Hello world" post or "Sample Page").
			$cat_name = __( 'Uncategorized' );
			$cat_slug = sanitize_title( _x( 'Uncategorized', 'Default category slug' ) );
			$inserted = wp_insert_term( $cat_name, 'category', array( 'slug' => $cat_slug ) );
			if ( ! is_wp_error( $inserted ) && isset( $inserted['term_id'] ) ) {
				update_option( 'default_category', (int) $inserted['term_id'] );
			}

			wp_cache_flush();

			return array(
				'success'        => true,
				'deleted_tables' => $deleted,
				'uploads_purged' => $uploads_purged,
			);
		},
	)
);
