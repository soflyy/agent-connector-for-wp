<?php
/**
 * Ability: migration-mode/list-plugins
 *
 * @package MigrationModeAbilities
 */

declare( strict_types=1 );

namespace MigrationMode\Abilities\Abilities;

use MigrationMode\Abilities\Config;

defined( 'ABSPATH' ) || exit;

final class ListPluginsAbility {

	public const NAME = 'migration-mode/list-plugins';

	public static function definition(): array {
		return array(
			'label'               => 'Migration Mode: list installed plugins',
			'description'         => 'List every installed plugin with its plugin file (e.g. "oxygen-elements/oxygen-elements.php"), display name, version, and active state. Use the exact "file" values when defining profiles with set-profiles.',
			'category'            => 'migration-mode',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'plugins' => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'file'           => array( 'type' => 'string' ),
								'name'           => array( 'type' => 'string' ),
								'version'        => array( 'type' => 'string' ),
								'active'         => array( 'type' => 'boolean' ),
								'network_active' => array( 'type' => 'boolean' ),
							),
						),
					),
				),
			),
			'execute_callback'    => array( self::class, 'execute' ),
			'annotations'         => array( 'readonly' => true ),
		);
	}

	public static function execute( array $input ): array {
		unset( $input );
		return array( 'plugins' => array_values( Config::installed_plugins() ) );
	}
}
