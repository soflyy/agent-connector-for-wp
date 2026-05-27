<?php
/**
 * Plugin orchestrator: registers the ability category and the enabled abilities.
 *
 * @package RootForAgents
 */

declare( strict_types=1 );

namespace RootForAgents;

use RootForAgents\Abilities\EnvInspectAbility;
use RootForAgents\Abilities\FileDeleteAbility;
use RootForAgents\Abilities\FileListAbility;
use RootForAgents\Abilities\FileReadAbility;
use RootForAgents\Abilities\FileWriteAbility;
use RootForAgents\Abilities\PhpEvalAbility;
use RootForAgents\Abilities\ProcessExecAbility;
use RootForAgents\Abilities\ShellAbility;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin into the WordPress Abilities API. We register additional
 * abilities only — there is intentionally no custom MCP server. The installed
 * wordpress/mcp-adapter surfaces these abilities automatically.
 */
final class Plugin {

	/**
	 * Ability classes, in display order. Each exposes NAME, is_allowed(),
	 * and definition(). Per-ability gates live in is_allowed().
	 *
	 * @var array<int,class-string>
	 */
	private const ABILITIES = array(
		EnvInspectAbility::class,
		ShellAbility::class,
		ProcessExecAbility::class,
		PhpEvalAbility::class,
		FileReadAbility::class,
		FileWriteAbility::class,
		FileListAbility::class,
		FileDeleteAbility::class,
	);

	public function register(): void {
		add_action( 'wp_abilities_api_categories_init', array( $this, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
	}

	/**
	 * Register the category that groups every ability this plugin exposes.
	 */
	public function register_category(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}
		wp_register_ability_category(
			'root-for-agents',
			array(
				'label'       => 'Root for Agents',
				'description' => 'Unrestricted operational capabilities (shell, PHP eval, filesystem, process, environment) for trusted agents in development environments.',
			)
		);
	}

	/**
	 * Register each enabled ability with the Abilities API.
	 */
	public function register_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}
		foreach ( self::ABILITIES as $ability ) {
			if ( ! $ability::is_allowed() ) {
				continue;
			}
			wp_register_ability( $ability::NAME, $ability::definition() );
		}
	}
}
