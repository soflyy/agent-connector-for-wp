<?php
/**
 * Ability: migration-mode/repair-mu
 *
 * @package MigrationModeAbilities
 */

declare( strict_types=1 );

namespace MigrationMode\Abilities\Abilities;

use MigrationMode\Abilities\Config;
use MigrationMode\Abilities\MuInstaller;

defined( 'ABSPATH' ) || exit;

final class RepairMuAbility {

	public const NAME = 'migration-mode/repair-mu';

	public static function definition(): array {
		return array(
			'label'               => 'Migration Mode: (re)install switch & rotate secret',
			'description'         => '(Re)install or update the must-use switch plugin in mu-plugins, and optionally rotate the preview secret token. Use if get-status shows the mu-plugin missing/outdated, or to invalidate previously issued preview URLs.',
			'category'            => 'migration-mode',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'rotate_secret' => array(
						'type'        => 'boolean',
						'description' => 'If true, generate a fresh secret (invalidates old preview URLs/cookies). Default false.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'mu_installed'   => array( 'type' => 'boolean' ),
					'action'         => array( 'type' => 'string' ),
					'message'        => array( 'type' => 'string' ),
					'secret_rotated' => array( 'type' => 'boolean' ),
				),
			),
			'execute_callback'    => array( self::class, 'execute' ),
			'annotations'         => array( 'readonly' => false ),
		);
	}

	public static function execute( array $input ): array {
		$rotated = false;
		if ( ! empty( $input['rotate_secret'] ) ) {
			update_option( Config::OPT_SECRET, Config::generate_secret(), false );
			$rotated = true;
		}
		$result = MuInstaller::ensure();

		return array(
			'mu_installed'   => $result['installed'],
			'action'         => $result['action'],
			'message'        => $result['message'],
			'secret_rotated' => $rotated,
		);
	}
}
