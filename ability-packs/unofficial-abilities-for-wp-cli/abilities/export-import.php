<?php
/**
 * `wp export` / `wp import` — produce and consume WordPress eXtended RSS (WXR)
 * documents in native PHP (no shell, no `wp` binary, no WordPress Importer
 * plugin).
 *
 * Export captures the output of WordPress core's export_wp() via output
 * buffering, so the WXR is byte-for-byte what `wp export` would write. Import is
 * a pragmatic native parser built on SimpleXML over the WXR namespaces (wp:,
 * content:, dc:): it inserts terms, posts, post meta, and re-assigns terms,
 * remapping parent IDs as it goes. It does NOT run the full WordPress Importer
 * (no media file fetching, no comment threading beyond basic fields, no author
 * creation) — orphaned content can be reassigned to `default_author`.
 *
 * @package AcfwAbilityPackWpCli
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/export',
		'label'            => 'Export Content (WXR)',
		'description'      => 'Export site content to a WXR (WordPress eXtended RSS) XML document (like `wp export`). Optionally filter by post_type, post_status, author (user ID), and a start_date/end_date range. By default the XML is returned inline; set return_content=false to write it to a file under uploads and return its path instead. Exports larger than ~5MB are always written to a file (and the response says so) to avoid oversized payloads.',
		'category'         => 'wp-cli-content',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'post_type'      => array( 'type' => 'string', 'description' => 'Limit to a single post type (e.g. "post", "page"). Default all content.' ),
				'post_status'    => array( 'type' => 'string', 'description' => 'Limit to a post status (e.g. "publish", "draft"). Only applies to post/page.' ),
				'author'         => array( 'type' => 'integer', 'description' => 'Limit to content by this author user ID.' ),
				'start_date'     => array( 'type' => 'string', 'description' => 'Only content on/after this date (YYYY-MM-DD).' ),
				'end_date'       => array( 'type' => 'string', 'description' => 'Only content on/before this date (YYYY-MM-DD).' ),
				'return_content' => array( 'type' => 'boolean', 'description' => 'Return the XML inline (default true). When false, write to a file under uploads and return file_path.' ),
			),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'xml'        => array( 'type' => 'string' ),
				'file_path'  => array( 'type' => 'string' ),
				'post_count' => array( 'type' => 'integer' ),
				'note'       => array( 'type' => 'string' ),
			),
		),
		'annotations'      => array( 'readonly' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			require_once ABSPATH . 'wp-admin/includes/export.php';

			$args = array( 'content' => 'all' );
			if ( ! empty( $input['post_type'] ) ) {
				$args['content'] = (string) $input['post_type'];
			}
			if ( ! empty( $input['post_status'] ) ) {
				$args['status'] = (string) $input['post_status'];
			}
			if ( ! empty( $input['author'] ) ) {
				$args['author'] = (int) $input['author'];
			}
			if ( ! empty( $input['start_date'] ) ) {
				$args['start_date'] = (string) $input['start_date'];
			}
			if ( ! empty( $input['end_date'] ) ) {
				$args['end_date'] = (string) $input['end_date'];
			}

			ob_start();
			export_wp( $args );
			$xml = ob_get_clean();

			if ( ! is_string( $xml ) || '' === $xml ) {
				return wpcli_pack_err( 'export_failed', 'export_wp produced no output.' );
			}

			// Count <item> elements as a quick post count.
			$post_count = substr_count( $xml, '<item>' );

			$return_content = array_key_exists( 'return_content', $input ) ? (bool) $input['return_content'] : true;
			$too_large      = strlen( $xml ) > ( 5 * 1024 * 1024 );

			if ( $return_content && ! $too_large ) {
				return array(
					'xml'        => $xml,
					'file_path'  => '',
					'post_count' => $post_count,
					'note'       => '',
				);
			}

			// Write to a file under uploads.
			$uploads = wp_upload_dir();
			if ( ! empty( $uploads['error'] ) ) {
				return wpcli_pack_err( 'uploads_unavailable', 'Cannot write export file: ' . $uploads['error'] );
			}
			$filename = 'wxr-export-' . gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 6, false ) . '.xml';
			$path     = trailingslashit( $uploads['path'] ) . $filename;
			$written  = file_put_contents( $path, $xml ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( false === $written ) {
				return wpcli_pack_err( 'write_failed', sprintf( 'Could not write export to "%s".', $path ) );
			}

			$note = $too_large
				? 'Export exceeded ~5MB, so it was written to a file instead of returned inline.'
				: 'Export written to a file as requested.';

			return array(
				'xml'        => '',
				'file_path'  => $path,
				'post_count' => $post_count,
				'note'       => $note,
			);
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/import',
		'label'            => 'Import Content (WXR)',
		'description'      => 'Import a WXR (WordPress eXtended RSS) document (like `wp import`). Provide either `file_path` (an absolute path to a .xml/.wxr file on the server) or `xml` (the raw WXR string). This is a pragmatic NATIVE importer (no WordPress Importer plugin): it inserts terms first, then posts (remapping parent IDs), post meta, and re-assigns post terms. It does NOT fetch media files, create missing authors, or fully thread comments; orphaned posts are assigned to `default_author` when given. Returns counts of imported items plus any per-item errors.',
		'category'         => 'wp-cli-content',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'file_path'      => array( 'type' => 'string', 'description' => 'Absolute path to a WXR (.xml/.wxr) file on the server.' ),
				'xml'            => array( 'type' => 'string', 'description' => 'Raw WXR XML string (alternative to file_path).' ),
				'default_author' => array( 'type' => 'integer', 'description' => 'User ID to own posts whose original author cannot be resolved.' ),
			),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'imported' => array( 'type' => 'object' ),
				'skipped'  => array( 'type' => 'integer' ),
				'errors'   => array( 'type' => 'array' ),
			),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ),
		'execute_callback' => static function ( array $input ) {
			// Resolve the raw XML.
			if ( ! empty( $input['file_path'] ) ) {
				$file = (string) $input['file_path'];
				if ( ! @is_file( $file ) || ! @is_readable( $file ) ) {
					return wpcli_pack_err( 'file_not_found', sprintf( 'WXR file "%s" does not exist or is not readable.', $file ), 404 );
				}
				$raw = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			} elseif ( ! empty( $input['xml'] ) ) {
				$raw = (string) $input['xml'];
			} else {
				return wpcli_pack_err( 'no_input', 'Provide either file_path or xml.' );
			}

			if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
				return wpcli_pack_err( 'empty_input', 'The WXR content is empty.' );
			}

			$prev = libxml_use_internal_errors( true );
			$xml  = simplexml_load_string( $raw );
			libxml_use_internal_errors( $prev );
			if ( false === $xml ) {
				return wpcli_pack_err( 'parse_failed', 'Could not parse the WXR XML.' );
			}

			$namespaces = $xml->getNamespaces( true );
			$wp_ns      = isset( $namespaces['wp'] ) ? $namespaces['wp'] : 'http://wordpress.org/export/1.2/';
			$content_ns = isset( $namespaces['content'] ) ? $namespaces['content'] : 'http://purl.org/rss/1.0/modules/content/';
			$dc_ns      = isset( $namespaces['dc'] ) ? $namespaces['dc'] : 'http://purl.org/dc/elements/1.1/';
			$excerpt_ns = isset( $namespaces['excerpt'] ) ? $namespaces['excerpt'] : 'http://wordpress.org/export/1.2/excerpt/';

			if ( ! isset( $xml->channel ) ) {
				return wpcli_pack_err( 'bad_wxr', 'WXR has no <channel>; it does not look like an export file.' );
			}
			$channel = $xml->channel;

			$default_author = isset( $input['default_author'] ) ? (int) $input['default_author'] : 0;

			$imported = array( 'posts' => 0, 'terms' => 0, 'comments' => 0 );
			$skipped  = 0;
			$errors   = array();

			// ---- 1. Terms (categories, tags, custom terms). --------------------
			$term_id_map = array(); // taxonomy|slug => term_id

			$insert_term = static function ( $taxonomy, $name, $slug, $description, $parent_slug ) use ( &$term_id_map, &$imported, &$errors ) {
				$taxonomy = (string) $taxonomy;
				$slug     = (string) $slug;
				if ( '' === $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
					return;
				}
				$key = $taxonomy . '|' . $slug;
				$existing = get_term_by( 'slug', $slug, $taxonomy );
				if ( $existing && ! is_wp_error( $existing ) ) {
					$term_id_map[ $key ] = (int) $existing->term_id;
					return;
				}
				$args = array( 'slug' => $slug, 'description' => (string) $description );
				if ( $parent_slug ) {
					$parent_key = $taxonomy . '|' . $parent_slug;
					if ( isset( $term_id_map[ $parent_key ] ) ) {
						$args['parent'] = $term_id_map[ $parent_key ];
					}
				}
				$res = wp_insert_term( '' !== (string) $name ? (string) $name : $slug, $taxonomy, $args );
				if ( is_wp_error( $res ) ) {
					$errors[] = array( 'type' => 'term', 'slug' => $slug, 'error' => $res->get_error_message() );
					return;
				}
				$term_id_map[ $key ] = (int) $res['term_id'];
				++$imported['terms'];
			};

			// wp:category
			foreach ( $channel->children( $wp_ns )->category as $cat ) {
				$c = $cat->children( $wp_ns );
				$insert_term( 'category', (string) $c->cat_name, (string) $c->category_nicename, (string) $c->category_description, (string) $c->category_parent );
			}
			// wp:tag
			foreach ( $channel->children( $wp_ns )->tag as $tag ) {
				$t = $tag->children( $wp_ns );
				$insert_term( 'post_tag', (string) $t->tag_name, (string) $t->tag_slug, (string) $t->tag_description, '' );
			}
			// wp:term (custom taxonomies)
			foreach ( $channel->children( $wp_ns )->term as $term ) {
				$tm = $term->children( $wp_ns );
				$insert_term( (string) $tm->term_taxonomy, (string) $tm->term_name, (string) $tm->term_slug, (string) $tm->term_description, (string) $tm->term_parent );
			}

			// ---- 2. Posts. -----------------------------------------------------
			// First pass: insert posts, building an original->new ID map. Second
			// pass: fix parents. We process in document order and patch parents
			// at the end for items whose parent appears later.
			$post_id_map     = array(); // original_id => new_id
			$pending_parents = array(); // new_id => original_parent_id

			foreach ( $channel->item as $item ) {
				$wp_item = $item->children( $wp_ns );
				$dc      = $item->children( $dc_ns );
				$cont    = $item->children( $content_ns );
				$exc     = $item->children( $excerpt_ns );

				$original_id = (int) $wp_item->post_id;
				$post_type   = (string) $wp_item->post_type;
				$post_type   = '' !== $post_type ? $post_type : 'post';

				// Resolve author by login, falling back to default_author.
				$author_login = (string) $dc->creator;
				$author_id    = 0;
				if ( '' !== $author_login ) {
					$user = get_user_by( 'login', $author_login );
					if ( $user ) {
						$author_id = (int) $user->ID;
					}
				}
				if ( ! $author_id && $default_author ) {
					$author_id = $default_author;
				}

				$postarr = array(
					'post_title'    => (string) $item->title,
					'post_name'     => (string) $wp_item->post_name,
					'post_content'  => (string) $cont->encoded,
					'post_excerpt'  => (string) $exc->encoded,
					'post_status'   => (string) $wp_item->status ? (string) $wp_item->status : 'publish',
					'post_type'     => $post_type,
					'post_date'     => (string) $wp_item->post_date ? (string) $wp_item->post_date : '',
					'post_date_gmt' => (string) $wp_item->post_date_gmt && '0000-00-00 00:00:00' !== (string) $wp_item->post_date_gmt ? (string) $wp_item->post_date_gmt : '',
					'menu_order'    => (int) $wp_item->menu_order,
					'comment_status'=> (string) $wp_item->comment_status ? (string) $wp_item->comment_status : 'closed',
					'ping_status'   => (string) $wp_item->ping_status ? (string) $wp_item->ping_status : 'closed',
				);
				if ( $author_id ) {
					$postarr['post_author'] = $author_id;
				}

				$new_id = wp_insert_post( wp_slash( $postarr ), true );
				if ( is_wp_error( $new_id ) ) {
					$errors[] = array( 'type' => 'post', 'original_id' => $original_id, 'error' => $new_id->get_error_message() );
					++$skipped;
					continue;
				}
				$new_id = (int) $new_id;
				if ( $original_id ) {
					$post_id_map[ $original_id ] = $new_id;
				}
				++$imported['posts'];

				$original_parent = (int) $wp_item->post_parent;
				if ( $original_parent ) {
					$pending_parents[ $new_id ] = $original_parent;
				}

				// Post meta.
				foreach ( $wp_item->postmeta as $meta ) {
					$meta_c = $meta->children( $wp_ns );
					$key    = (string) $meta_c->meta_key;
					if ( '' === $key ) {
						continue;
					}
					$value = maybe_unserialize( (string) $meta_c->meta_value );
					add_post_meta( $new_id, wp_slash( $key ), wp_slash( $value ) );
				}

				// Assign terms (category elements with a domain attribute).
				$term_assign = array(); // taxonomy => [slugs]
				foreach ( $item->category as $cat ) {
					$attrs    = $cat->attributes();
					$taxonomy = isset( $attrs['domain'] ) ? (string) $attrs['domain'] : '';
					$slug     = isset( $attrs['nicename'] ) ? (string) $attrs['nicename'] : '';
					if ( '' === $taxonomy || '' === $slug || ! taxonomy_exists( $taxonomy ) ) {
						continue;
					}
					$term_assign[ $taxonomy ][] = $slug;
				}
				foreach ( $term_assign as $taxonomy => $slugs ) {
					$term_ids = array();
					foreach ( $slugs as $slug ) {
						$term = get_term_by( 'slug', $slug, $taxonomy );
						if ( $term && ! is_wp_error( $term ) ) {
							$term_ids[] = (int) $term->term_id;
						}
					}
					if ( $term_ids ) {
						wp_set_object_terms( $new_id, $term_ids, $taxonomy, false );
					}
				}

				// Comments (basic fields only).
				foreach ( $wp_item->comment as $comment ) {
					$cm   = $comment->children( $wp_ns );
					$carr = array(
						'comment_post_ID'      => $new_id,
						'comment_author'       => (string) $cm->comment_author,
						'comment_author_email' => (string) $cm->comment_author_email,
						'comment_author_url'   => (string) $cm->comment_author_url,
						'comment_content'      => (string) $cm->comment_content,
						'comment_date'         => (string) $cm->comment_date,
						'comment_approved'     => (string) $cm->comment_approved,
					);
					$cid = wp_insert_comment( wp_slash( $carr ) );
					if ( $cid ) {
						++$imported['comments'];
					}
				}
			}

			// ---- 3. Fix up post parents now that the ID map is complete. -------
			foreach ( $pending_parents as $new_id => $original_parent ) {
				if ( isset( $post_id_map[ $original_parent ] ) ) {
					wp_update_post(
						array(
							'ID'          => $new_id,
							'post_parent' => $post_id_map[ $original_parent ],
						)
					);
				}
			}

			return array(
				'imported' => $imported,
				'skipped'  => $skipped,
				'errors'   => $errors,
			);
		},
	)
);
