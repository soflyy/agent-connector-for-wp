<?php
/**
 * Ability: agent-connector-for-wp/plugins-list
 *
 * @package AgentConnectorForWp
 */

declare( strict_types=1 );

namespace AgentConnectorForWp\UniversalAbilities\Abilities;

use AgentConnectorForWp\Services\AuditLogger;
use AgentConnectorForWp\Support\Config;
use AgentConnectorForWp\UniversalAbilities\Services\PluginManager;

defined( 'ABSPATH' ) || exit;

final class PluginsListAbility {

	public const NAME = 'agent-connector-for-wp/plugins-list';

	public static function is_allowed(): bool {
		return true;
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function definition(): array {
		return array(
			'label'               => 'List Plugins',
			'description'         => 'List installed plugins with their version, whether they are active, any available update and whether auto-updates are on. Protected plugins (Agent Connector and MCP Adapter) cannot be deactivated, updated or deleted over MCP.',
			'category'            => 'agent-connector-for-wp',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'status' => array(
						'type'        => 'string',
						'enum'        => array( 'all', 'active', 'inactive' ),
						'description' => 'Filter: "all" (default), "active" or "inactive".',
					),
				),
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
				'annotations'  => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
				'show_in_rest' => true,
			),
		);
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function execute( array $input ) {
		return ( new PluginManager() )->list( (string) ( $input['status'] ?? 'all' ) );
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
