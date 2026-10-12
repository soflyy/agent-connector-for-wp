<?php
/**
 * GitHub-build-only bootstrap: self-updates from GitHub, plus compatibility for
 * sites upgrading from versions that bundled features which now live in the
 * Universal Abilities plugin.
 *
 * This whole directory is excluded from the wordpress.org build (.distignore),
 * so nothing here ships there. Loaded from the end of the main plugin file.
 *
 * @package AgentConnectorForWp
 */

declare( strict_types=1 );

namespace AgentConnectorForWp\Legacy;

defined( 'ABSPATH' ) || exit;

/**
 * Self-update from GitHub Releases via yahnis-elsts/plugin-update-checker.
 *
 * Sites that installed the GitHub zip keep updating from GitHub Releases; each
 * release is the same version and code as the wordpress.org release, plus this
 * directory. A site that takes the wordpress.org copy instead loses this file
 * and updates from wordpress.org from then on.
 *
 * IMPORTANT — release assets, not the source tarball:
 * enableReleaseAssets() makes the checker install the built ZIP attached to each
 * GitHub Release (which bundles vendor/) rather than GitHub's auto-generated
 * source tarball. The source tarball would be BROKEN as an update because
 * vendor/ is gitignored, so it ships without the Jetpack autoloader or the MCP
 * adapter and the plugin would fatal on load. The release ZIP is produced by
 * .github/workflows/auto-release.yml / release.yml.
 *
 * The repo is PUBLIC — the checker needs no authentication.
 *
 * Guarded to admin context: update checks only need to run in wp-admin, never on
 * every front-end request.
 */
add_action(
	'admin_init',
	static function (): void {
		$factory = '\\YahnisElsts\\PluginUpdateChecker\\v5\\PucFactory';
		if ( ! class_exists( $factory ) ) {
			// vendor/ missing (e.g. a source checkout without `composer install`).
			return;
		}

		$update_checker = $factory::buildUpdateChecker(
			'https://github.com/soflyy/agent-connector-for-wp/',
			AGENT_CONNECTOR_FOR_WP_FILE,
			'agent-connector-for-wp'
		);

		// Default branch holding the stable tags/releases.
		$update_checker->setBranch( 'master' );

		// Install the built release ZIP (with bundled vendor/) attached to each
		// GitHub Release — NOT the source tarball, which lacks vendor/.
		$update_checker->getVcsApi()->enableReleaseAssets();
	}
);

/**
 * The classes that used to live in this plugin and now live in Universal
 * Abilities, kept here (unchanged, original namespaces) for sites that haven't
 * updated Universal Abilities yet:
 *
 * - Support\Helpers and Support\Sandbox — older Universal Abilities builds call
 *   them from their file and shell abilities.
 * - Services\SandboxLoader — runs the sandbox's AI-written PHP files.
 * - Services\PluginDirectory + Services\PackUpdater — keep Universal Abilities
 *   and installed ability packs updating from GitHub. Older Universal
 *   Abilities builds have no updater of their own, so without this they could
 *   never receive the build that does.
 */
spl_autoload_register(
	static function ( string $class ): void {
		$map = array(
			'AgentConnectorForWp\\Support\\Helpers'          => 'Helpers.php',
			'AgentConnectorForWp\\Support\\Sandbox'          => 'Sandbox.php',
			'AgentConnectorForWp\\Services\\SandboxLoader'   => 'SandboxLoader.php',
			'AgentConnectorForWp\\Services\\PluginDirectory' => 'PluginDirectory.php',
			'AgentConnectorForWp\\Services\\PackUpdater'     => 'PackUpdater.php',
		);
		if ( isset( $map[ $class ] ) ) {
			require __DIR__ . '/src/' . $map[ $class ];
		}
	}
);

/**
 * Run the sandbox loader and the updater exactly as before — unless a
 * Universal Abilities build that does both itself is active
 * (AGENT_CONNECTOR_DEFAULT_ABILITIES_STANDALONE), so nothing runs twice.
 *
 * Hooked after the main boot (same priority, registered later) and before
 * Universal Abilities boots (priority 11), matching the old timing.
 */
add_action(
	'plugins_loaded',
	static function (): void {
		if ( defined( 'AGENT_CONNECTOR_DEFAULT_ABILITIES_STANDALONE' ) ) {
			return;
		}

		( new \AgentConnectorForWp\Services\PackUpdater() )->register();

		if ( \AgentConnectorForWp\Support\Config::can_boot() ) {
			( new \AgentConnectorForWp\Services\SandboxLoader() )->run();
		}
	}
);
