<?php
/**
 * Ability: agent-connector-for-wp/plugin-update
 *
 * @package AgentConnectorForWp
 */

declare( strict_types=1 );

namespace AgentConnectorForWp\UniversalAbilities\Abilities;

use AgentConnectorForWp\Services\AuditLogger;
use AgentConnectorForWp\Support\Config;
use AgentConnectorForWp\UniversalAbilities\Services\PluginManager;

defined( 'ABSPATH' ) || exit;

final class PluginUpdateAbility {

	public const NAME = 'agent-connector-for-wp/plugin-update';

	public static function is_allowed(): bool {
		return true;
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function definition(): array {
		return array(
			'label'               => 'Update Plugin',
			'description'         => 'Update a plugin to its latest version, or every plugin with an update when plugin is "all". Agent Connector and MCP Adapter are skipped: update those from wp-admin.',
			'category'            => 'agent-connector-for-wp',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'plugin' => array(
						'type'        => 'string',
						'description' => 'Plugin slug or file, or "all".',
					),
				),
				'required'             => array( 'plugin' ),
				'additionalProperties' => false,
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
				'annotations'  => array( 'readonly' => false, 'destructive' => true, 'idempotent' => false ),
				'show_in_rest' => true,
			),
		);
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function execute( array $input ) {
		return ( new PluginManager() )->update( (string) ( $input['plugin'] ?? '' ) );
	}

	/**
	 * The input is small and holds no secrets, so it is logged as is.
	 *
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>
	 */
	public static function summarize( array $input ): array {
		return $input;
	}
}
