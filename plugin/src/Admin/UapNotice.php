<?php
/**
 * Site-wide "install Universal Abilities" nudge notice.
 *
 * @package AgentConnectorForWp
 */

declare( strict_types=1 );

namespace AgentConnectorForWp\Admin;

use AgentConnectorForWp\Services\PluginDirectory;
use AgentConnectorForWp\Support\Config;

defined( 'ABSPATH' ) || exit;

/**
 * Shown on every wp-admin page while Agent Connector is running but the
 * Universal Abilities pack is not installed/active.
 *
 * Without the pack, a connected agent only has whatever abilities other
 * plugins happen to register — usually nothing — so the plugin looks broken.
 * This notice explains that and offers a one-click Install & Activate (the
 * same REST route the Settings screen uses). Dismiss is site-wide: one admin
 * dismissing hides it for all.
 */
final class UapNotice {

	public function register(): void {
		add_action( 'admin_notices', array( $this, 'render' ) );
		add_action( 'network_admin_notices', array( $this, 'render' ) );
	}

	public function render(): void {
		if (
			! current_user_can( Config::CAP )
			|| ! Config::can_boot()
			|| Config::uap_notice_hidden()
			|| PluginDirectory::is_universal_abilities_active()
		) {
			return;
		}

		// The plugin's own app page already offers the install button.
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && false !== strpos( (string) $screen->id, ConnectionPage::MENU_SLUG ) ) {
			return;
		}

		$install_url = rest_url( 'agent-connector-for-wp/v1/uap/install' );
		$dismiss_url = rest_url( 'agent-connector-for-wp/v1/dismiss-uap-notice' );
		?>
		<div class="notice notice-warning" id="acfw-uap-notice">
			<p>
				<strong><?php esc_html_e( 'Your agent can\'t do much yet.', 'agent-connector-for-wp' ); ?></strong>
				<?php esc_html_e( 'Agent Connector is running, but agents only get the abilities your other plugins register. Install the Universal Abilities plugin to give your agent complete access to this WordPress install — shell, PHP, files, WP-CLI, and admin login.', 'agent-connector-for-wp' ); ?>
			</p>
			<p>
				<button type="button" class="button button-primary" id="acfw-uap-notice-install">
					<?php esc_html_e( 'Install & Activate', 'agent-connector-for-wp' ); ?>
				</button>
				<button type="button" class="button" id="acfw-uap-notice-dismiss">
					<?php esc_html_e( 'Dismiss', 'agent-connector-for-wp' ); ?>
				</button>
				<span id="acfw-uap-notice-error" style="color:#b32d2e;margin-left:8px;"></span>
			</p>
		</div>
		<script>
			( function () {
				var notice  = document.getElementById( 'acfw-uap-notice' );
				var install = document.getElementById( 'acfw-uap-notice-install' );
				var dismiss = document.getElementById( 'acfw-uap-notice-dismiss' );
				var errorEl = document.getElementById( 'acfw-uap-notice-error' );
				if ( ! notice || ! install || ! dismiss ) {
					return;
				}
				var nonce = <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?>;

				install.addEventListener( 'click', function () {
					install.disabled = true;
					dismiss.disabled = true;
					install.textContent = <?php echo wp_json_encode( __( 'Installing…', 'agent-connector-for-wp' ) ); ?>;
					errorEl.textContent = '';
					fetch( <?php echo wp_json_encode( esc_url_raw( $install_url ) ); ?>, {
						method: 'POST',
						headers: { 'X-WP-Nonce': nonce },
					} ).then( function ( res ) {
						return res.json().then( function ( data ) {
							if ( ! res.ok ) {
								throw new Error( data && data.message ? data.message : 'Install failed.' );
							}
							window.location.reload();
						} );
					} ).catch( function ( err ) {
						install.disabled = false;
						dismiss.disabled = false;
						install.textContent = <?php echo wp_json_encode( __( 'Install & Activate', 'agent-connector-for-wp' ) ); ?>;
						errorEl.textContent = err && err.message ? err.message : 'Install failed.';
					} );
				} );

				dismiss.addEventListener( 'click', function () {
					dismiss.disabled = true;
					fetch( <?php echo wp_json_encode( esc_url_raw( $dismiss_url ) ); ?>, {
						method: 'POST',
						headers: { 'X-WP-Nonce': nonce },
					} ).finally( function () {
						notice.remove();
					} );
				} );
			} )();
		</script>
		<?php
	}
}
