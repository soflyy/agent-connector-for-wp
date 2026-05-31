<?php
/**
 * Ability: agent-connector-for-wp/file-write
 *
 * @package AgentConnectorForWp
 */

declare( strict_types=1 );

namespace AgentConnectorForWp\Abilities;

use AgentConnectorForWp\Services\AuditLogger;
use AgentConnectorForWp\Services\FileManager;
use AgentConnectorForWp\Support\Config;

defined( 'ABSPATH' ) || exit;

final class FileWriteAbility {

	public const NAME = 'agent-connector-for-wp/file-write';

	public static function is_allowed(): bool {
		return true;
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function definition(): array {
		return array(
			'label'               => 'Write File',
			'description'         => 'Write a file to the server, creating parent directories as needed. Binary-safe when contents are base64-encoded. Can append instead of overwriting.',
			'category'            => 'agent-connector-for-wp',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'path'     => array(
						'type'        => 'string',
						'description' => 'Destination path. Relative paths resolve against ABSPATH.',
					),
					'contents' => array(
						'type'        => 'string',
						'description' => 'File contents. Treated as text unless encoding is "base64".',
					),
					'encoding' => array(
						'type'        => 'string',
						'enum'        => array( 'utf8', 'base64' ),
						'description' => '"base64" decodes contents before writing (binary-safe). Defaults to "utf8".',
					),
					'append'   => array(
						'type'        => 'boolean',
						'description' => 'Append instead of overwriting. Defaults to false.',
					),
				),
				'required'             => array( 'path', 'contents' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'path'          => array( 'type' => 'string' ),
					'bytes_written' => array( 'type' => 'integer' ),
					'created'       => array( 'type' => 'boolean', 'description' => 'True if the file did not previously exist.' ),
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
		$path     = isset( $input['path'] ) ? (string) $input['path'] : '';
		$contents = isset( $input['contents'] ) ? (string) $input['contents'] : '';
		$encoding = isset( $input['encoding'] ) ? (string) $input['encoding'] : 'utf8';
		$append   = ! empty( $input['append'] );
		return ( new FileManager() )->write( $path, $contents, $encoding, $append );
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>
	 */
	public static function summarize( array $input ): array {
		return array(
			'path'         => isset( $input['path'] ) ? (string) $input['path'] : '',
			'bytes'        => isset( $input['contents'] ) ? strlen( (string) $input['contents'] ) : 0,
			'append'       => ! empty( $input['append'] ),
		);
	}
}
