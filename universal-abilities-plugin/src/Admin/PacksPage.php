<?php
/**
 * The "Ability Packs" admin screen: shows which installed plugins have an
 * available Agent Connector ability pack in the remote directory.
 *
 * @package AgentConnectorForWpDefaultAbilities
 */

declare( strict_types=1 );

namespace AgentConnectorForWp\DefaultAbilities\Admin;

use AgentConnectorForWp\DefaultAbilities\Services\PluginDirectory;
use AgentConnectorForWp\Support\Config;

defined( 'ABSPATH' ) || exit;

/**
 * Ability-pack directory browser + installer.
 *
 * Submenu under the host plugin's top-level "Agent Connector" menu. It asks the
 * remote directory which installed plugins have a companion "ability pack"
 * available (cached for 12h), lists the hits, and offers one-click
 * Install/Activate buttons for packs that aren't active yet. A manual "Refresh
 * now" button (admin-post + nonce) busts the cache. All actions are gated behind
 * the host plugin's admin capability plus core's install_plugins /
 * activate_plugins, with nonces.
 */
final class PacksPage {

	public const MENU_SLUG = 'agent-connector-for-wp-ability-packs';

	/** The host plugin's top-level menu slug this page attaches under. */
	private const PARENT_SLUG = 'agent-connector-for-wp';

