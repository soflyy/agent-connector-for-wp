<?php
/**
 * GitHub self-updater — ships ONLY in the GitHub build.
 *
 * Agent Connector is built twice from the same source and the same version
 * (see bin/build-plugin-zip.sh):
 *
 *   - github: the GitHub Release asset. Includes this file and the
 *             yahnis-elsts/plugin-update-checker dependency, so sites that
 *             installed from GitHub keep updating from GitHub Releases.
 *   - org:    the wordpress.org build. Identical except this file and the
 *             update-checker dependency are removed; wordpress.org delivers
 *             its updates.
 *
 * The main plugin file requires this file only when it exists, so deleting it
 * is the whole difference between the two builds.
 *
 * IMPORTANT — release assets, not the source tarball:
 * enableReleaseAssets() makes the checker install the built ZIP attached to each
 * GitHub Release (which bundles vendor/) rather than GitHub's auto-generated
 * source tarball. The source tarball would be BROKEN as an update because
 * vendor/ is gitignored, so it ships without the Composer autoloader or the
 * update checker and updates would stop flowing. The release ZIP is produced by
 * .github/workflows/auto-release.yml / release.yml, and must be the ONLY asset
 * on the release (the checker installs the first one it finds).
 *
 * The library is loaded through the Composer autoloader with the rest of
 * vendor/: its loader (load-v5p7.php) is registered in the autoloader's filemap,
 * so YahnisElsts\PluginUpdateChecker\v5\PucFactory is available once vendor/ is
 * present — no separate require needed here.
 *
 * The repo is PUBLIC — the checker needs no authentication.
 *
 * Guarded to admin context: update checks only need to run in wp-admin, never on
 * every front-end request.
 *
 * @package AgentConnectorForWp
 */

declare( strict_types=1 );

namespace AgentConnectorForWp;

defined( 'ABSPATH' ) || exit;

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
