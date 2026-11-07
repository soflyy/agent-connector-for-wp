<?php
/**
 * Hand-over from the old standalone Universal Abilities plugin.
 *
 * @package AgentConnectorForWp
 */

declare( strict_types=1 );

namespace AgentConnectorForWp\UniversalAbilities;

use AgentConnectorForWp\Services\CompanionPlugins;
use AgentConnectorForWp\Support\Config;

defined( 'ABSPATH' ) || exit;

/**
 * Universal Abilities used to be a separate plugin ("Universal Abilities for
 * Agent Connector"). It is built in now, so a site that still has the old
 * plugin active would register every ability twice.
 *
 * While the old plugin is loaded, the built-in copy stands down
 * (is_loaded()). The next time an administrator opens wp-admin, the old plugin
 * is deactivated, the built-in Universal Abilities switch is turned on (the
 * site had opted in by activating the old plugin), and a one-time notice says
 * the old plugin can be deleted.
 */
final class LegacyPlugin {

	/**
	 * Option flagging that the hand-over happened and the notice is due.
	 */
	private const NOTICE_OPTION = 'agent_connector_for_wp_universal_abilities_migrated';

	/**
	 * Whether the old plugin's code is loaded on this request (it defines this
	 * constant from its main file).
	 */
	public static function is_loaded(): bool {
		return defined( 'AGENT_CONNECTOR_DEFAULT_ABILITIES_VERSION' );
	}

	public function register(): void {
		add_action( 'admin_init', array( $this, 'maybe_take_over' ) );
		add_action( 'admin_notices', array( $this, 'render_notice' ) );
	}

	/**
	 * Deactivate the old plugin and switch the built-in abilities on.
	 */
	public function maybe_take_over(): void {
		// An active old plugin always defines its constant, so this cheap check
		// skips the plugin scan below on nearly every request.
		if ( ! self::is_loaded() || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		$file = CompanionPlugins::universal_abilities_file();
		if ( null === $file || ! CompanionPlugins::is_universal_abilities_active() ) {
			return;
		}

		$network_wide = is_multisite() && is_plugin_active_for_network( $file );
		deactivate_plugins( $file, true, $network_wide );

		update_option( Config::UNIVERSAL_ABILITIES_OPTION, true, true );
		update_option( self::NOTICE_OPTION, true, false );
	}

	/**
	 * One-time notice after the hand-over.
	 */
	public function render_notice(): void {
		if ( ! get_option( self::NOTICE_OPTION ) || ! current_user_can( Config::CAP ) ) {
			return;
		}
		delete_option( self::NOTICE_OPTION );

		$settings_url = admin_url( 'admin.php?page=' . \AgentConnectorForWp\Admin\ConnectionPage::MENU_SLUG . '#/settings' );
		?>
		<div class="notice notice-success is-dismissible">
			<p>
				<?php esc_html_e( 'Universal Abilities is now built into Agent Connector. The separate Universal Abilities plugin was deactivated and can be deleted.', 'agent-connector' ); ?>
				<a href="<?php echo esc_url( $settings_url ); ?>"><?php esc_html_e( 'Manage Universal Abilities', 'agent-connector' ); ?></a>
			</p>
		</div>
		<?php
	}
}
