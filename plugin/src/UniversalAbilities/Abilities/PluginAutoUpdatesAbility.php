<?php
/**
 * Ability: agent-connector-for-wp/plugin-auto-updates
 *
 * @package AgentConnectorForWp
 */

declare( strict_types=1 );

namespace AgentConnectorForWp\UniversalAbilities\Abilities;

use AgentConnectorForWp\Services\AuditLogger;
use AgentConnectorForWp\Support\Config;
use AgentConnectorForWp\UniversalAbilities\Services\PluginManager;

defined( 'ABSPATH' ) || exit;

final class PluginAutoUpdatesAbility {

	public const NAME = 'agent-connector-for-wp/plugin-auto-updates';

	public static function is_allowed(): bool {
		return true;
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function definition(): array {
		return array(
			'label'               => 'Set Plugin Auto-Updates',
			'description'         => 'Turn automatic updates on or off for an installed plugin.',
			'category'            => 'agent-connector-for-wp',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'plugin' => array(
						'type'        => 'string',
						'description' => 'Plugin slug (e.g. "akismet") or file (e.g. "akismet/akismet.php"), as plugins-list shows it.',
					),
					'enabled' => array(
						'type'        => 'boolean',
						'description' => 'true to turn auto-updates on, false to turn them off.',
					),
				),
				'required'             => array( 'plugin', 'enabled' ),
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
				'annotations'  => array( 'readonly' => false, 'destructive' => false, 'idempotent' => true ),
				'show_in_rest' => true,
			),
		);
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function execute( array $input ) {
		return ( new PluginManager() )->set_auto_updates( (string) ( $input['plugin'] ?? '' ), ! empty( $input['enabled'] ) );
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
