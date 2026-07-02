<?php
/**
 * Read-only access to the WordPress media library.
 *
 * @package AgentConnectorForWp
 */

declare( strict_types=1 );

namespace AgentConnectorForWp\DefaultAbilities\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Searches attachments and returns lean, agent-friendly results: the shape an
 * image control consumes (id, url, alt) plus a little metadata. Deliberately
 * excludes the heavy srcset/sizes blob.
 */
final class MediaLibrary {

	/**
	 * Search the media library for attachments.
	 *
	 * @param array{query?: string, mime_type?: string|string[], limit?: int, offset?: int} $input
	 * @return array{total: int, items: array<int, array{id: int, url: string, alt: string, caption: string, filename: string, mime: string, width: int, height: int}>}
	 */
	public function search( array $input ): array {
		$query_args = array(
			'post_type'              => 'attachment',
			'post_status'            => 'inherit',
			'posts_per_page'         => min( 100, max( 1, (int) ( $input['limit'] ?? 20 ) ) ),
			'offset'                 => max( 0, (int) ( $input['offset'] ?? 0 ) ),
			'no_found_rows'          => false,
			'update_post_term_cache' => false,
			'orderby'                => 'date',
			'order'                  => 'DESC',
		);

		if ( ! empty( $input['query'] ) ) {
			$query_args['s'] = (string) $input['query'];
		}

		// Default to images; a literal "any" (or empty) opts out of the mime filter.
		// WP_Query treats "image" as image/*, and accepts a string or an array.
		$mime = $input['mime_type'] ?? 'image';
		if ( 'any' !== $mime && '' !== $mime && array() !== $mime ) {
			$query_args['post_mime_type'] = $mime;
		}

		$query = new \WP_Query( $query_args );

		$items = array_map(
			static function ( $post ): array {
				$meta   = wp_get_attachment_metadata( (int) $post->ID );
				$width  = is_array( $meta ) && isset( $meta['width'] ) ? (int) $meta['width'] : 0;
				$height = is_array( $meta ) && isset( $meta['height'] ) ? (int) $meta['height'] : 0;

				return array(
					'id'       => (int) $post->ID,
					'url'      => (string) wp_get_attachment_url( $post->ID ),
					'alt'      => (string) get_post_meta( $post->ID, '_wp_attachment_image_alt', true ),
					'caption'  => (string) $post->post_excerpt,
					'filename' => (string) wp_basename( (string) get_attached_file( $post->ID ) ),
					'mime'     => (string) $post->post_mime_type,
					'width'    => $width,
					'height'   => $height,
				);
			},
			$query->posts
		);

		return array(
			'total' => (int) $query->found_posts,
			'items' => $items,
		);
	}
}
