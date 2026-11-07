<?php
/**
 * Ability: agent-connector-for-wp/plugin-install
 *
 * @package AgentConnectorForWp
 */

declare( strict_types=1 );

namespace AgentConnectorForWp\UniversalAbilities\Abilities;

use AgentConnectorForWp\Services\AuditLogger;
use AgentConnectorForWp\Support\Config;
use AgentConnectorForWp\UniversalAbilities\Services\PluginManager;

defined( 'ABSPATH' ) || exit;

final class PluginInstallAbility {

	public const NAME = 'agent-connector-for-wp/plugin-install';

	public static function is_allowed(): bool {
		return true;
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function definition(): array {
		return array(
			'label'               => 'Install Plugin',
			'description'         => 'Install a plugin from the wordpress.org directory by slug (find it with plugins-search), optionally a specific version, and optionally activate it. Only wordpress.org plugins can be installed.',
			'category'            => 'agent-connector-for-wp',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'slug' => array(
						'type'        => 'string',
						'description' => 'The wordpress.org plugin slug.',
					),
					'version' => array(
						'type'        => 'string',
						'description' => 'A specific version to install. Defaults to the latest.',
					),
					'activate' => array(
						'type'        => 'boolean',
						'description' => 'Activate it after installing. Defaults to false.',
					),
				),
				'required'             => array( 'slug' ),
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
				'annotations'  => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ),
				'show_in_rest' => true,
			),
		);
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function execute( array $input ) {
		return ( new PluginManager() )->install( (string) ( $input['slug'] ?? '' ), (string) ( $input['version'] ?? '' ), ! empty( $input['activate'] ) );
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
