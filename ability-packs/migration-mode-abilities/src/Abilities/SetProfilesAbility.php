<?php
/**
 * Ability: migration-mode/set-profiles
 *
 * @package MigrationModeAbilities
 */

declare( strict_types=1 );

namespace MigrationMode\Abilities\Abilities;

use MigrationMode\Abilities\Config;

defined( 'ABSPATH' ) || exit;

final class SetProfilesAbility {

	public const NAME = 'migration-mode/set-profiles';

	public static function definition(): array {
		return array(
			'label'               => 'Migration Mode: define profiles',
			'description'         => 'Define the plugin profiles the per-request switch chooses between. A profile is a named set of plugins to keep active; for a migration you typically define a "source" profile (e.g. only Elementor + its add-ons) and a "target" profile (e.g. only Oxygen + its add-ons). When a profile is requested, every plugin named across ANY profile ("managed") is turned off unless the requested profile lists it; protected plugins (Agent Connector, this tool) always stay on; all other plugins are left untouched. This REPLACES the full profile set. Use plugin "file" values from list-plugins.',
			'category'            => 'migration-mode',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'profiles' => array(
						'type'        => 'array',
						'description' => 'The complete set of profiles (replaces any existing).',
						'items'       => array(
							'type'                 => 'object',
							'properties'           => array(
								'id'     => array(
									'type'        => 'string',
									'description' => 'URL-safe profile id, e.g. "source" or "target". Reserved: "off"/"default" mean no filtering.',
									'pattern'     => '^[A-Za-z0-9_-]+$',
								),
								'label'  => array( 'type' => 'string' ),
								'active' => array(
									'type'        => 'array',
									'description' => 'Plugin files to keep active for this profile.',
									'items'       => array( 'type' => 'string' ),
								),
							),
							'required'             => array( 'id', 'active' ),
							'additionalProperties' => false,
						),
					),
					'managed_extra' => array(
						'type'        => 'array',
						'description' => 'Optional: plugin files to ALSO mark managed (turned off in every profile that does not list them) even though no profile names them — use to force a plugin off across all profiles.',
						'items'       => array( 'type' => 'string' ),
					),
					'protected' => array(
						'type'        => 'array',
						'description' => 'Optional: replace the always-on protected set. Omit to keep the current/default protected plugins.',
						'items'       => array( 'type' => 'string' ),
					),
					'enabled' => array(
						'type'        => 'boolean',
						'description' => 'Optional master switch for the whole mechanism. Defaults to true.',
					),
				),
				'required'             => array( 'profiles' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'saved'            => array( 'type' => 'boolean' ),
					'enabled'          => array( 'type' => 'boolean' ),
					'profiles'         => array( 'type' => 'object' ),
					'managed_plugins'  => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
					'protected_plugins'=> array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
					'warnings'         => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
				),
			),
			'execute_callback'    => array( self::class, 'execute' ),
			'annotations'         => array( 'readonly' => false, 'destructive' => true ),
		);
	}

	public static function execute( array $input ): array {
		$installed = Config::installed_plugins();
		$warnings  = array();

		$profiles = array();
		$managed  = array();
		foreach ( (array) ( $input['profiles'] ?? array() ) as $p ) {
			$id = isset( $p['id'] ) ? sanitize_key( (string) $p['id'] ) : '';
			if ( '' === $id || in_array( $id, array( 'off', 'default' ), true ) ) {
				$warnings[] = "Skipped profile with invalid or reserved id: '" . ( $p['id'] ?? '' ) . "'.";
				continue;
			}
			$active = array();
			foreach ( (array) ( $p['active'] ?? array() ) as $file ) {
				$file = (string) $file;
				if ( ! isset( $installed[ $file ] ) ) {
					$warnings[] = "Profile '{$id}': plugin file not installed: '{$file}' (kept anyway — install it before previewing).";
				}
				$active[] = $file;
			}
			$active             = array_values( array_unique( $active ) );
			$profiles[ $id ]    = array(
				'label'  => isset( $p['label'] ) ? sanitize_text_field( (string) $p['label'] ) : $id,
				'active' => $active,
			);
			$managed = array_merge( $managed, $active );
		}

		foreach ( (array) ( $input['managed_extra'] ?? array() ) as $file ) {
			$managed[] = (string) $file;
		}
		$managed = array_values( array_unique( $managed ) );

		update_option( Config::OPT_PROFILES, $profiles, false );
		update_option( Config::OPT_MANAGED, $managed, false );

		if ( array_key_exists( 'protected', $input ) && is_array( $input['protected'] ) ) {
			update_option( Config::OPT_PROTECTED, array_values( array_map( 'strval', $input['protected'] ) ), false );
		}

		$enabled = array_key_exists( 'enabled', $input ) ? (bool) $input['enabled'] : true;
		update_option( Config::OPT_ENABLED, $enabled, false );

		return array(
			'saved'             => true,
			'enabled'           => $enabled,
			'profiles'          => $profiles,
			'managed_plugins'   => $managed,
			'protected_plugins' => Config::protected_plugins(),
			'warnings'          => $warnings,
		);
	}
}
