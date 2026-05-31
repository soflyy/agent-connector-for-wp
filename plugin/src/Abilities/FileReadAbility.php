<?php
/**
 * Ability: agent-connector-for-wp/file-read
 *
 * @package AgentConnectorForWp
 */

declare( strict_types=1 );

namespace AgentConnectorForWp\Abilities;

use AgentConnectorForWp\Services\AuditLogger;
use AgentConnectorForWp\Services\FileManager;
use AgentConnectorForWp\Support\Config;

defined( 'ABSPATH' ) || exit;

final class FileReadAbility {

	public const NAME = 'agent-connector-for-wp/file-read';

	public static function is_allowed(): bool {
		return true;
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function definition(): array {
		return array(
			'label'               => 'Read File',
			'description'         => 'Read a file from the server. Relative paths resolve against the WordPress root (ABSPATH). Binary files are returned base64-encoded.',
			'category'            => 'agent-connector-for-wp',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'path'     => array(
						'type'        => 'string',
						'description' => 'File path. Relative paths resolve against ABSPATH.',
					),
					'encoding' => array(
						'type'        => 'string',
						'enum'        => array( 'auto', 'base64' ),
						'description' => '"auto" returns UTF-8 text or base64 for binary; "base64" always base64-encodes.',
					),
				),
				'required'             => array( 'path' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'path'      => array( 'type' => 'string' ),
					'encoding'  => array( 'type' => 'string', 'enum' => array( 'utf8', 'base64' ) ),
					'contents'  => array( 'type' => 'string' ),
					'size'      => array( 'type' => 'integer' ),
					'truncated' => array( 'type' => 'boolean' ),
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
		$path     = isset( $input['path'] ) ? (string) $input['path'] : '';
		$encoding = isset( $input['encoding'] ) ? (string) $input['encoding'] : 'auto';
		return ( new FileManager() )->read( $path, $encoding );
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>
	 */
	public static function summarize( array $input ): array {
		return array( 'path' => isset( $input['path'] ) ? (string) $input['path'] : '' );
	}
}
