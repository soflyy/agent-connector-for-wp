<?php
/**
 * Ability: agent-connector-for-wp/search-media
 *
 * @package AgentConnectorForWp
 */

declare( strict_types=1 );

namespace AgentConnectorForWp\DefaultAbilities\Abilities;

use AgentConnectorForWp\Services\AuditLogger;
use AgentConnectorForWp\DefaultAbilities\Services\MediaLibrary;
use AgentConnectorForWp\Support\Config;

defined( 'ABSPATH' ) || exit;

final class SearchMediaAbility {

	public const NAME = 'agent-connector-for-wp/search-media';

	public static function is_allowed(): bool {
		return true;
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function definition(): array {
		return array(
			'label'               => 'Search Media',
			'description'         => 'Searches the WordPress media library and returns matching attachments (id, url, alt, caption, dimensions). Use this to find real images already on the site — a logo, product photos, brand imagery — instead of hardcoding external image URLs. The returned id/url/alt can be set directly on an element image control.',
			'category'            => 'agent-connector-for-wp',
			'input_schema'        => array(
				'type'                 => 'object',
				'additionalProperties' => false,
				'properties'           => array(
					'query'     => array(
						'type'        => 'string',
						'description' => 'Optional keyword to search the title, filename, caption, and description.',
					),
					'mime_type' => array(
						'type'        => array( 'string', 'array' ),
						'items'       => array( 'type' => 'string' ),
						'default'     => 'image',
						'description' => 'Filter by MIME type (passed to WP_Query post_mime_type). Defaults to "image" (all image types). Pass a specific type like "image/png", an array like ["image/png","image/jpeg"], a top-level type like "video", or "any" to return all media.',
					),
					'limit'     => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'maximum'     => 100,
						'default'     => 20,
						'description' => 'Maximum number of items to return.',
					),
					'offset'    => array(
						'type'        => 'integer',
						'minimum'     => 0,
						'default'     => 0,
						'description' => 'Number of items to skip (for pagination).',
					),
				),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'total' => array(
						'type'        => 'integer',
						'description' => 'Total items matching the query, ignoring limit/offset.',
					),
					'items' => array(
						'type'        => 'array',
						'description' => 'The matching media attachments.',
						'items'       => array(
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
									'description' => 'Original file name.',
								),
								'mime'     => array(
									'type'        => 'string',
									'description' => 'MIME type, e.g. "image/jpeg".',
								),
								'width'    => array(
									'type'        => 'integer',
									'description' => 'Intrinsic width in pixels (0 if unknown).',
								),
								'height'   => array(
									'type'        => 'integer',
									'description' => 'Intrinsic height in pixels (0 if unknown).',
								),
							),
						),
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
				'annotations'  => array( 'readonly' => true ),
				'show_in_rest' => true,
			),
		);
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function execute( array $input ) {
		return ( new MediaLibrary() )->search( $input );
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>
	 */
	public static function summarize( array $input ): array {
		$summary = array();
		if ( isset( $input['query'] ) ) {
			$summary['query'] = (string) $input['query'];
		}
		if ( isset( $input['mime_type'] ) ) {
			$summary['mime_type'] = $input['mime_type'];
		}
		return $summary;
	}
}
