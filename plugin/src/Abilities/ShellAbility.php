<?php
/**
 * Ability: agent-connector-for-wp/shell-exec
 *
 * @package AgentConnectorForWp
 */

declare( strict_types=1 );

namespace AgentConnectorForWp\Abilities;

use AgentConnectorForWp\Services\AuditLogger;
use AgentConnectorForWp\Services\ShellExecutor;
use AgentConnectorForWp\Support\Config;

defined( 'ABSPATH' ) || exit;

final class ShellAbility {

	public const NAME = 'agent-connector-for-wp/shell-exec';

	public static function is_allowed(): bool {
		return true;
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function definition(): array {
		return array(
			'label'               => 'Execute Shell Command',
			'description'         => 'Run an arbitrary shell command on the server and capture stdout, stderr, and the exit code. Supports a working directory and a timeout.',
			'category'            => 'agent-connector-for-wp',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'command'    => array(
						'type'        => 'string',
						'description' => 'The command line to execute via the system shell.',
					),
					'cwd'        => array(
						'type'        => 'string',
						'description' => 'Working directory. Relative paths resolve against the WordPress root (ABSPATH).',
					),
					'timeout_ms' => array(
						'type'        => 'integer',
						'description' => 'Wall-clock timeout in milliseconds. Defaults to the configured plugin timeout.',
					),
				),
				'required'             => array( 'command' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'stdout'          => array( 'type' => 'string' ),
					'stdout_encoding' => array( 'type' => 'string', 'enum' => array( 'utf8', 'base64' ) ),
					'stderr'          => array( 'type' => 'string' ),
					'stderr_encoding' => array( 'type' => 'string', 'enum' => array( 'utf8', 'base64' ) ),
					'exit_code'       => array( 'type' => 'integer' ),
					'duration_ms'     => array( 'type' => 'integer' ),
					'timed_out'       => array( 'type' => 'boolean' ),
					'truncated'       => array( 'type' => 'boolean' ),
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
					'openWorldHint'   => true,
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
		$command    = isset( $input['command'] ) ? (string) $input['command'] : '';
		$cwd        = isset( $input['cwd'] ) ? (string) $input['cwd'] : null;
		$timeout_ms = isset( $input['timeout_ms'] ) ? (int) $input['timeout_ms'] : null;

		return ( new ShellExecutor() )->run( $command, $cwd, $timeout_ms );
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>
	 */
	public static function summarize( array $input ): array {
		return array(
			'command' => isset( $input['command'] ) ? mb_substr( (string) $input['command'], 0, 500 ) : '',
			'cwd'     => isset( $input['cwd'] ) ? (string) $input['cwd'] : null,
		);
	}
}
