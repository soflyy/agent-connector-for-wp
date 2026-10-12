<?php
/**
 * The "Ability Packs" tab on Agent Connector's Abilities page.
 *
 * @package AgentConnectorForWpDefaultAbilities
 */

declare( strict_types=1 );

namespace AgentConnectorForWp\DefaultAbilities\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Enqueues admin/build/packs.js on Agent Connector's screen. The script adds the
 * tab through Agent Connector's `agentConnector.abilities.tabs` JS filter; the
 * tab talks to Rest\DirectoryController.
 */
final class AbilityPacksTab {

	public function register(): void {
		add_action( 'agent_connector_for_wp_enqueue_admin_assets', array( $this, 'enqueue' ) );
	}

	public function enqueue(): void {
		$js = AGENT_CONNECTOR_DEFAULT_ABILITIES_DIR . 'admin/build/packs.js';
		if ( ! file_exists( $js ) ) {
			return;
		}

		$version = AGENT_CONNECTOR_DEFAULT_ABILITIES_VERSION . '.' . filemtime( $js );

		wp_enqueue_script(
			'universal-abilities-packs',
			plugins_url( 'admin/build/packs.js', AGENT_CONNECTOR_DEFAULT_ABILITIES_FILE ),
			array( 'react', 'react-jsx-runtime', 'wp-hooks', 'wp-api-fetch' ),
			$version,
			true
		);

		if ( file_exists( AGENT_CONNECTOR_DEFAULT_ABILITIES_DIR . 'admin/build/packs.css' ) ) {
			wp_enqueue_style(
				'universal-abilities-packs',
				plugins_url( 'admin/build/packs.css', AGENT_CONNECTOR_DEFAULT_ABILITIES_FILE ),
				array(),
				$version
			);
		}
	}
}
