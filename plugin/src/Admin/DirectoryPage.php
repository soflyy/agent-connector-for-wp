<?php
/**
 * The "Ability Packs" admin screen: shows which installed plugins have an
 * available Agent Connector ability pack in the remote directory.
 *
 * @package AgentConnectorForWp
 */

declare( strict_types=1 );

namespace AgentConnectorForWp\Admin;

use AgentConnectorForWp\Services\PluginDirectory;
use AgentConnectorForWp\Support\Config;

defined( 'ABSPATH' ) || exit;

/**
 * Ability-pack directory browser + installer.
 *
 * Submenu under the top-level "Agent Connector for WP" menu. It asks the remote
 * match endpoint which installed plugins have a companion "ability pack" available
 * (cached for 12h), lists the hits, and offers one-click Install/Activate buttons
 * for packs that aren't active yet. A manual "Refresh now" button (admin-post +
 * nonce) busts the cache. All actions are gated behind the plugin's admin
 * capability plus core's install_plugins/activate_plugins, with nonces.
 */
final class DirectoryPage {

	public const MENU_SLUG = 'agent-connector-for-wp-ability-packs';

	private const REFRESH_ACTION  = 'agent_connector_for_wp_refresh_directory';
	private const INSTALL_ACTION  = 'agent_connector_for_wp_install_pack';
	private const ACTIVATE_ACTION = 'agent_connector_for_wp_activate_pack';

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_post_' . self::REFRESH_ACTION, array( $this, 'handle_refresh' ) );
		add_action( 'admin_post_' . self::INSTALL_ACTION, array( $this, 'handle_install' ) );
		add_action( 'admin_post_' . self::ACTIVATE_ACTION, array( $this, 'handle_activate' ) );
	}

	/**
	 * Add the "Ability Packs" submenu beneath the plugin's top-level menu.
	 */
	public function register_menu(): void {
		add_submenu_page(
			ConnectionPage::MENU_SLUG,
			__( 'Agent Connector — Ability Packs', 'agent-connector-for-wp' ),
			__( 'Ability Packs', 'agent-connector-for-wp' ),
			Config::CAP,
			self::MENU_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Render the Ability Packs screen.
	 */
	public function render_page(): void {
		if ( ! current_user_can( Config::CAP ) ) {
			return;
		}

		$result  = PluginDirectory::installed_overview();
		$rows    = $result['rows'];
		$notice  = isset( $_GET['acfw_notice'] ) ? sanitize_key( wp_unslash( $_GET['acfw_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Agent Connector for WP — Ability Packs', 'agent-connector-for-wp' ); ?></h1>

			<?php $this->render_notice( $notice ); ?>

			<p style="max-width:50em;">
				<?php esc_html_e( 'Plugins on this site can be extended with a companion "ability pack" that registers AI abilities through Agent Connector. This lists your installed plugins and, where one is available, lets you install it in one click. Installed packs are kept up to date automatically.', 'agent-connector-for-wp' ); ?>
			</p>

			<?php $this->render_toolbar( $result ); ?>

			<?php if ( '' !== $result['error'] ) : ?>
				<div class="notice notice-warning inline" style="max-width:50em;">
					<p><?php echo esc_html( $result['error'] ); ?></p>
				</div>
			<?php endif; ?>

			<?php $this->render_table( $rows ); ?>
		</div>
		<?php
	}

	/**
	 * Source line + manual refresh button.
	 *
	 * @param array<string,mixed> $result PluginDirectory::matches() result.
	 */
	private function render_toolbar( array $result ): void {
		?>
		<p class="description" style="max-width:50em;">
			<?php
			printf(
				/* translators: %s: directory URL. */
				esc_html__( 'Directory source: %s', 'agent-connector-for-wp' ),
				'<code>' . esc_html( (string) $result['url'] ) . '</code>'
			);
			?>
		</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:.6em 0 1.2em;">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::REFRESH_ACTION ); ?>" />
			<?php wp_nonce_field( self::REFRESH_ACTION ); ?>
			<?php submit_button( __( 'Refresh now', 'agent-connector-for-wp' ), 'secondary', 'submit', false ); ?>
		</form>
		<?php
	}

	/**
	 * Render the installed-plugins table (or an empty-state message).
	 *
	 * @param array<int,array<string,mixed>> $rows PluginDirectory overview rows.
	 */
	private function render_table( array $rows ): void {
		if ( empty( $rows ) ) {
			?>
			<p><em><?php esc_html_e( 'No plugins are installed on this site yet.', 'agent-connector-for-wp' ); ?></em></p>
			<?php
			return;
		}
		?>
		<table class="widefat striped" style="max-width:64em;margin-top:1em;">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Installed plugin', 'agent-connector-for-wp' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Ability pack', 'agent-connector-for-wp' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'agent-connector-for-wp' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<?php $this->render_row( $row ); ?>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render one installed-plugin row — with its ability pack + actions when one
	 * is available, or a "no pack" indicator otherwise.
	 *
	 * @param array<string,mixed> $row A single PluginDirectory overview row.
	 */
	private function render_row( array $row ): void {
		$has_pack = ! empty( $row['has_pack'] );
		?>
		<tr>
			<td>
				<strong><?php echo esc_html( (string) $row['plugin_name'] ); ?></strong><br />
				<code style="font-size:11px;"><?php echo esc_html( (string) $row['plugin_file'] ); ?></code>
				<?php if ( empty( $row['plugin_active'] ) ) : ?>
					<br /><span class="description"><?php esc_html_e( '(installed, not active)', 'agent-connector-for-wp' ); ?></span>
				<?php endif; ?>
			</td>
			<?php if ( ! $has_pack ) : ?>
				<td><span class="description">—</span></td>
				<td><span class="description"><?php esc_html_e( 'No ability pack available yet', 'agent-connector-for-wp' ); ?></span></td>
			<?php else : ?>
				<?php $this->render_pack_cells( $row ); ?>
			<?php endif; ?>
		</tr>
		<?php
	}

	/**
	 * Render the "Ability pack" + "Status" cells for a row that has a pack.
	 *
	 * @param array<string,mixed> $row A single PluginDirectory overview row.
	 */
	private function render_pack_cells( array $row ): void {
		$entry        = (array) $row['entry'];
		$pack_name    = (string) ( $entry['ability_pack_name'] ?? '' );
		$pack_slug    = (string) ( $entry['ability_pack_slug'] ?? '' );
		$desc         = (string) ( $entry['description'] ?? '' );
		$source_url   = (string) ( $entry['source_url'] ?? '' );
		$version      = (string) ( $entry['version'] ?? '' );
		$download_url = (string) ( $entry['download_url'] ?? '' );
		$can_install  = current_user_can( 'install_plugins' ) && ! ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS );
		?>
		<td>
			<strong>
				<?php if ( '' !== $source_url ) : ?>
					<a href="<?php echo esc_url( $source_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $pack_name ); ?></a>
				<?php else : ?>
					<?php echo esc_html( $pack_name ); ?>
				<?php endif; ?>
			</strong>
			<?php if ( '' !== $version ) : ?>
				<span class="description">v<?php echo esc_html( $version ); ?></span>
			<?php endif; ?>
			<?php if ( '' !== $desc ) : ?>
				<p class="description" style="margin:.3em 0 0;max-width:40em;"><?php echo esc_html( $desc ); ?></p>
			<?php endif; ?>
		</td>
		<td>
			<?php if ( $row['pack_active'] ) : ?>
				<span style="color:#1a7f37;font-weight:600;"><?php esc_html_e( 'Installed &amp; active', 'agent-connector-for-wp' ); ?></span>
			<?php elseif ( $row['pack_installed'] ) : ?>
				<span style="color:#996800;font-weight:600;"><?php esc_html_e( 'Installed, inactive', 'agent-connector-for-wp' ); ?></span>
				<?php if ( $can_install && '' !== $pack_slug ) : ?>
					<div style="margin-top:.4em;"><?php $this->render_action_form( self::ACTIVATE_ACTION, $pack_slug, __( 'Activate', 'agent-connector-for-wp' ), 'secondary' ); ?></div>
				<?php endif; ?>
			<?php else : ?>
				<span style="color:#2271b1;font-weight:600;"><?php esc_html_e( 'Available to add', 'agent-connector-for-wp' ); ?></span>
				<?php if ( $can_install && '' !== $pack_slug && '' !== $download_url ) : ?>
					<div style="margin-top:.4em;"><?php $this->render_action_form( self::INSTALL_ACTION, $pack_slug, __( 'Install', 'agent-connector-for-wp' ), 'primary' ); ?></div>
				<?php endif; ?>
			<?php endif; ?>
		</td>
		<?php
	}

	/**
	 * admin-post: clear the cache and refetch, then redirect back.
	 */
	public function handle_refresh(): void {
		$this->verify( self::REFRESH_ACTION );

		PluginDirectory::clear_cache();
		PluginDirectory::get( true );

		$this->redirect( 'refreshed' );
	}

	/**
	 * admin-post: download an ability pack's zip from the directory's trusted
	 * download URL and install + activate it.
	 */
	public function handle_install(): void {
		$this->verify( self::INSTALL_ACTION );

		if ( ! current_user_can( 'install_plugins' ) || ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) ) {
			$this->redirect( 'install_failed' );
		}

		$slug  = isset( $_POST['pack_slug'] ) ? sanitize_text_field( wp_unslash( $_POST['pack_slug'] ) ) : '';
		$entry = $this->find_entry( $slug );
		// Re-resolve the download URL server-side from the trusted directory — never
		// trust a URL posted by the browser.
		$url = null !== $entry ? (string) ( $entry['download_url'] ?? '' ) : '';
		if ( '' === $url || ! self::is_allowed_download( $url ) ) {
			$this->redirect( 'install_failed' );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		// We can't show an FTP-credentials form from an admin-post handler, so bail
		// cleanly when the filesystem isn't directly writable.
		if ( 'direct' !== get_filesystem_method() ) {
			$this->redirect( 'fs_unavailable' );
		}

		$upgrader = new \Plugin_Upgrader( new \Automatic_Upgrader_Skin() );
		$result   = $upgrader->install( $url );

		if ( is_wp_error( $result ) || true !== $result ) {
			$this->redirect( 'install_failed' );
		}

		$plugin_file = (string) $upgrader->plugin_info();
		if ( '' === $plugin_file ) {
			$plugin_file = (string) ( PluginDirectory::installed_file_for_slug( $slug ) ?? '' );
		}

		if ( '' !== $plugin_file ) {
			activate_plugin( $plugin_file, '', false, true );
		}

		PluginDirectory::clear_cache();
		$this->redirect( 'installed' );
	}

	/**
	 * admin-post: activate an already-installed ability pack.
	 */
	public function handle_activate(): void {
		$this->verify( self::ACTIVATE_ACTION );

		if ( ! current_user_can( 'activate_plugins' ) ) {
			$this->redirect( 'install_failed' );
		}

		$slug = isset( $_POST['pack_slug'] ) ? sanitize_text_field( wp_unslash( $_POST['pack_slug'] ) ) : '';
		$file = PluginDirectory::installed_file_for_slug( $slug );
		if ( null === $file ) {
			$this->redirect( 'install_failed' );
		}

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$result = activate_plugin( $file, '', false, true );

		PluginDirectory::clear_cache();
		$this->redirect( is_wp_error( $result ) ? 'install_failed' : 'activated' );
	}

	/**
	 * Capability + nonce gate shared by every admin-post handler here.
	 */
	private function verify( string $action ): void {
		if ( ! current_user_can( Config::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'agent-connector-for-wp' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( $action );
	}

	/**
	 * Redirect back to this screen with a notice key, then stop.
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

	/**
	 * Find a directory entry by its ability-pack slug (cache-aware).
	 *
	 * @return array<string,string>|null
	 */
	private function find_entry( string $slug ): ?array {
		if ( '' === $slug ) {
			return null;
		}
		foreach ( PluginDirectory::get()['entries'] as $entry ) {
			if ( isset( $entry['ability_pack_slug'] ) && $entry['ability_pack_slug'] === $slug ) {
				return $entry;
			}
		}

		return null;
	}

	/**
	 * Only allow downloading pack zips over https from an allowlisted host
	 * (the GitHub release hosts by default). Filterable.
	 */
	private static function is_allowed_download( string $url ): bool {
		$parts = wp_parse_url( $url );
		if ( empty( $parts['scheme'] ) || 'https' !== strtolower( (string) $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return false;
		}
		$host = strtolower( (string) $parts['host'] );

		/**
		 * Filters the hosts an ability-pack zip may be downloaded from.
		 *
		 * @param array<int,string> $hosts Allowlisted hostnames (and their subdomains).
		 */
		$allowed = (array) apply_filters(
			'agent_connector_for_wp_pack_download_hosts',
			array( 'github.com', 'objects.githubusercontent.com', 'codeload.github.com' )
		);

		foreach ( $allowed as $h ) {
			$h = strtolower( (string) $h );
			if ( '' === $h ) {
				continue;
			}
			if ( $host === $h || substr( $host, -strlen( '.' . $h ) ) === '.' . $h ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Render a small POST form (action button) for install/activate. Posts only
	 * the pack slug + nonce; the handler re-resolves everything else server-side.
	 */
	private function render_action_form( string $action, string $slug, string $label, string $type ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
			<input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>" />
			<input type="hidden" name="pack_slug" value="<?php echo esc_attr( $slug ); ?>" />
			<?php wp_nonce_field( $action ); ?>
			<?php submit_button( $label, $type, 'submit', false ); ?>
		</form>
		<?php
	}

	/**
	 * Render the result notice for a given notice key (post-redirect-get).
	 */
	private function render_notice( string $notice ): void {
		$map = array(
			'refreshed'      => array( 'success', __( 'Directory refreshed.', 'agent-connector-for-wp' ) ),
			'installed'      => array( 'success', __( 'Ability pack installed and activated.', 'agent-connector-for-wp' ) ),
			'activated'      => array( 'success', __( 'Ability pack activated.', 'agent-connector-for-wp' ) ),
			'install_failed' => array( 'error', __( 'Could not install the ability pack. Check that it is available and that this site can install plugins.', 'agent-connector-for-wp' ) ),
			'fs_unavailable' => array( 'error', __( 'This site cannot install plugins directly (no direct filesystem access). Install the pack zip manually instead.', 'agent-connector-for-wp' ) ),
		);
		if ( ! isset( $map[ $notice ] ) ) {
			return;
		}
		list( $kind, $message ) = $map[ $notice ];
		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $kind ),
			esc_html( $message )
		);
	}
}
