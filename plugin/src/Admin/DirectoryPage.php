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
 * Read-only directory browser.
 *
 * Submenu under the top-level "Agent Connector for WP" menu. It fetches a remote
 * directory of companion "ability pack" plugins (cached for 12h), matches it
 * against the plugins installed here, and lists the hits. Purely informational —
 * it never installs anything. A manual "Refresh now" button (admin-post + nonce)
 * busts the cache. Visibility is gated behind the same capability as the rest of
 * the plugin's admin area.
 */
final class DirectoryPage {

	public const MENU_SLUG = 'agent-connector-for-wp-ability-packs';

	private const REFRESH_ACTION = 'agent_connector_for_wp_refresh_directory';

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_post_' . self::REFRESH_ACTION, array( $this, 'handle_refresh' ) );
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

		$result   = PluginDirectory::matches();
		$matches  = $result['matches'];
		$notice   = isset( $_GET['acfw_notice'] ) ? sanitize_key( wp_unslash( $_GET['acfw_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Agent Connector for WP — Ability Packs', 'agent-connector-for-wp' ); ?></h1>

			<?php if ( 'refreshed' === $notice ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Directory refreshed.', 'agent-connector-for-wp' ); ?></p></div>
			<?php endif; ?>

			<p style="max-width:50em;">
				<?php esc_html_e( 'Some plugins on this site can be extended with a companion "ability pack" that registers AI abilities through Agent Connector. This page checks the published directory of ability packs against your installed plugins and lists the matches. It is informational only — nothing is installed automatically.', 'agent-connector-for-wp' ); ?>
			</p>

			<?php $this->render_toolbar( $result ); ?>

			<?php if ( '' !== $result['error'] && empty( $matches ) ) : ?>
				<div class="notice notice-warning inline" style="max-width:50em;">
					<p><?php echo esc_html( $result['error'] ); ?></p>
				</div>
			<?php elseif ( $result['stale'] ) : ?>
				<div class="notice notice-warning inline" style="max-width:50em;">
					<p><?php echo esc_html( $result['error'] ); ?></p>
				</div>
			<?php endif; ?>

			<?php $this->render_matches_table( $matches ); ?>
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
	 * Render the matches table (or an empty-state message).
	 *
	 * @param array<int,array<string,mixed>> $matches PluginDirectory matches.
	 */
	private function render_matches_table( array $matches ): void {
		if ( empty( $matches ) ) {
			?>
			<p><em><?php esc_html_e( 'None of your installed plugins have an ability pack in the directory yet.', 'agent-connector-for-wp' ); ?></em></p>
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
				<?php foreach ( $matches as $match ) : ?>
					<?php $this->render_match_row( $match ); ?>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render one match row.
	 *
	 * @param array<string,mixed> $match A single PluginDirectory match.
	 */
	private function render_match_row( array $match ): void {
		$entry      = (array) $match['entry'];
		$pack_name  = (string) ( $entry['ability_pack_name'] ?? '' );
		$desc       = (string) ( $entry['description'] ?? '' );
		$source_url = (string) ( $entry['source_url'] ?? '' );
		$version    = (string) ( $entry['version'] ?? '' );
		?>
		<tr>
			<td>
				<strong><?php echo esc_html( (string) $match['target_plugin_name'] ); ?></strong><br />
				<code style="font-size:11px;"><?php echo esc_html( (string) $match['target_plugin_file'] ); ?></code>
				<?php if ( ! $match['target_active'] ) : ?>
					<br /><span class="description"><?php esc_html_e( '(installed, not active)', 'agent-connector-for-wp' ); ?></span>
				<?php endif; ?>
			</td>
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
				<?php if ( $match['pack_active'] ) : ?>
					<span style="color:#1a7f37;font-weight:600;"><?php esc_html_e( 'Installed &amp; active', 'agent-connector-for-wp' ); ?></span>
				<?php elseif ( $match['pack_installed'] ) : ?>
					<span style="color:#996800;font-weight:600;"><?php esc_html_e( 'Installed, inactive', 'agent-connector-for-wp' ); ?></span>
				<?php else : ?>
					<span style="color:#2271b1;font-weight:600;"><?php esc_html_e( 'Available to add', 'agent-connector-for-wp' ); ?></span>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * admin-post: clear the cache and refetch, then redirect back.
	 */
	public function handle_refresh(): void {
		if ( ! current_user_can( Config::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'agent-connector-for-wp' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::REFRESH_ACTION );

		PluginDirectory::clear_cache();
		PluginDirectory::get( true );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => self::MENU_SLUG,
					'acfw_notice' => 'refreshed',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
