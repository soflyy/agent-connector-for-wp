<?php
/**
 * Ability: agent-connector-for-wp/file-list
 *
 * @package AgentConnectorForWp
 */

declare( strict_types=1 );

namespace AgentConnectorForWp\DefaultAbilities\Abilities;

use AgentConnectorForWp\Services\AuditLogger;
use AgentConnectorForWp\DefaultAbilities\Services\FileManager;
use AgentConnectorForWp\Support\Config;

defined( 'ABSPATH' ) || exit;

final class FileListAbility {

	public const NAME = 'agent-connector-for-wp/file-list';

	public static function is_allowed(): bool {
		return true;
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function definition(): array {
		return array(
			'label'               => 'List Directory',
			'description'         => 'List the contents of a directory, optionally recursively. Returns name, type, size, mtime, and permissions for each entry.',
			'category'            => 'agent-connector-for-wp',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'path'      => array(
						'type'        => 'string',
						'description' => 'Directory path. Relative paths resolve against ABSPATH.',
					),
					'recursive' => array(
						'type'        => 'boolean',
						'description' => 'Recurse into subdirectories. Defaults to false.',
					),
					'max_depth' => array(
						'type'        => 'integer',
						'description' => 'Maximum recursion depth when recursive is true. Defaults to 5.',
					),
				),
				'required'             => array( 'path' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'path'    => array( 'type' => 'string' ),
					'entries' => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'path'     => array( 'type' => 'string' ),
								'name'     => array( 'type' => 'string' ),
								'type'     => array( 'type' => 'string', 'enum' => array( 'file', 'dir' ) ),
								'size'     => array( 'type' => 'integer' ),
								'modified' => array( 'type' => 'integer' ),
								'perms'    => array( 'type' => 'string' ),
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
		$path      = isset( $input['path'] ) ? (string) $input['path'] : '';
		$recursive = ! empty( $input['recursive'] );
		$max_depth = isset( $input['max_depth'] ) ? (int) $input['max_depth'] : 5;
		return ( new FileManager() )->list( $path, $recursive, $max_depth );
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>
	 */
	public static function summarize( array $input ): array {
		return array(
			'path'      => isset( $input['path'] ) ? (string) $input['path'] : '',
			'recursive' => ! empty( $input['recursive'] ),
		);
	}
}
