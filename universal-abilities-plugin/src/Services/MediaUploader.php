<?php
/**
 * Adds files to the WordPress media library.
 *
 * @package AgentConnectorForWp
 */

declare( strict_types=1 );

namespace AgentConnectorForWp\DefaultAbilities\Services;

use AgentConnectorForWp\Support\Helpers;

defined( 'ABSPATH' ) || exit;

/**
 * Sideloads a file from a URL, inline base64, or a path on the server into the
 * media library, through the same core pipeline as a wp-admin upload (file type
 * checks, unique filenames, generated image sizes). Returns the attachment in
 * the same shape search-media uses.
 */
final class MediaUploader {

	private const DOWNLOAD_TIMEOUT = 60;

	/**
	 * @param array{url?: string, base64?: string, path?: string, filename?: string, title?: string, alt?: string, caption?: string, description?: string, post_id?: int} $input
	 * @return array<string,mixed>|\WP_Error
	 */
	public function upload( array $input ) {
		$sources = array_filter(
			array(
				'url'    => (string) ( $input['url'] ?? '' ),
				'base64' => (string) ( $input['base64'] ?? '' ),
				'path'   => (string) ( $input['path'] ?? '' ),
			),
			static fn ( string $value ): bool => '' !== $value
		);
		if ( 1 !== count( $sources ) ) {
			return new \WP_Error( 'agent_connector_for_wp_bad_source', 'Provide exactly one of url, base64 or path.' );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$source   = (string) array_key_first( $sources );
		$filename = (string) ( $input['filename'] ?? '' );
		if ( '' === $filename && 'url' === $source ) {
			$filename = rawurldecode( wp_basename( (string) wp_parse_url( $sources['url'], PHP_URL_PATH ) ) );
		} elseif ( '' === $filename && 'path' === $source ) {
			$filename = wp_basename( $sources['path'] );
		}
		$tmp      = $this->to_temp_file( $source, $sources[ $source ], $filename );
		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}

		$name = $this->filename_with_extension( $tmp, $filename );
		if ( '' === $name ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return new \WP_Error( 'agent_connector_for_wp_unknown_type', 'Could not tell the file type. Pass a filename with an extension, e.g. "hero.jpg".' );
		}

		$post_data = array();
		if ( isset( $input['title'] ) && '' !== $input['title'] ) {
			$post_data['post_title'] = sanitize_text_field( (string) $input['title'] );
		}
		if ( isset( $input['caption'] ) ) {
			$post_data['post_excerpt'] = wp_kses_post( (string) $input['caption'] );
		}
		if ( isset( $input['description'] ) ) {
			$post_data['post_content'] = wp_kses_post( (string) $input['description'] );
		}

		$file_array = array(
			'name'     => $name,
			'tmp_name' => $tmp,
		);

		// Moves the temp file into uploads (or leaves it behind on failure).
		$id = media_handle_sideload( $file_array, max( 0, (int) ( $input['post_id'] ?? 0 ) ), null, $post_data );
		if ( is_wp_error( $id ) ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return $id;
		}

		if ( isset( $input['alt'] ) ) {
			update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( (string) $input['alt'] ) );
		}

		$item = MediaLibrary::describe( $id );
		if ( null === $item ) {
			return new \WP_Error( 'agent_connector_for_wp_upload_failed', 'The attachment was created but could not be read back.' );
		}

		$item['title'] = (string) get_the_title( $id );
		$item['sizes'] = $this->generated_sizes( $id );
		return $item;
	}

	/**
	 * Materialise the source as a temp file media_handle_sideload() can consume
	 * (and move, so a server path is copied, never moved).
	 *
	 * @return string|\WP_Error Temp file path.
	 */
	private function to_temp_file( string $source, string $value, string $filename ) {
		if ( 'url' === $source ) {
			$scheme = strtolower( (string) wp_parse_url( $value, PHP_URL_SCHEME ) );
			if ( 'http' !== $scheme && 'https' !== $scheme ) {
				return new \WP_Error( 'agent_connector_for_wp_bad_url', 'url must be an http or https URL.' );
			}
			// download_url() goes through wp_safe_remote_get(), which refuses
			// local and private network addresses.
			return download_url( $value, self::DOWNLOAD_TIMEOUT );
		}

		$tmp = wp_tempnam( '' !== $filename ? $filename : 'agent-upload' );
		if ( ! $tmp ) {
			return new \WP_Error( 'agent_connector_for_wp_tmp_failed', 'Could not create a temporary file.' );
		}

		if ( 'base64' === $source ) {
			// Tolerate a data: URI prefix ("data:image/png;base64,...").
			$payload = base64_decode( (string) preg_replace( '#^data:[^,]*,#', '', $value ), true );
			if ( false === $payload || '' === $payload ) {
				@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				return new \WP_Error( 'agent_connector_for_wp_bad_base64', 'base64 was not valid base64.' );
			}
			if ( false === file_put_contents( $tmp, $payload ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
				@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				return new \WP_Error( 'agent_connector_for_wp_write_failed', 'Could not write the temporary file.' );
			}
			return $tmp;
		}

		$resolved = Helpers::resolve_path( $value );
		if ( '' === $resolved || ! is_file( $resolved ) || ! is_readable( $resolved ) ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return new \WP_Error( 'agent_connector_for_wp_not_found', sprintf( 'File not found or not readable: %s', $value ) );
		}
		if ( ! copy( $resolved, $tmp ) ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return new \WP_Error( 'agent_connector_for_wp_copy_failed', sprintf( 'Could not copy: %s', $value ) );
		}
		return $tmp;
	}

	/**
	 * Pick the attachment's filename: the caller's, else the source's basename,
	 * with an extension sniffed from the bytes when it has none (WordPress
	 * rejects an upload it cannot map to an allowed type by extension).
	 */
	private function filename_with_extension( string $tmp, string $filename ): string {
		$name = sanitize_file_name( wp_basename( $filename ) );
		$ext  = pathinfo( $name, PATHINFO_EXTENSION );
		if ( '' !== $ext ) {
			return $name;
		}

		$mime = wp_get_image_mime( $tmp );
		if ( ! $mime && function_exists( 'mime_content_type' ) ) {
			$mime = (string) mime_content_type( $tmp );
		}
		$ext = $mime ? wp_get_default_extension_for_mime_type( $mime ) : false;
		if ( ! $ext ) {
			return '';
		}

		return ( '' !== $name ? $name : 'upload' ) . '.' . $ext;
	}

	/**
	 * @return array<int,string> Sizes WordPress generated for the attachment.
	 */
	private function generated_sizes( int $id ): array {
		$meta = wp_get_attachment_metadata( $id );
		if ( ! is_array( $meta ) || empty( $meta['sizes'] ) || ! is_array( $meta['sizes'] ) ) {
			return array();
		}
		return array_keys( $meta['sizes'] );
	}
}
