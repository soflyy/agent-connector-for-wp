<?php
/**
 * Ability: agent-connector-for-wp/php-eval
 *
 * @package AgentConnectorForWp
 */

declare( strict_types=1 );

namespace AgentConnectorForWp\DefaultAbilities\Abilities;

use AgentConnectorForWp\Services\AuditLogger;
use AgentConnectorForWp\DefaultAbilities\Services\PhpEvaluator;
use AgentConnectorForWp\Support\Config;

defined( 'ABSPATH' ) || exit;

final class PhpEvalAbility {

	public const NAME = 'agent-connector-for-wp/php-eval';

	public static function is_allowed(): bool {
		return true;
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function definition(): array {
		return array(
			'label'               => 'Evaluate PHP',
			'description'         => 'Execute arbitrary PHP inside the loaded WordPress runtime. Captures printed output, the returned value, and any thrown error. Code may use `return` to send back a value.',
			'category'            => 'agent-connector-for-wp',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'code'       => array(
						'type'        => 'string',
						'description' => 'PHP code to evaluate. WordPress is fully loaded. Use `return $value;` to return a result.',
					),
					'timeout_ms' => array(
						'type'        => 'integer',
						'description' => 'Soft time limit in milliseconds (applied via set_time_limit).',
					),
				),
				'required'             => array( 'code' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'output'          => array( 'type' => 'string', 'description' => 'Anything the code printed/echoed.' ),
					'output_encoding' => array( 'type' => 'string', 'enum' => array( 'utf8', 'base64' ) ),
					'result'          => array(
						'type'        => array( 'null', 'boolean', 'integer', 'number', 'string', 'array', 'object' ),
						'description' => 'The returned value, JSON-normalized. May be null.',
					),
					'result_type'     => array( 'type' => 'string', 'description' => 'PHP gettype() of the returned value.' ),
					'exception'       => array(
						'type'        => array( 'object', 'null' ),
						'description' => 'Thrown Throwable or captured fatal, with type/message/file/line.',
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
					'destructiveHint' => true,
					'openWorldHint'   => true,
				),
				'show_in_rest' => true,
			),
		);
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>
	 */
	public static function execute( array $input ): array {
		$code       = isset( $input['code'] ) ? (string) $input['code'] : '';
		$timeout_ms = isset( $input['timeout_ms'] ) ? (int) $input['timeout_ms'] : null;

		return ( new PhpEvaluator() )->evaluate( $code, $timeout_ms );
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>
	 */
	public static function summarize( array $input ): array {
		$code = isset( $input['code'] ) ? (string) $input['code'] : '';
		return array(
			'code_bytes'   => strlen( $code ),
			'code_preview' => mb_substr( $code, 0, 200 ),
		);
	}
}
