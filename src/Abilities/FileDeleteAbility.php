<?php
/**
 * Ability: root-for-agents/file-delete
 *
 * @package RootForAgents
 */

declare( strict_types=1 );

namespace RootForAgents\Abilities;

use RootForAgents\Services\AuditLogger;
use RootForAgents\Services\FileManager;
use RootForAgents\Support\Config;

defined( 'ABSPATH' ) || exit;

final class FileDeleteAbility {

	public const NAME = 'root-for-agents/file-delete';

	public static function is_allowed(): bool {
		return true;
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function definition(): array {
		return array(
			'label'               => 'Delete File or Directory',
			'description'         => 'Delete a file, or a directory (optionally recursively). Relative paths resolve against ABSPATH.',
			'category'            => 'root-for-agents',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'path'      => array(
						'type'        => 'string',
						'description' => 'Path to delete. Relative paths resolve against ABSPATH.',
					),
					'recursive' => array(
						'type'        => 'boolean',
						'description' => 'Required to delete a non-empty directory. Defaults to false.',
					),
				),
				'required'             => array( 'path' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'path'    => array( 'type' => 'string' ),
					'deleted' => array( 'type' => 'boolean' ),
				),
			),
			'execute_callback'    => AuditLogger::wrap(
				self::NAME,
				array( self::class, 'execute' ),
				array( self::class, 'summarize' )
			),
			'permission_callback' => static function (): bool {
				return Config::has_authenticated_user();
			},
			'meta'                => array(
				'mcp'          => array( 'public' => true ),
				'annotations'  => array(
					'readonly'        => false,
					'destructiveHint' => true,
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
		$path      = isset( $input['path'] ) ? (string) $input['path'] : '';
		$recursive = ! empty( $input['recursive'] );
		return ( new FileManager() )->delete( $path, $recursive );
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