	private const REFRESH_ACTION    = 'agent_connector_for_wp_refresh_directory';
	private const INSTALL_ACTION    = 'agent_connector_for_wp_install_pack';
	private const ACTIVATE_ACTION   = 'agent_connector_for_wp_activate_pack';
	private const DEACTIVATE_ACTION = 'agent_connector_for_wp_deactivate_pack';

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_post_' . self::REFRESH_ACTION, array( $this, 'handle_refresh' ) );
		add_action( 'admin_post_' . self::INSTALL_ACTION, array( $this, 'handle_install' ) );
		add_action( 'admin_post_' . self::ACTIVATE_ACTION, array( $this, 'handle_activate' ) );
		add_action( 'admin_post_' . self::DEACTIVATE_ACTION, array( $this, 'handle_deactivate' ) );
	}

	/**
	 * Register the "Ability Packs" submenu under the host plugin's menu. No-op
	 * when the host menu is absent (the host plugin is inactive).
	 */
	public function register_menu(): void {
		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Ability Packs', 'universal-abilities-plugin' ),
			__( 'Ability Packs', 'universal-abilities-plugin' ),
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

		$notice = isset( $_GET['acfw_notice'] ) ? sanitize_key( wp_unslash( $_GET['acfw_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Ability Packs', 'universal-abilities-plugin' ); ?></h1>

			<?php $this->render_notice( $notice ); ?>

			<?php $this->render_packs(); ?>
		</div>
		<?php
	}

	/**
	 * The packs listing: active plugins, the AI abilities available for each
	 * (official / third-party / ours), and one-click install of our packs.
	 */
	private function render_packs(): void {
		$result = PluginDirectory::installed_overview();
		?>
		<p style="max-width:52em;">
			<?php esc_html_e( 'Your active plugins and, for each, the optional unofficial ability pack we generate. Installing a pack is always optional.', 'universal-abilities-plugin' ); ?>
		</p>

		<?php $this->render_toolbar( $result ); ?>

		<?php if ( '' !== $result['error'] ) : ?>
			<div class="notice notice-warning inline" style="max-width:50em;">
				<p><?php echo esc_html( $result['error'] ); ?></p>
			</div>
		<?php endif; ?>

		<?php $this->render_table( $result['rows'] ); ?>
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
				/* translators: %s: ability-pack manifest URL. */
				esc_html__( 'Pack index source: %s', 'universal-abilities-plugin' ),
				'<code>' . esc_html( (string) $result['url'] ) . '</code>'
			);
			?>
		</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:.6em 0 1.2em;">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::REFRESH_ACTION ); ?>" />
			<?php wp_nonce_field( self::REFRESH_ACTION ); ?>
			<?php submit_button( __( 'Refresh now', 'universal-abilities-plugin' ), 'secondary', 'submit', false ); ?>
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
			<p><em><?php esc_html_e( 'No plugins are installed on this site yet.', 'universal-abilities-plugin' ); ?></em></p>
			<?php
			return;
		}
		?>
		<table class="widefat striped" style="max-width:72em;margin-top:1em;">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Active plugin', 'universal-abilities-plugin' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Unofficial ability pack', 'universal-abilities-plugin' ); ?></th>
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
	 * Render one active-plugin row: the plugin and our optional ability pack with
	 * its actions.
	 *
	 * @param array<string,mixed> $row A single PluginDirectory overview row.
	 */
	private function render_row( array $row ): void {
		?>
		<tr>
			<td>
				<strong><?php echo esc_html( (string) $row['plugin_name'] ); ?></strong><br />
				<code style="font-size:11px;"><?php echo esc_html( (string) $row['plugin_file'] ); ?></code>
			</td>
			<td><?php $this->render_pack_cell( $row ); ?></td>
		</tr>
		<?php
	}

	/**
	 * The "Unofficial ability pack" cell — our generated pack (if any) and its
	 * Install & Activate / Enable / Disable actions.
	 *
	 * @param array<string,mixed> $row A single PluginDirectory overview row.
	 */
	private function render_pack_cell( array $row ): void {
		if ( empty( $row['has_pack'] ) ) {
			echo '<span class="description">' . esc_html__( 'None available', 'universal-abilities-plugin' ) . '</span>';
			return;
		}

		$entry        = (array) $row['entry'];
		$pack_name    = (string) ( $entry['ability_pack_name'] ?? '' );
		$pack_slug    = (string) ( $entry['ability_pack_slug'] ?? '' );
		$desc         = (string) ( $entry['description'] ?? '' );
		$source_url   = (string) ( $entry['source_url'] ?? '' );
		$version      = (string) ( $entry['version'] ?? '' );
		$download_url = (string) ( $entry['download_url'] ?? '' );
		$can_manage   = current_user_can( 'install_plugins' ) && ! ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS );
		?>
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
			<p class="description" style="margin:.3em 0 .4em;max-width:36em;"><?php echo esc_html( $desc ); ?></p>
		<?php endif; ?>

		<div style="margin-top:.4em;">
			<?php if ( $row['pack_active'] ) : ?>
				<span style="color:#1a7f37;font-weight:600;"><?php esc_html_e( 'Installed &amp; active', 'universal-abilities-plugin' ); ?></span>
				<?php if ( $can_manage && '' !== $pack_slug ) : ?>
					<div style="margin-top:.3em;"><?php $this->render_action_form( self::DEACTIVATE_ACTION, $pack_slug, __( 'Disable', 'universal-abilities-plugin' ), 'secondary' ); ?></div>
				<?php endif; ?>
			<?php elseif ( $row['pack_installed'] ) : ?>
				<span style="color:#996800;font-weight:600;"><?php esc_html_e( 'Installed, disabled', 'universal-abilities-plugin' ); ?></span>
				<?php if ( $can_manage && '' !== $pack_slug ) : ?>
					<div style="margin-top:.3em;"><?php $this->render_action_form( self::ACTIVATE_ACTION, $pack_slug, __( 'Enable', 'universal-abilities-plugin' ), 'primary' ); ?></div>
				<?php endif; ?>
			<?php else : ?>
				<span style="color:#2271b1;font-weight:600;"><?php esc_html_e( 'Available', 'universal-abilities-plugin' ); ?></span>
				<?php if ( $can_manage && '' !== $pack_slug && '' !== $download_url ) : ?>
					<div style="margin-top:.3em;"><?php $this->render_action_form( self::INSTALL_ACTION, $pack_slug, __( 'Install &amp; Activate', 'universal-abilities-plugin' ), 'primary' ); ?></div>
				<?php endif; ?>
			<?php endif; ?>
		</div>
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
	 * admin-post: deactivate (disable) an installed ability pack.
	 */
	public function handle_deactivate(): void {
		$this->verify( self::DEACTIVATE_ACTION );

		if ( ! current_user_can( 'activate_plugins' ) ) {
			$this->redirect( 'install_failed' );
		}

		$slug = isset( $_POST['pack_slug'] ) ? sanitize_text_field( wp_unslash( $_POST['pack_slug'] ) ) : '';
		$file = PluginDirectory::installed_file_for_slug( $slug );
		if ( null === $file ) {
			$this->redirect( 'install_failed' );
		}

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		deactivate_plugins( $file, true );

		PluginDirectory::clear_cache();
		$this->redirect( 'disabled' );
	}

	/**
	 * Capability + nonce gate shared by every admin-post handler here.
	 */
	private function verify( string $action ): void {
		if ( ! current_user_can( Config::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'universal-abilities-plugin' ), '', array( 'response' => 403 ) );
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
			'refreshed'      => array( 'success', __( 'Directory refreshed.', 'universal-abilities-plugin' ) ),
			'installed'      => array( 'success', __( 'Ability pack installed and activated.', 'universal-abilities-plugin' ) ),
			'activated'      => array( 'success', __( 'Ability pack enabled.', 'universal-abilities-plugin' ) ),
			'disabled'       => array( 'success', __( 'Ability pack disabled.', 'universal-abilities-plugin' ) ),
			'install_failed' => array( 'error', __( 'Could not complete that action. Check that the pack is available and that this site can install/activate plugins.', 'universal-abilities-plugin' ) ),
			'fs_unavailable' => array( 'error', __( 'This site cannot install plugins directly (no direct filesystem access). Install the pack zip manually instead.', 'universal-abilities-plugin' ) ),
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
