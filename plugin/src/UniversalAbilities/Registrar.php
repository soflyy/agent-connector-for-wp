<?php
/**
 * Registers the built-in Universal Abilities.
 *
 * @package AgentConnectorForWp
 */

declare( strict_types=1 );

namespace AgentConnectorForWp\UniversalAbilities;

use AgentConnectorForWp\UniversalAbilities\Abilities\AdminLoginAbility;
use AgentConnectorForWp\UniversalAbilities\Abilities\EnvInspectAbility;
use AgentConnectorForWp\UniversalAbilities\Abilities\FileDeleteAbility;
use AgentConnectorForWp\UniversalAbilities\Abilities\FileListAbility;
use AgentConnectorForWp\UniversalAbilities\Abilities\FileReadAbility;
use AgentConnectorForWp\UniversalAbilities\Abilities\FileWriteAbility;
use AgentConnectorForWp\UniversalAbilities\Abilities\SearchMediaAbility;
use AgentConnectorForWp\UniversalAbilities\Services\AdminLoginLink;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the Universal Abilities into the WordPress Abilities API. They are
 * ordinary abilities: the MCP Adapter surfaces them, and Agent Connector's
 * governance (super-admin check, audit log, domain lock) applies to them like
 * any other.
 *
 * Registered only while the "Universal Abilities" switch in Settings is on
 * (Support\Config::universal_abilities_enabled()).
 *
 * The abilities that run shell commands or evaluate PHP ship only in the GitHub
 * build: they live under src/GitHub/ and are listed between the @github-build
 * markers below, both of which bin/build-plugin-zip.sh removes from the
 * wordpress.org build.
 */
final class Registrar {

	/**
	 * The ability category every Universal Ability belongs to.
	 */
	public const CATEGORY = 'agent-connector-for-wp';

	/**
	 * Ability classes, in display order. Each exposes NAME, is_allowed() and
	 * definition().
	 *
	 * @var array<int,class-string>
	 */
	private const ABILITIES = array(
		EnvInspectAbility::class,
		// @github-build-start
		\AgentConnectorForWp\GitHub\Abilities\ShellAbility::class,
		\AgentConnectorForWp\GitHub\Abilities\ProcessExecAbility::class,
		\AgentConnectorForWp\GitHub\Abilities\WpCliAbility::class,
		\AgentConnectorForWp\GitHub\Abilities\PhpEvalAbility::class,
		// @github-build-end
		FileReadAbility::class,
		FileWriteAbility::class,
		FileListAbility::class,
		FileDeleteAbility::class,
		SearchMediaAbility::class,
		AdminLoginAbility::class,
	);

	/**
	 * What the Universal Abilities let an agent do in this build, for the
	 * Settings screen: a one-line description and the confirmation dialog's
	 * list. Built here rather than in the admin app so the wordpress.org build
	 * never mentions abilities it doesn't ship.
	 *
	 * @return array{description:string,items:array<int,array{title:string,detail:string}>}
	 */
	public static function summary(): array {
		$parts = array();
		$items = array();

		// @github-build-start
		$parts[] = 'shell';
		$parts[] = 'PHP eval';
		$parts[] = 'WP-CLI';
		$items[] = array( 'title' => 'Run arbitrary shell commands', 'detail' => 'Full command-line access to the server' );
		$items[] = array( 'title' => 'Evaluate PHP code', 'detail' => 'Execute any PHP in the WordPress context' );
		$items[] = array( 'title' => 'Run WP-CLI commands', 'detail' => 'Any wp-cli command on your install' );
		// @github-build-end

		$parts = array_merge( $parts, array( 'filesystem', 'environment', 'media search', 'admin login link' ) );
		$items = array_merge(
			$items,
			array(
				array( 'title' => 'Read, write, and delete files', 'detail' => "Access to the server's filesystem" ),
				array( 'title' => 'Inspect the environment', 'detail' => 'Versions, paths, active plugins and theme' ),
				array( 'title' => 'Search the media library', 'detail' => 'Find attachments and their URLs' ),
				array( 'title' => 'Create one-time admin login links', 'detail' => 'Let your agent log into your wp-admin' ),
			)
		);

		$last        = array_pop( $parts );
		$description = ucfirst( implode( ', ', $parts ) . ', and ' . $last ) . ' — exposed over MCP.';

		return array(
			'description' => $description,
			'items'       => $items,
		);
	}

	public function register(): void {
		add_action( 'wp_abilities_api_categories_init', array( $this, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );

		// Redeem one-time admin login links. Hooked on the front end too — the
		// browser opening the link is logged out, so it won't reach wp-admin yet.
		add_action( 'init', array( AdminLoginLink::class, 'maybe_consume' ) );
	}

	/**
	 * Register the category that groups the Universal Abilities.
	 */
	public function register_category(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}
		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => 'Agent Connector',
				'description' => 'Built-in operational abilities (filesystem, environment, media, admin login) for trusted agents.',
			)
		);
	}

	/**
	 * Register each allowed ability with the Abilities API.
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
