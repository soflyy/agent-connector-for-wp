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
 * The main plugin file requires this file only when it exists.
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
 * Built on every request, as the library expects: WordPress installs automatic
 * updates from WP-Cron and WP-CLI runs `wp plugin update` outside wp-admin, so
 * the checker has to be there too or neither sees new releases (issue #143).
 * It schedules its own checks, so this costs nothing on ordinary requests.
 *
 * @package AgentConnectorForWp
 */

declare( strict_types=1 );

namespace AgentConnectorForWp;

defined( 'ABSPATH' ) || exit;

// vendor/ can be missing in a source checkout without `composer install`.
if ( class_exists( \YahnisElsts\PluginUpdateChecker\v5\PucFactory::class ) ) {
	$agent_connector_for_wp_update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
		'https://github.com/soflyy/agent-connector-for-wp/',
		AGENT_CONNECTOR_FOR_WP_FILE,
		'agent-connector-for-wp'
	);

	// Default branch holding the stable tags/releases.
	$agent_connector_for_wp_update_checker->setBranch( 'master' );

	// Install the built release ZIP (with bundled vendor/) attached to each
	// GitHub Release — NOT the source tarball, which lacks vendor/.
	$agent_connector_for_wp_update_checker->getVcsApi()->enableReleaseAssets();

	unset( $agent_connector_for_wp_update_checker );
}
