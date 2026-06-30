<?php
/**
 * Registers the ability category and the abilities. The installed mcp-adapter
 * (Agent Connector) surfaces these over MCP automatically — no custom server.
 *
 * @package MigrationModeAbilities
 */

declare( strict_types=1 );

namespace MigrationMode\Abilities;

use MigrationMode\Abilities\Abilities\GetPreviewUrlAbility;
use MigrationMode\Abilities\Abilities\GetStatusAbility;
use MigrationMode\Abilities\Abilities\ListPluginsAbility;
use MigrationMode\Abilities\Abilities\RepairMuAbility;
use MigrationMode\Abilities\Abilities\SetProfilesAbility;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	private const ABILITIES = array(
		GetStatusAbility::class,
		ListPluginsAbility::class,
		SetProfilesAbility::class,
		GetPreviewUrlAbility::class,
		RepairMuAbility::class,
	);

	public function register(): void {
		add_action( 'wp_abilities_api_categories_init', array( $this, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
	}

	public function register_category(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}
		wp_register_ability_category(
			'migration-mode',
			array(
				'label'       => 'Migration Mode',
				'description' => 'Per-request plugin-activation switching for migrating between page builders / plugin stacks without a staging clone.',
			)
		);
	}

	public function register_abilities(): void {
		// Register through Agent Connector so it injects the permission check
		// (admin/super-admin only), domain lock, and audit log for every ability.
		// If the host is inactive, register nothing.
		if ( ! function_exists( 'agent_connector_for_wp_register_ability' ) ) {
			return;
		}
		foreach ( self::ABILITIES as $ability ) {
			agent_connector_for_wp_register_ability( $ability::NAME, $ability::definition() );
		}
	}
}
