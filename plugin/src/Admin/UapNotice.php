<?php
/**
 * Site-wide "install Universal Abilities" nudge notice.
 *
 * @package AgentConnectorForWp
 */

declare( strict_types=1 );

namespace AgentConnectorForWp\Admin;

use AgentConnectorForWp\Support\Config;
use AgentConnectorForWp\Support\Helpers;

defined( 'ABSPATH' ) || exit;

/**
 * Shown on every wp-admin page while Agent Connector is running but the
 * Universal Abilities pack is not installed/active.
 *
 * Without the pack, a connected agent only has whatever abilities other
 * plugins happen to register — usually nothing — so the plugin looks broken.
 * This notice explains that and links to where the optional companion plugin
 * can be downloaded. Dismiss is site-wide: one admin dismissing hides it for
 * all.
 */
final class UapNotice {

	/** Where the optional Universal Abilities companion plugin lives. */
	public const UNIVERSAL_ABILITIES_URL = 'https://wpagentconnector.com/universal-abilities';

	public function register(): void {
		add_action( 'admin_notices', array( $this, 'render' ) );
		add_action( 'network_admin_notices', array( $this, 'render' ) );
	}

	public function render(): void {
		if (
			! current_user_can( Config::CAP )
			|| ! Config::can_boot()
			|| Config::uap_notice_hidden()
			|| Helpers::is_universal_abilities_active()
		) {
			return;
		}

		// The plugin's own app page already offers the install button.
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && false !== strpos( (string) $screen->id, ConnectionPage::MENU_SLUG ) ) {
			return;
		}

		$dismiss_url = rest_url( 'agent-connector-for-wp/v1/dismiss-uap-notice' );
		?>
		<div class="notice notice-warning" id="acfw-uap-notice">
			<p>
				<strong><?php esc_html_e( 'Your agent can\'t do much yet.', 'agent-connector-for-wp' ); ?></strong>
				<?php esc_html_e( 'Agent Connector is running, but agents only get the abilities your other plugins register. Install the optional Universal Abilities plugin to give your agent complete access to this WordPress install — shell, PHP, files, WP-CLI, and admin login.', 'agent-connector-for-wp' ); ?>
			</p>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( self::UNIVERSAL_ABILITIES_URL ); ?>" target="_blank" rel="noopener noreferrer">
					<?php esc_html_e( 'Get Universal Abilities', 'agent-connector-for-wp' ); ?>
				</a>
				<button type="button" class="button" id="acfw-uap-notice-dismiss">
					<?php esc_html_e( 'Dismiss', 'agent-connector-for-wp' ); ?>
				</button>
			</p>
		</div>
		<script>
			( function () {
				var notice  = document.getElementById( 'acfw-uap-notice' );
				var dismiss = document.getElementById( 'acfw-uap-notice-dismiss' );
				if ( ! notice || ! dismiss ) {
					return;
				}
				dismiss.addEventListener( 'click', function () {
					dismiss.disabled = true;
					fetch( <?php echo wp_json_encode( esc_url_raw( $dismiss_url ) ); ?>, {
						method: 'POST',
						headers: { 'X-WP-Nonce': <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?> },
					} ).finally( function () {
						notice.remove();
					} );
				} );
			} )();
		</script>
		<?php
	}
}
