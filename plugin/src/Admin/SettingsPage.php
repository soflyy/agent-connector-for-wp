<?php
/**
 * Admin menu owner + "Settings" page (enable toggle and domain lock).
 *
 * @package AgentConnectorForWp
 */

declare( strict_types=1 );

namespace AgentConnectorForWp\Admin;

use AgentConnectorForWp\Support\Config;

defined( 'ABSPATH' ) || exit;

/**
 * The Settings screen, and the owner of the top-level admin menu.
 *
 * This page is registered *unconditionally* (even when the plugin is switched
 * off), because it is the only place an operator can switch it on. Everything
 * dangerous stays gated behind Config::can_boot(); this screen just flips the
 * switch and manages the domain lock.
 *
 * Form submissions go through admin-post.php (admin_post_* actions) so we can
 * validate, mutate, and redirect with a status — no Settings API, matching the
 * plugin's existing manual-nonce style.
 */
final class SettingsPage {

	public const MENU_SLUG = 'agent-connector-for-wp';

	private const SAVE_ACTION      = 'agent_connector_for_wp_save_settings';
	private const RECONNECT_ACTION = 'agent_connector_for_wp_reconnect';

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_post_' . self::SAVE_ACTION, array( $this, 'handle_save' ) );
		add_action( 'admin_post_' . self::RECONNECT_ACTION, array( $this, 'handle_reconnect' ) );
	}

	/**
	 * Register the top-level menu and the Settings page. ConnectPage adds its own
	 * submenu under this slug when the plugin is booted.
	 */
	public function register_menu(): void {
		add_menu_page(
			__( 'Agent Connector for WP', 'agent-connector-for-wp' ),
			__( 'Agent Connector for WP', 'agent-connector-for-wp' ),
			Config::CAP,
			self::MENU_SLUG,
			array( $this, 'render_page' ),
			'dashicons-superhero-alt',
			81
		);

		// Rename the auto-created first submenu item to "Settings".
		add_submenu_page(
			self::MENU_SLUG,
			__( 'Agent Connector Settings', 'agent-connector-for-wp' ),
			__( 'Settings', 'agent-connector-for-wp' ),
			Config::CAP,
			self::MENU_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Render the Settings screen.
	 */
	public function render_page(): void {
		if ( ! current_user_can( Config::CAP ) ) {
			return;
		}

		$enabled        = Config::is_enabled();
		$locked_host    = Config::locked_host();
		$declared_host  = Config::declared_host();
		$lock_mismatch  = $enabled && '' !== $locked_host && $locked_host !== $declared_host;
		$notice         = isset( $_GET['acfw_notice'] ) ? sanitize_key( wp_unslash( $_GET['acfw_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Agent Connector for WP — Settings', 'agent-connector-for-wp' ); ?></h1>

			<?php $this->render_status_notice( $notice ); ?>

			<div class="notice notice-error inline" style="max-width:48em;">
				<p>
					<strong><?php esc_html_e( 'Danger:', 'agent-connector-for-wp' ); ?></strong>
					<?php esc_html_e( 'When enabled, this plugin grants root-equivalent control of this server — arbitrary shell commands, PHP evaluation, and filesystem access — to anyone holding a WordPress application password, and to the agents acting for them. There is no sandbox. Enable it only on trusted local, development, or staging environments. Never on production.', 'agent-connector-for-wp' ); ?>
				</p>
			</div>

			<h2><?php esc_html_e( 'Status', 'agent-connector-for-wp' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Abilities', 'agent-connector-for-wp' ); ?></th>
					<td>
						<?php if ( $enabled ) : ?>
							<span style="color:#996800;font-weight:600;"><?php esc_html_e( 'ENABLED', 'agent-connector-for-wp' ); ?></span>
						<?php else : ?>
							<span style="color:#1a7f37;font-weight:600;"><?php esc_html_e( 'Disabled', 'agent-connector-for-wp' ); ?></span>
							<p class="description"><?php esc_html_e( 'The plugin is inert. No abilities are registered and the MCP endpoint exposes nothing from this plugin.', 'agent-connector-for-wp' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
			</table>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::SAVE_ACTION ); ?>" />
				<?php wp_nonce_field( self::SAVE_ACTION ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Enable Agent Connector', 'agent-connector-for-wp' ); ?></th>
						<td>
							<label>
								<input
									type="checkbox"
									name="acfw_enabled"
									value="1"
									<?php checked( $enabled ); ?>
								/>
								<?php esc_html_e( 'Allow agents to run shell, PHP, filesystem, WP-CLI, and admin-login abilities on this site.', 'agent-connector-for-wp' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'Enabling also locks the plugin to this site\'s current domain (see below). Turn this off to make the plugin completely inert again.', 'agent-connector-for-wp' ); ?>
							</p>
						</td>
					</tr>
				</table>
				<?php submit_button( $enabled ? __( 'Save changes', 'agent-connector-for-wp' ) : __( 'Enable plugin', 'agent-connector-for-wp' ) ); ?>
			</form>

			<?php if ( $enabled ) : ?>
				<hr />
				<h2><?php esc_html_e( 'Domain lock', 'agent-connector-for-wp' ); ?></h2>
				<p style="max-width:48em;">
					<?php esc_html_e( 'Agent Connector only runs on the domain it was enabled on. If this site is cloned or moved to another domain, abilities are blocked until an administrator reconnects here — so a copied database (and its application passwords) can\'t silently grant access on a different site.', 'agent-connector-for-wp' ); ?>
				</p>

				<?php if ( $lock_mismatch ) : ?>
					<div class="notice notice-error inline" style="max-width:48em;">
						<p>
							<strong><?php esc_html_e( 'Domain mismatch — abilities are blocked.', 'agent-connector-for-wp' ); ?></strong>
							<?php
							printf(
								/* translators: 1: locked host, 2: current host. */
								esc_html__( 'Locked to %1$s, but this site now reports %2$s. Click "Reconnect to this domain" to allow abilities here.', 'agent-connector-for-wp' ),
								'<code>' . esc_html( $locked_host ) . '</code>', // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
								'<code>' . esc_html( '' === $declared_host ? '(unknown)' : $declared_host ) . '</code>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
							);
							?>
						</p>
					</div>
				<?php endif; ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Locked to', 'agent-connector-for-wp' ); ?></th>
						<td><code><?php echo esc_html( '' === $locked_host ? __( '(not locked)', 'agent-connector-for-wp' ) : $locked_host ); ?></code></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'This site reports', 'agent-connector-for-wp' ); ?></th>
						<td><code><?php echo esc_html( '' === $declared_host ? __( '(unknown)', 'agent-connector-for-wp' ) : $declared_host ); ?></code></td>
					</tr>
				</table>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::RECONNECT_ACTION ); ?>" />
					<?php wp_nonce_field( self::RECONNECT_ACTION ); ?>
					<?php
					submit_button(
						__( 'Reconnect to this domain', 'agent-connector-for-wp' ),
						$lock_mismatch ? 'primary' : 'secondary',
						'submit',
						false
					);
					?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render the post-redirect status notice.
	 */
	private function render_status_notice( string $notice ): void {
		$messages = array(
			'enabled'    => array( 'success', __( 'Agent Connector is now enabled and locked to this domain.', 'agent-connector-for-wp' ) ),
			'disabled'   => array( 'success', __( 'Agent Connector is now disabled. The plugin is inert.', 'agent-connector-for-wp' ) ),
			'saved'      => array( 'success', __( 'Settings saved.', 'agent-connector-for-wp' ) ),
			'reconnected' => array( 'success', __( 'Reconnected — abilities are allowed on this domain again.', 'agent-connector-for-wp' ) ),
		);

		if ( ! isset( $messages[ $notice ] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			esc_attr( $messages[ $notice ][0] ),
			esc_html( $messages[ $notice ][1] )
		);
	}

	/**
	 * admin-post: persist the enable toggle (and lock the domain on enable).
	 */
	public function handle_save(): void {
		$this->verify( self::SAVE_ACTION );

		$was_enabled = Config::is_enabled();
		$enable      = isset( $_POST['acfw_enabled'] ) && '1' === $_POST['acfw_enabled']; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		update_option( Config::ENABLED_OPTION, $enable, true );

		if ( $enable ) {
			// (Re)lock to the current domain whenever we transition to enabled.
			if ( ! $was_enabled ) {
				Config::lock_to_current_host();
			}
			$this->redirect( $was_enabled ? 'saved' : 'enabled' );
		}

		$this->redirect( 'disabled' );
	}

	/**
	 * admin-post: re-pin the domain lock to the current host.
	 */
	public function handle_reconnect(): void {
		$this->verify( self::RECONNECT_ACTION );
		Config::lock_to_current_host();
		$this->redirect( 'reconnected' );
	}

	/**
	 * Shared cap + nonce check for the admin-post handlers.
	 */
	private function verify( string $action ): void {
		if ( ! current_user_can( Config::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'agent-connector-for-wp' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( $action );
	}

	/**
	 * Redirect back to this page with a status notice.
	 */
	private function redirect( string $notice ): void {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => self::MENU_SLUG,
					'acfw_notice' => $notice,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
