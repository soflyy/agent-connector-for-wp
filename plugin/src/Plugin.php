<?php
/**
 * Plugin orchestrator: registers the ability category and the enabled abilities.
 *
 * @package AgentConnectorForWp
 */

declare( strict_types=1 );

namespace AgentConnectorForWp;

use AgentConnectorForWp\Abilities\AdminLoginAbility;
use AgentConnectorForWp\Abilities\EnvInspectAbility;
use AgentConnectorForWp\Abilities\FileDeleteAbility;
use AgentConnectorForWp\Abilities\FileListAbility;
use AgentConnectorForWp\Abilities\FileReadAbility;
use AgentConnectorForWp\Abilities\FileWriteAbility;
use AgentConnectorForWp\Abilities\PhpEvalAbility;
use AgentConnectorForWp\Abilities\ProcessExecAbility;
use AgentConnectorForWp\Abilities\ShellAbility;
use AgentConnectorForWp\Abilities\WpCliAbility;
use AgentConnectorForWp\Admin\ConnectPage;
use AgentConnectorForWp\Services\AdminLoginLink;

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
		WpCliAbility::class,
		PhpEvalAbility::class,
		FileReadAbility::class,
		FileWriteAbility::class,
		FileListAbility::class,
		FileDeleteAbility::class,
		AdminLoginAbility::class,
	);

	public function register(): void {
		add_action( 'wp_abilities_api_categories_init', array( $this, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );

		// Redeem one-time admin login links. Hooked on the front end too — the
		// browser opening the link is logged out, so it won't reach wp-admin yet.
		add_action( 'init', array( AdminLoginLink::class, 'maybe_consume' ) );

		if ( is_admin() ) {
			( new ConnectPage() )->register();
		}
	}

	/**
	 * Register the category that groups every ability this plugin exposes.
	 */
	public function register_category(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}
		wp_register_ability_category(
			'agent-connector-for-wp',
			array(
				'label'       => 'Agent Connector for WP',
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
