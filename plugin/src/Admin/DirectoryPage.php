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
use AgentConnectorForWp\Support\Governance;

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
	 * Previously registered "Abilities" as a standalone submenu page. Removed
	 * now that the Abilities tab lives inside the React SPA on the main Connection
	 * screen, keeping only the admin_post action handlers for compatibility.
	 */
	public function register_menu(): void {}

	/**
	 * Render the Abilities screen — tabbed: Ability Packs + Registered Abilities.
	 */
	public function render_page(): void {
		if ( ! current_user_can( Config::CAP ) ) {
			return;
		}

		$tabs   = array(
			'packs'     => __( 'Ability Packs', 'agent-connector-for-wp' ),
			'abilities' => __( 'Registered Abilities', 'agent-connector-for-wp' ),
		);
		$tab    = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'packs'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$notice = isset( $_GET['acfw_notice'] ) ? sanitize_key( wp_unslash( $_GET['acfw_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'packs';
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Agent Connector for WP — Abilities', 'agent-connector-for-wp' ); ?></h1>

			<?php $this->render_notice( $notice ); ?>

			<h2 class="nav-tab-wrapper">
				<?php foreach ( $tabs as $key => $label ) : ?>
					<a
						href="<?php echo esc_url( add_query_arg( array( 'page' => self::MENU_SLUG, 'tab' => $key ), admin_url( 'admin.php' ) ) ); ?>"
						class="nav-tab <?php echo esc_attr( $tab === $key ? 'nav-tab-active' : '' ); ?>"
					><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</h2>

			<?php
			if ( 'abilities' === $tab ) {
				$this->render_abilities_tab();
			} else {
				$this->render_packs_tab();
			}
			?>
		</div>
		<?php
	}

	/**
	 * The "Ability Packs" tab: active plugins, the AI abilities available for each
	 * (official / third-party / ours), and one-click install of our packs.
	 */
	private function render_packs_tab(): void {
		$result = PluginDirectory::installed_overview();
		?>
		<p style="max-width:52em;">
			<?php esc_html_e( 'Your active plugins and, for each, the optional unofficial ability pack we generate. Installing a pack is always optional.', 'agent-connector-for-wp' ); ?>
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
				esc_html__( 'Pack index source: %s', 'agent-connector-for-wp' ),
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
		<table class="widefat striped" style="max-width:72em;margin-top:1em;">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Active plugin', 'agent-connector-for-wp' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Unofficial ability pack', 'agent-connector-for-wp' ); ?></th>
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
			echo '<span class="description">' . esc_html__( 'None available', 'agent-connector-for-wp' ) . '</span>';
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
				<span style="color:#1a7f37;font-weight:600;"><?php esc_html_e( 'Installed &amp; active', 'agent-connector-for-wp' ); ?></span>
				<?php if ( $can_manage && '' !== $pack_slug ) : ?>
					<div style="margin-top:.3em;"><?php $this->render_action_form( self::DEACTIVATE_ACTION, $pack_slug, __( 'Disable', 'agent-connector-for-wp' ), 'secondary' ); ?></div>
				<?php endif; ?>
			<?php elseif ( $row['pack_installed'] ) : ?>
				<span style="color:#996800;font-weight:600;"><?php esc_html_e( 'Installed, disabled', 'agent-connector-for-wp' ); ?></span>
				<?php if ( $can_manage && '' !== $pack_slug ) : ?>
					<div style="margin-top:.3em;"><?php $this->render_action_form( self::ACTIVATE_ACTION, $pack_slug, __( 'Enable', 'agent-connector-for-wp' ), 'primary' ); ?></div>
				<?php endif; ?>
			<?php else : ?>
				<span style="color:#2271b1;font-weight:600;"><?php esc_html_e( 'Available', 'agent-connector-for-wp' ); ?></span>
				<?php if ( $can_manage && '' !== $pack_slug && '' !== $download_url ) : ?>
					<div style="margin-top:.3em;"><?php $this->render_action_form( self::INSTALL_ACTION, $pack_slug, __( 'Install &amp; Activate', 'agent-connector-for-wp' ), 'primary' ); ?></div>
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

	/** How many abilities to show per page on the Registered Abilities tab. */
	private const ABILITIES_PER_PAGE = 50;

	/**
	 * The "Registered Abilities" tab: a compact, searchable, paginated list of
	 * every ability registered through the WP Abilities API. One line each; full
	 * detail (schemas + meta) lives in a collapsed disclosure per row.
	 *
	 * Built to stay light with very large registries (e.g. 100 plugins ×
	 * 100 abilities): we filter in a single pass, sort names (strings, not
	 * objects), and only ever render one page (<= ABILITIES_PER_PAGE) of rows, so
	 * the DOM and the rendered schema markup stay bounded regardless of the total.
	 */
	private function render_abilities_tab(): void {
		$all = function_exists( 'wp_get_abilities' ) ? (array) wp_get_abilities() : array();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only GET navigation.
		$search = isset( $_GET['ability_s'] ) ? sanitize_text_field( wp_unslash( $_GET['ability_s'] ) ) : '';
		$paged  = isset( $_GET['ability_paged'] ) ? max( 1, (int) $_GET['ability_paged'] ) : 1;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$needle = strtolower( $search );

		// Single pass: filter + key by name (names are unique).
		$keyed = array();
		foreach ( $all as $ability ) {
			if ( ! is_object( $ability ) || ! method_exists( $ability, 'get_name' ) ) {
				continue;
			}
			if ( '' !== $needle ) {
				$category = method_exists( $ability, 'get_category' ) ? (string) $ability->get_category() : '';
				$hay      = strtolower( $ability->get_name() . ' ' . $ability->get_label() . ' ' . $ability->get_description() . ' ' . $category );
				if ( false === strpos( $hay, $needle ) ) {
					continue;
				}
			}
			$keyed[ (string) $ability->get_name() ] = $ability;
		}

		$names = array_keys( $keyed );
		sort( $names, SORT_NATURAL | SORT_FLAG_CASE ); // Cheap: strings, not objects.

		$total    = count( $names );
		$pages    = (int) max( 1, (int) ceil( $total / self::ABILITIES_PER_PAGE ) );
		$paged    = min( $paged, $pages );
		$offset   = ( $paged - 1 ) * self::ABILITIES_PER_PAGE;
		$page_set = array_slice( $names, $offset, self::ABILITIES_PER_PAGE );

		$base = add_query_arg(
			array(
				'page' => self::MENU_SLUG,
				'tab'  => 'abilities',
			),
			admin_url( 'admin.php' )
		);
		?>
		<p style="max-width:52em;">
			<?php esc_html_e( 'Every ability registered on this site through the WordPress Abilities API. Abilities whose plugin is inactive (or whose code is gated off) are not registered and so do not appear here. Those marked “Exposed via MCP” are surfaced to connected agents while the plugin is enabled.', 'agent-connector-for-wp' ); ?>
		</p>

		<form method="get" style="margin:.5em 0 1em;">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>" />
			<input type="hidden" name="tab" value="abilities" />
			<input type="search" name="ability_s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search abilities…', 'agent-connector-for-wp' ); ?>" style="min-width:22em;" />
			<?php submit_button( __( 'Search', 'agent-connector-for-wp' ), 'secondary', '', false ); ?>
			<?php if ( '' !== $search ) : ?>
				<a href="<?php echo esc_url( $base ); ?>" class="button-link" style="margin-left:.5em;"><?php esc_html_e( 'Clear', 'agent-connector-for-wp' ); ?></a>
			<?php endif; ?>
		</form>

		<?php if ( 0 === $total ) : ?>
			<p><em><?php echo '' !== $search ? esc_html__( 'No abilities match your search.', 'agent-connector-for-wp' ) : esc_html__( 'No abilities are registered yet.', 'agent-connector-for-wp' ); ?></em></p>
			<?php return; ?>
		<?php endif; ?>

		<p class="description">
			<?php
			printf(
				/* translators: 1: first row number, 2: last row number, 3: total count. */
				esc_html__( 'Showing %1$d–%2$d of %3$d abilities.', 'agent-connector-for-wp' ),
				(int) ( $offset + 1 ),
				(int) min( $offset + self::ABILITIES_PER_PAGE, $total ),
				(int) $total
			);
			?>
		</p>

		<table class="widefat striped" style="margin-top:.5em;">
			<thead>
				<tr>
					<th scope="col" style="width:48%;"><?php esc_html_e( 'Ability', 'agent-connector-for-wp' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Category', 'agent-connector-for-wp' ); ?></th>
					<th scope="col"><?php esc_html_e( 'MCP', 'agent-connector-for-wp' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Details', 'agent-connector-for-wp' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $page_set as $name ) : ?>
					<?php $this->render_ability_row( $keyed[ $name ] ); ?>
				<?php endforeach; ?>
			</tbody>
		</table>

		<?php $this->render_abilities_pagination( $base, $search, $paged, $pages ); ?>
		<?php
	}

	/**
	 * Prev/next pager for the Registered Abilities tab (preserves the search term).
	 */
	private function render_abilities_pagination( string $base, string $search, int $paged, int $pages ): void {
		if ( $pages < 2 ) {
			return;
		}
		$extra = '' !== $search ? array( 'ability_s' => $search ) : array();
		$link  = static function ( int $p ) use ( $base, $extra ): string {
			return esc_url( add_query_arg( array_merge( $extra, array( 'ability_paged' => $p ) ), $base ) );
		};
		?>
		<p style="margin-top:1em;">
			<?php if ( $paged > 1 ) : ?>
				<a class="button" href="<?php echo $link( $paged - 1 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>">&larr; <?php esc_html_e( 'Previous', 'agent-connector-for-wp' ); ?></a>
			<?php endif; ?>
			<span class="description" style="margin:0 .6em;">
				<?php
				printf(
					/* translators: 1: current page, 2: total pages. */
					esc_html__( 'Page %1$d of %2$d', 'agent-connector-for-wp' ),
					(int) $paged,
					(int) $pages
				);
				?>
			</span>
			<?php if ( $paged < $pages ) : ?>
				<a class="button" href="<?php echo $link( $paged + 1 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>"><?php esc_html_e( 'Next', 'agent-connector-for-wp' ); ?> &rarr;</a>
			<?php endif; ?>
		</p>
		<?php
	}

	/**
	 * Render one compact ability row: a single line (label, name, category, MCP)
	 * with a collapsed disclosure holding the description + schemas + meta.
	 *
	 * @param object $ability A WP_Ability instance.
	 */
	private function render_ability_row( $ability ): void {
		$name        = (string) $ability->get_name();
		$label       = (string) $ability->get_label();
		$desc        = (string) $ability->get_description();
		$category    = method_exists( $ability, 'get_category' ) ? (string) $ability->get_category() : '';
		$meta        = (array) $ability->get_meta();
		$mcp_public  = Governance::is_mcp_exposed_meta( $meta );
		$annotations = isset( $meta['annotations'] ) && is_array( $meta['annotations'] ) ? $meta['annotations'] : array();
		$input       = $ability->get_input_schema();
		$output      = method_exists( $ability, 'get_output_schema' ) ? $ability->get_output_schema() : null;

		$pre  = 'white-space:pre-wrap;max-width:48em;background:#f6f7f7;padding:.6em;overflow:auto;';
		$json = static function ( $data ): string {
			return (string) wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		};
		?>
		<tr>
			<td>
				<?php if ( '' !== $label && $label !== $name ) : ?>
					<strong><?php echo esc_html( $label ); ?></strong>
				<?php endif; ?>
				<code style="font-size:11px;"><?php echo esc_html( $name ); ?></code>
			</td>
			<td><?php echo '' !== $category ? esc_html( $category ) : '<span class="description">—</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
			<td>
				<?php if ( $mcp_public ) : ?>
					<span style="color:#1a7f37;font-weight:600;"><?php esc_html_e( 'Yes', 'agent-connector-for-wp' ); ?></span>
				<?php else : ?>
					<span class="description"><?php esc_html_e( 'No', 'agent-connector-for-wp' ); ?></span>
				<?php endif; ?>
			</td>
			<td>
				<details>
					<summary style="cursor:pointer;"><?php esc_html_e( 'View', 'agent-connector-for-wp' ); ?></summary>
					<?php if ( '' !== $desc ) : ?>
						<p class="description" style="margin:.5em 0 0;max-width:48em;"><?php echo esc_html( $desc ); ?></p>
					<?php endif; ?>
					<?php if ( ! empty( $annotations ) ) : ?>
						<p style="margin:.5em 0 0;"><strong><?php esc_html_e( 'Annotations', 'agent-connector-for-wp' ); ?></strong></p>
						<pre style="<?php echo esc_attr( $pre ); ?>"><?php echo esc_html( $json( $annotations ) ); ?></pre>
					<?php endif; ?>
					<p style="margin:.5em 0 0;"><strong><?php esc_html_e( 'Input schema', 'agent-connector-for-wp' ); ?></strong></p>
					<pre style="<?php echo esc_attr( $pre ); ?>"><?php echo esc_html( $json( $input ) ); ?></pre>
					<?php if ( ! empty( $output ) ) : ?>
						<p style="margin:.5em 0 0;"><strong><?php esc_html_e( 'Output schema', 'agent-connector-for-wp' ); ?></strong></p>
						<pre style="<?php echo esc_attr( $pre ); ?>"><?php echo esc_html( $json( $output ) ); ?></pre>
					<?php endif; ?>
				</details>
			</td>
		</tr>
		<?php
	}

	/**
	 * Render the result notice for a given notice key (post-redirect-get).
	 */
	private function render_notice( string $notice ): void {
		$map = array(
			'refreshed'      => array( 'success', __( 'Directory refreshed.', 'agent-connector-for-wp' ) ),
			'installed'      => array( 'success', __( 'Ability pack installed and activated.', 'agent-connector-for-wp' ) ),
			'activated'      => array( 'success', __( 'Ability pack enabled.', 'agent-connector-for-wp' ) ),
			'disabled'       => array( 'success', __( 'Ability pack disabled.', 'agent-connector-for-wp' ) ),
			'install_failed' => array( 'error', __( 'Could not complete that action. Check that the pack is available and that this site can install/activate plugins.', 'agent-connector-for-wp' ) ),
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
