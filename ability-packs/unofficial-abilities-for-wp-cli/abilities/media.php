<?php
/**
 * `wp media` — import, list, regenerate, and delete media library attachments,
 * all in native PHP (no shell, no `wp` binary).
 *
 * Importing from a remote URL requires outbound network access; importing from
 * an absolute server file path does not. Both routes funnel through
 * media_handle_sideload so the attachment, metadata, and thumbnails are created
 * the same way the wp-admin uploader would.
 *
 * @package AcfwAbilityPackWpCli
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'wpcli_pack_media_row' ) ) {

	/**
	 * Shape an attachment post into a clean array.
	 */
	function wpcli_pack_media_row( WP_Post $post ): array {
		return array(
			'id'        => (int) $post->ID,
			'title'     => $post->post_title,
			'url'       => (string) wp_get_attachment_url( $post->ID ),
			'mime_type' => $post->post_mime_type,
			'parent'    => (int) $post->post_parent,
		);
	}
}

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/media-import',
		'label'            => 'Import Media',
		'description'      => 'Import a file into the media library (like `wp media import <file>`). `url` may be an http(s) URL (downloaded with download_url — needs outbound network access) or an absolute path to a file already on the server. Optionally attach it to a post and set it as that post\'s featured image. Returns the new attachment_id and its public url.',
		'category'         => 'wp-cli-content',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'url'         => array( 'type' => 'string', 'description' => 'http(s) URL to download, or an absolute server file path to import.' ),
				'title'       => array( 'type' => 'string', 'description' => 'Attachment title. Defaults to the file name.' ),
				'caption'     => array( 'type' => 'string', 'description' => 'Attachment caption (post_excerpt).' ),
				'alt'         => array( 'type' => 'string', 'description' => 'Alt text (_wp_attachment_image_alt meta).' ),
				'description' => array( 'type' => 'string', 'description' => 'Attachment description (post_content).' ),
				'post_id'     => array( 'type' => 'integer', 'description' => 'Attach the new media to this parent post ID.' ),
				'featured'    => array( 'type' => 'boolean', 'description' => 'Set the imported image as the featured image of post_id. Requires post_id.' ),
			),
			'required'             => array( 'url' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'attachment_id' => array( 'type' => 'integer' ),
				'url'           => array( 'type' => 'string' ),
				'featured'      => array( 'type' => 'boolean' ),
			),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ),
		'execute_callback' => static function ( array $input ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';

			$source  = (string) $input['url'];
			$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;

			$tmp           = '';
			$cleanup_tmp   = false;
			$original_name = '';

			if ( preg_match( '#^https?://#i', $source ) ) {
				$tmp = download_url( $source );
				if ( is_wp_error( $tmp ) ) {
					return wpcli_pack_err( 'download_failed', 'Could not download the URL: ' . $tmp->get_error_message() );
				}
				$cleanup_tmp = true;
				// Derive a sensible file name from the URL path.
				$path          = wp_parse_url( $source, PHP_URL_PATH );
				$original_name = $path ? basename( $path ) : basename( $tmp );
				if ( '' === $original_name || false === strpos( $original_name, '.' ) ) {
					// Fall back to a generic name; sideload uses the extension to set the mime.
					$original_name = 'import-' . wp_generate_password( 6, false ) . '.tmp';
				}
			} else {
				// Treat as a local absolute path. Copy to a temp file so sideload can
				// move it without destroying the caller's original.
				if ( ! @is_file( $source ) ) {
					return wpcli_pack_err( 'file_not_found', sprintf( 'Local file "%s" does not exist or is not readable.', $source ), 404 );
				}
				$original_name = basename( $source );
				$tmp           = wp_tempnam( $original_name );
				if ( ! $tmp || ! @copy( $source, $tmp ) ) {
					if ( $tmp ) {
						@unlink( $tmp );
					}
					return wpcli_pack_err( 'copy_failed', sprintf( 'Could not stage local file "%s" for import.', $source ) );
				}
				$cleanup_tmp = true;
			}

			$file_array = array(
				'name'     => $original_name,
				'tmp_name' => $tmp,
			);

			$post_data = array();
			if ( isset( $input['title'] ) ) {
				$post_data['post_title'] = (string) $input['title'];
			}
			if ( isset( $input['caption'] ) ) {
				$post_data['post_excerpt'] = (string) $input['caption'];
			}
			if ( isset( $input['description'] ) ) {
				$post_data['post_content'] = (string) $input['description'];
			}

			$attachment_id = media_handle_sideload( $file_array, $post_id, null, $post_data );

			if ( is_wp_error( $attachment_id ) ) {
				if ( $cleanup_tmp && @is_file( $tmp ) ) {
					@unlink( $tmp );
				}
				return wpcli_pack_err( 'sideload_failed', 'Import failed: ' . $attachment_id->get_error_message() );
			}

			if ( isset( $input['alt'] ) ) {
				update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( (string) $input['alt'] ) );
			}

			$featured = false;
			if ( ! empty( $input['featured'] ) && $post_id > 0 ) {
				$featured = (bool) set_post_thumbnail( $post_id, $attachment_id );
			}

			return array(
				'attachment_id' => (int) $attachment_id,
				'url'           => (string) wp_get_attachment_url( $attachment_id ),
				'featured'      => $featured,
			);
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/media-list',
		'label'            => 'List Media',
		'description'      => 'List attachments in the media library (like `wp media` listing / `wp post list --post_type=attachment`). Filter by parent post, by mime type (e.g. "image", "image/png", "video"), and cap with number (default 25).',
		'category'         => 'wp-cli-content',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'post_id'   => array( 'type' => 'integer', 'description' => 'Only attachments whose parent is this post ID.' ),
				'mime_type' => array( 'type' => 'string', 'description' => 'Filter by mime type or prefix, e.g. "image", "image/jpeg", "application/pdf".' ),
				'number'    => array( 'type' => 'integer', 'description' => 'Maximum number of attachments to return. Default 25. Use -1 for all.' ),
			),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'count' => array( 'type' => 'integer' ),
				'items' => array( 'type' => 'array' ),
			),
		),
		'annotations'      => array( 'readonly' => true, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			$args = array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => isset( $input['number'] ) ? (int) $input['number'] : 25,
				'orderby'        => 'date',
				'order'          => 'DESC',
			);
			if ( isset( $input['post_id'] ) ) {
				$args['post_parent'] = (int) $input['post_id'];
			}
			if ( ! empty( $input['mime_type'] ) ) {
				$args['post_mime_type'] = (string) $input['mime_type'];
			}
			$query = new WP_Query( $args );
			$items = array();
			foreach ( $query->posts as $post ) {
				$items[] = wpcli_pack_media_row( $post );
			}
			return array( 'count' => count( $items ), 'items' => $items );
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/media-regenerate',
		'label'            => 'Regenerate Media',
		'description'      => 'Regenerate attachment metadata and thumbnails from the original files (like `wp media regenerate`). With no `ids`, processes every image attachment. `only_missing` skips attachments that already have generated metadata. Returns the IDs regenerated, skipped, and any errors.',
		'category'         => 'wp-cli-content',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'ids'          => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'integer' ),
					'description' => 'Attachment IDs to regenerate. Omit to process all image attachments.',
				),
				'only_missing' => array( 'type' => 'boolean', 'description' => 'Only regenerate attachments that have no existing metadata. Default false.' ),
			),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'regenerated' => array( 'type' => 'array' ),
				'skipped'     => array( 'type' => 'array' ),
				'errors'      => array( 'type' => 'array' ),
			),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => false, 'idempotent' => true ),
		'execute_callback' => static function ( array $input ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';

			if ( ! empty( $input['ids'] ) ) {
				$ids = array_map( 'intval', (array) $input['ids'] );
			} else {
				$ids = get_posts(
					array(
						'post_type'      => 'attachment',
						'post_mime_type' => 'image',
						'post_status'    => 'inherit',
						'posts_per_page' => -1,
						'fields'         => 'ids',
					)
				);
				$ids = array_map( 'intval', (array) $ids );
			}

			$only_missing = ! empty( $input['only_missing'] );
			$regenerated  = array();
			$skipped      = array();
			$errors       = array();

			foreach ( $ids as $id ) {
				$post = get_post( $id );
				if ( ! $post || 'attachment' !== $post->post_type ) {
					$errors[] = array( 'id' => $id, 'error' => 'Not an attachment.' );
					continue;
				}
				if ( $only_missing && wp_get_attachment_metadata( $id ) ) {
					$skipped[] = $id;
					continue;
				}
				$file = get_attached_file( $id );
				if ( ! $file || ! @is_file( $file ) ) {
					$errors[] = array( 'id' => $id, 'error' => 'Original file is missing.' );
					continue;
				}
				$meta = wp_generate_attachment_metadata( $id, $file );
				if ( empty( $meta ) || is_wp_error( $meta ) ) {
					$msg      = is_wp_error( $meta ) ? $meta->get_error_message() : 'wp_generate_attachment_metadata returned empty.';
					$errors[] = array( 'id' => $id, 'error' => $msg );
					continue;
				}
				wp_update_attachment_metadata( $id, $meta );
				$regenerated[] = $id;
			}

			return array(
				'regenerated' => $regenerated,
				'skipped'     => $skipped,
				'errors'      => $errors,
			);
		},
	)
);

