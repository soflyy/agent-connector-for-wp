<?php
/**
 * Ability: agent-connector-for-wp/upload-media
 *
 * @package AgentConnectorForWp
 */

declare( strict_types=1 );

namespace AgentConnectorForWp\DefaultAbilities\Abilities;

use AgentConnectorForWp\Services\AuditLogger;
use AgentConnectorForWp\DefaultAbilities\Services\MediaUploader;
use AgentConnectorForWp\Support\Config;

defined( 'ABSPATH' ) || exit;

final class UploadMediaAbility {

	public const NAME = 'agent-connector-for-wp/upload-media';

	public static function is_allowed(): bool {
		return true;
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function definition(): array {
		return array(
			'label'               => 'Upload Media',
			'description'         => 'Adds a file to the WordPress media library, the same way an upload in wp-admin does (file type checks, image sizes generated), and returns the new attachment (id, url, alt, dimensions, generated sizes). Pass exactly one source: a public http/https url to download, inline base64 contents, or a path to a file already on the server. Use it to put an image on the site before using it, so the page references a library attachment instead of hotlinking an external URL; call search-media first to avoid uploading a duplicate. The returned id/url/alt can be set directly on an element image control.',
			'category'            => 'agent-connector-for-wp',
			'input_schema'        => array(
				'type'                 => 'object',
				'additionalProperties' => false,
				'properties'           => array(
					'url'         => array(
						'type'        => 'string',
						'description' => 'Public http/https URL to download. Local and private network addresses are refused.',
					),
					'base64'      => array(
						'type'        => 'string',
						'description' => 'File contents, base64-encoded (a "data:<mime>;base64," prefix is accepted). Pass filename with it.',
					),
					'path'        => array(
						'type'        => 'string',
						'description' => 'Path to a file on the server, relative to ABSPATH unless absolute. The file is copied; the original is left in place.',
					),
					'filename'    => array(
						'type'        => 'string',
						'description' => 'Filename for the attachment, e.g. "team-photo.jpg". Defaults to the URL or path basename; an extension is added from the file contents when missing.',
					),
					'title'       => array(
						'type'        => 'string',
						'description' => 'Attachment title. Defaults to the filename (or the image\'s embedded title).',
					),
					'alt'         => array(
						'type'        => 'string',
						'description' => 'Alt text for an image. Describe what the image shows.',
					),
					'caption'     => array(
						'type'        => 'string',
						'description' => 'Attachment caption.',
					),
					'description' => array(
						'type'        => 'string',
						'description' => 'Attachment description.',
					),
					'post_id'     => array(
						'type'        => 'integer',
						'minimum'     => 0,
						'description' => 'Post to attach the file to (its parent). Omit to leave it unattached.',
					),
				),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'id'       => array(
						'type'        => 'integer',
						'description' => 'Attachment ID. Set this as the image control id to use the library asset.',
					),
					'url'      => array(
						'type'        => 'string',
						'description' => 'Full-size URL of the attachment.',
					),
					'title'    => array(
						'type'        => 'string',
						'description' => 'Attachment title.',
					),
					'alt'      => array(
						'type'        => 'string',
						'description' => 'Alt text stored on the attachment.',
					),
					'caption'  => array(
						'type'        => 'string',
						'description' => 'Attachment caption.',
					),
					'filename' => array(
						'type'        => 'string',
						'description' => 'File name in the uploads directory (WordPress may add a suffix to keep it unique).',
					),
					'mime'     => array(
						'type'        => 'string',
						'description' => 'MIME type, e.g. "image/jpeg".',
					),
					'width'    => array(
						'type'        => 'integer',
						'description' => 'Intrinsic width in pixels (0 if not an image).',
					),
					'height'   => array(
						'type'        => 'integer',
						'description' => 'Intrinsic height in pixels (0 if not an image).',
					),
					'sizes'    => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => 'Image sizes WordPress generated (e.g. "thumbnail", "medium"). None is larger than the original.',
					),
				),
			),
			'execute_callback'    => AuditLogger::wrap(
				self::NAME,
				array( self::class, 'execute' ),
				array( self::class, 'summarize' )
			),
			'permission_callback' => static function (): bool {
				return Config::has_admin_access();
			},
			'meta'                => array(
				'mcp'          => array( 'public' => true ),
				'annotations'  => array(
					'readonly'        => false,
					'destructiveHint' => false,
					'idempotentHint'  => false,
				),
				'show_in_rest' => true,
			),
		);
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function execute( array $input ) {
		return ( new MediaUploader() )->upload( $input );
	}

	/**
	 * Never logs the base64 payload, only its size.
	 *
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>
	 */
	public static function summarize( array $input ): array {
		$summary = array();
		foreach ( array( 'url', 'path', 'filename', 'post_id' ) as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$summary[ $key ] = $input[ $key ];
			}
		}
		if ( isset( $input['base64'] ) ) {
			$summary['base64_bytes'] = strlen( (string) $input['base64'] );
		}
		return $summary;
	}
}
