<?php
/**
 * Ability: agent-connector-for-wp/plugin-delete
 *
 * @package AgentConnectorForWp
 */

declare( strict_types=1 );

namespace AgentConnectorForWp\UniversalAbilities\Abilities;

use AgentConnectorForWp\Services\AuditLogger;
use AgentConnectorForWp\Support\Config;
use AgentConnectorForWp\UniversalAbilities\Services\PluginManager;

defined( 'ABSPATH' ) || exit;

final class PluginDeleteAbility {

	public const NAME = 'agent-connector-for-wp/plugin-delete';

	public static function is_allowed(): bool {
		return true;
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function definition(): array {
		return array(
			'label'               => 'Delete Plugin',
			'description'         => 'Delete a plugin: deactivates it if needed, runs its uninstall routine (which may remove its data) and deletes its files. Agent Connector and MCP Adapter cannot be deleted this way.',
			'category'            => 'agent-connector-for-wp',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'plugin' => array(
						'type'        => 'string',
						'description' => 'Plugin slug (e.g. "akismet") or file (e.g. "akismet/akismet.php"), as plugins-list shows it.',
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
		return ( new PluginManager() )->delete( (string) ( $input['plugin'] ?? '' ) );
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
