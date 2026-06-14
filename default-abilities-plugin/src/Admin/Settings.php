<?php
/**
 * Injects the "Built-in abilities" status, settings toggle, save logic, and
 * connection warnings into Agent Connector for WP's Connection screen.
 *
 * @package AgentConnectorForWpDefaultAbilities
 */

declare( strict_types=1 );

namespace AgentConnectorForWp\DefaultAbilities\Admin;

use AgentConnectorForWp\Support\Config;

defined( 'ABSPATH' ) || exit;

/**
 * Owner of the "Built-in abilities" opt-in.
 *
 * Everything the main plugin used to render and persist for its own powerful
 * abilities now lives here and is hooked back onto the Connection screen through
 * the action/filter injection points the main plugin exposes:
 *
 *   - agent_connector_for_wp_render_status_rows   → the Status table row
 *   - agent_connector_for_wp_render_settings_rows → the toggle + warning row
 *   - agent_connector_for_wp_settings_saved       → persist the toggle
 *   - agent_connector_for_wp_connect_heads_up     → the connection heads-up text
 *   - agent_connector_for_wp_render_connect_notices → the super-admin warning
 *
 * The main plugin renders a one-click "Install Default Abilities" prompt instead
 * whenever nothing is hooked to agent_connector_for_wp_render_settings_rows — i.e.
 * when this plugin is not active.
 */
final class Settings {

	public const TEXT_DOMAIN = 'default-abilities-plugin';

	/**
	 * Option toggling the built-in abilities. Kept under the original
	 * agent_connector_for_wp_* name so an install that already opted in keeps its
	 * setting after this functionality moved into the Default Abilities plugin.
	 */
	public const ENABLED_OPTION = 'agent_connector_for_wp_builtin_abilities';

	/**
	 * Whether the operator opted in to the built-in abilities. Default OFF.
	 */
	public static function enabled(): bool {
		return (bool) get_option( self::ENABLED_OPTION, false );
	}

	/**
	 * Whether the abilities should actually be registered/exposed: Agent Connector
	 * must be active (master gate, incl. the production override) AND the toggle on.
	 */
	public static function active(): bool {
		return class_exists( Config::class ) && Config::can_boot() && self::enabled();
	}

	/**
	 * Hook the injection points. Safe to call in admin even while inert.
	 */
	public function register(): void {
		add_action( 'agent_connector_for_wp_render_status_rows', array( $this, 'render_status_row' ) );
		add_action( 'agent_connector_for_wp_render_settings_rows', array( $this, 'render_settings_row' ) );
		add_action( 'agent_connector_for_wp_settings_saved', array( $this, 'handle_save' ) );
		add_filter( 'agent_connector_for_wp_connect_heads_up', array( $this, 'filter_connect_heads_up' ) );
		add_action( 'agent_connector_for_wp_render_connect_notices', array( $this, 'render_connect_notices' ) );
	}

	/**
	 * Status table: the "Built-in abilities" row.
	 */
	public function render_status_row(): void {
		$active  = class_exists( Config::class ) && Config::can_boot(); // MCP server running.
		$enabled = self::enabled();
		?>
		<tr>
			<th scope="row"><?php esc_html_e( 'Built-in abilities', 'default-abilities-plugin' ); ?></th>
			<td>
				<?php if ( self::active() ) : ?>
					<span style="color:#996800;font-weight:600;"><?php esc_html_e( 'ON', 'default-abilities-plugin' ); ?></span>
					<p class="description"><?php esc_html_e( 'Shell, PHP eval, filesystem, WP-CLI, env-inspect and admin-login abilities are exposed.', 'default-abilities-plugin' ); ?></p>
				<?php elseif ( $enabled && ! $active ) : ?>
					<span style="color:#646970;font-weight:600;"><?php esc_html_e( 'On, but inactive', 'default-abilities-plugin' ); ?></span>
					<p class="description"><?php esc_html_e( 'Will be exposed once the MCP server is active.', 'default-abilities-plugin' ); ?></p>
				<?php else : ?>
					<span style="color:#646970;font-weight:600;"><?php esc_html_e( 'Off', 'default-abilities-plugin' ); ?></span>
					<p class="description"><?php esc_html_e( 'The built-in abilities are not exposed.', 'default-abilities-plugin' ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Settings form: the warning + opt-in toggle row.
	 */
	public function render_settings_row(): void {
		?>
		<tr>
			<th scope="row"><?php esc_html_e( 'Built-in abilities', 'default-abilities-plugin' ); ?></th>
			<td>
				<div class="notice notice-warning inline" style="margin:0 0 .9em;max-width:46em;">
					<p>
						<strong><?php esc_html_e( 'Powerful:', 'default-abilities-plugin' ); ?></strong>
						<?php esc_html_e( "These are the Default Abilities pack's own abilities — arbitrary shell commands, PHP evaluation, filesystem read/write, WP-CLI, and a one-time admin login link. They grant admin-equivalent control of this site to anyone holding an application password. Off by default; enable only when you want an agent to have that access.", 'default-abilities-plugin' ); ?>
					</p>
				</div>
				<label>
					<input type="checkbox" name="acfw_builtin_abilities" value="1" <?php checked( self::enabled() ); ?> />
					<?php esc_html_e( 'Expose the built-in abilities over MCP.', 'default-abilities-plugin' ); ?>
				</label>
			</td>
		</tr>
		<?php
	}

	/**
	 * Persist the toggle. Fired from the main plugin's settings handler, which has
	 * already verified the nonce and capability for this POST.
	 */
	public function handle_save(): void {
		if ( ! current_user_can( Config::CAP ) ) {
			return;
		}
		$builtin = isset( $_POST['acfw_builtin_abilities'] ) && '1' === $_POST['acfw_builtin_abilities']; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		update_option( self::ENABLED_OPTION, $builtin, true );
	}

	/**
	 * Strengthen the connection heads-up when built-in abilities are live.
	 *
	 * @param string $text The default heads-up text.
	 * @return string
	 */
	public function filter_connect_heads_up( $text ): string {
		if ( self::active() ) {
			return __( 'Built-in abilities are on, so anyone holding this application password can run shell commands, evaluate PHP, and read/write files on this server as you. Treat it like an SSH key. The password is shown only once — copy it now.', 'default-abilities-plugin' );
		}
		return (string) $text;
	}

	/**
	 * Extra notice in the connection section: built-in abilities need super admin.
	 */
	public function render_connect_notices(): void {
		if ( ! self::active() ) {
			return;
		}
		$user           = wp_get_current_user();
		$is_super_admin = function_exists( 'is_super_admin' ) && $user instanceof \WP_User && is_super_admin( $user->ID );
		if ( $is_super_admin ) {
			return;
		}
		?>
		<div class="notice notice-error inline" style="max-width:48em;">
			<p><?php esc_html_e( 'Your account is not a super admin. The built-in abilities require super-admin access, so they will reject the connection. Sign in as a super admin first.', 'default-abilities-plugin' ); ?></p>
		</div>
		<?php
	}
}
