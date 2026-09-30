<?php
/**
 * Ability: migration-mode/get-status
 *
 * @package MigrationModeAbilities
 */

declare( strict_types=1 );

namespace MigrationMode\Abilities\Abilities;

use MigrationMode\Abilities\Config;
use MigrationMode\Abilities\MuInstaller;

defined( 'ABSPATH' ) || exit;

final class GetStatusAbility {

	public const NAME = 'migration-mode/get-status';

	public static function definition(): array {
		return array(
			'label'               => 'Migration Mode: status',
			'description'         => 'Report the current migration-mode configuration: whether it is enabled, whether the must-use switch plugin is installed and current, the defined profiles, the managed/protected plugin sets, and the site home URL. Read this first to understand the setup.',
			'category'            => 'migration-mode',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'enabled'          => array( 'type' => 'boolean' ),
					'secret_set'       => array( 'type' => 'boolean' ),
					'mu_installed'     => array( 'type' => 'boolean' ),
					'mu_version'       => array( 'type' => 'string' ),
					'mu_bundled'       => array( 'type' => 'string' ),
					'mu_path'          => array( 'type' => 'string' ),
					'home_url'         => array( 'type' => 'string' ),
					'is_multisite'     => array( 'type' => 'boolean' ),
					'profiles'         => array( 'type' => 'object' ),
					'managed_plugins'  => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
					'protected_plugins'=> array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
				),
			),
			'execute_callback'    => array( self::class, 'execute' ),
			'annotations'         => array( 'readonly' => true ),
		);
	}

	public static function execute( array $input ): array {
		unset( $input );
		return array(
			'enabled'           => Config::enabled(),
			'secret_set'        => '' !== Config::secret(),
			'mu_installed'      => MuInstaller::is_installed(),
			'mu_version'        => MuInstaller::installed_version(),
			'mu_bundled'        => MuInstaller::bundled_version(),
			'mu_path'           => MuInstaller::target_path(),
			'home_url'          => home_url( '/' ),
			'is_multisite'      => is_multisite(),
			'profiles'          => Config::profiles(),
			'managed_plugins'   => Config::managed(),
			'protected_plugins' => Config::protected_plugins(),
		);
	}
}
