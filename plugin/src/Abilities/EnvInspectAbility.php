<?php
/**
 * Ability: agent-connector-for-wp/env-inspect
 *
 * @package AgentConnectorForWp
 */

declare( strict_types=1 );

namespace AgentConnectorForWp\Abilities;

use AgentConnectorForWp\Services\AuditLogger;
use AgentConnectorForWp\Services\EnvInspector;
use AgentConnectorForWp\Support\Config;

defined( 'ABSPATH' ) || exit;

final class EnvInspectAbility {

	public const NAME = 'agent-connector-for-wp/env-inspect';

	public static function is_allowed(): bool {
		return true;
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function definition(): array {
		return array(
			'label'               => 'Inspect Environment',
			'description'         => 'Return structured metadata about the WordPress environment: versions, paths, active plugins/theme, debug state, writable-ness, and available CLI tools (wp-cli, node, npm, composer, git).',
			'category'            => 'agent-connector-for-wp',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'        => 'object',
				'description' => 'Environment metadata. See properties.',
				'properties'  => array(
					'wordpress_version' => array( 'type' => 'string' ),
					'php_version'       => array( 'type' => 'string' ),
					'environment_type'  => array( 'type' => 'string' ),
					'wp_debug'          => array( 'type' => 'boolean' ),
					'is_multisite'      => array( 'type' => 'boolean' ),
					'abspath'           => array( 'type' => 'string' ),
					'wp_content_dir'    => array( 'type' => 'string' ),
					'uploads_dir'       => array( 'type' => 'string' ),
					'home_url'          => array( 'type' => 'string' ),
					'site_url'          => array( 'type' => 'string' ),
					'active_theme'      => array( 'type' => 'object' ),
					'active_plugins'    => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
					'tools'             => array( 'type' => 'object' ),
					'os'                => array( 'type' => 'object' ),
					'permissions'       => array( 'type' => 'object' ),
				),
			),
			'execute_callback'    => AuditLogger::wrap(
				self::NAME,
				array( self::class, 'execute' ),
				null
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
	 * @return array<string,mixed>
	 */
	public static function execute( array $input ): array {
		unset( $input );
		return ( new EnvInspector() )->inspect();
	}
}