ACFW_Pack::ability(
	array(
		'name'             => 'wp-cli/media-delete',
		'label'            => 'Delete Media',
		'description'      => 'Delete one or more attachments (like `wp post delete <id> --force` for attachments). With `force` true (the default) the files are removed from disk too; with false the attachment is trashed. Returns the IDs actually deleted.',
		'category'         => 'wp-cli-content',
		'input_schema'     => array(
			'type'                 => 'object',
			'properties'           => array(
				'ids'   => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'integer' ),
					'description' => 'Attachment IDs to delete.',
				),
				'force' => array( 'type' => 'boolean', 'description' => 'Permanently delete (remove files) instead of trashing. Default true.' ),
			),
			'required'             => array( 'ids' ),
			'additionalProperties' => false,
		),
		'output_schema'    => array(
			'type'       => 'object',
			'properties' => array(
				'deleted' => array( 'type' => 'array' ),
				'errors'  => array( 'type' => 'array' ),
			),
		),
		'annotations'      => array( 'readonly' => false, 'destructive' => true, 'idempotent' => false ),
		'execute_callback' => static function ( array $input ) {
			$ids   = array_map( 'intval', (array) $input['ids'] );
			$force = array_key_exists( 'force', $input ) ? (bool) $input['force'] : true;
			if ( empty( $ids ) ) {
				return wpcli_pack_err( 'no_ids', 'Provide at least one attachment ID in ids.' );
			}
			$deleted = array();
			$errors  = array();
			foreach ( $ids as $id ) {
				$post = get_post( $id );
				if ( ! $post || 'attachment' !== $post->post_type ) {
					$errors[] = array( 'id' => $id, 'error' => 'Not an attachment.' );
					continue;
				}
				$result = wp_delete_attachment( $id, $force );
				if ( $result ) {
					$deleted[] = $id;
				} else {
					$errors[] = array( 'id' => $id, 'error' => 'wp_delete_attachment failed.' );
				}
			}
			return array( 'deleted' => $deleted, 'errors' => $errors );
		},
	)
);
