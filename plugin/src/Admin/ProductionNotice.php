<?php
/**
 * Site-wide production warning notice.
 *
 * @package AgentConnectorForWp
 */

declare( strict_types=1 );

namespace AgentConnectorForWp\Admin;

use AgentConnectorForWp\Support\Config;

defined( 'ABSPATH' ) || exit;

/**
 * The red banner shown on EVERY wp-admin page while Agent Connector runs on a
 * production environment.
 *
 * This replaces the old up-front friction (the mandatory production-override
 * checkbox): instead of blocking the operator, we let the plugin work and make
 * absolutely sure they understand what it does. The notice is site-wide and
 * stays until an admin clicks "I understand" (which sets the same option as the
 * "Disable production warning" toggle under Settings → Protection) — or opts
 * into the "Block on production environments" protection, which makes the
 * warning moot.
 */
final class ProductionNotice {

	public function register(): void {
		add_action( 'admin_notices', array( $this, 'render' ) );
		add_action( 'network_admin_notices', array( $this, 'render' ) );
	}

	public function render(): void {
		if ( ! current_user_can( Config::CAP ) || ! Config::should_show_production_warning() ) {
			return;
		}

		// The plugin's own app page already communicates all of this — a second
		// banner there is noise.
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && false !== strpos( (string) $screen->id, ConnectionPage::MENU_SLUG ) ) {
			return;
		}

		$settings_url = admin_url( 'admin.php?page=' . ConnectionPage::MENU_SLUG . '#/settings' );
		$dismiss_url  = rest_url( 'agent-connector-for-wp/v1/dismiss-production-warning' );
		?>
		<div class="notice notice-error" id="acfw-production-warning">
			<p>
				<strong><?php esc_html_e( 'Agent Connector for WP is active on this site.', 'agent-connector-for-wp' ); ?></strong>
				<?php esc_html_e( 'AI agents connected to it can do anything an administrator can — run code, modify files, and change content. If that is what you want, you are all set. To restrict it, enable protections in the settings.', 'agent-connector-for-wp' ); ?>
			</p>
			<p>
				<button type="button" class="button button-primary" id="acfw-production-warning-dismiss">
					<?php esc_html_e( 'I understand, hide this warning', 'agent-connector-for-wp' ); ?>
				</button>
				<a class="button" href="<?php echo esc_url( $settings_url ); ?>">
					<?php esc_html_e( 'Protection settings', 'agent-connector-for-wp' ); ?>
				</a>
			</p>
		</div>
		<script>
			( function () {
				var btn = document.getElementById( 'acfw-production-warning-dismiss' );
				if ( ! btn ) {
					return;
				}
				btn.addEventListener( 'click', function () {
					btn.disabled = true;
					fetch( <?php echo wp_json_encode( esc_url_raw( $dismiss_url ) ); ?>, {
						method: 'POST',
						headers: { 'X-WP-Nonce': <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?> },
					} ).finally( function () {
						var notice = document.getElementById( 'acfw-production-warning' );
						if ( notice ) {
							notice.remove();
						}
					} );
				} );
			} )();
		</script>
		<?php
	}
}
