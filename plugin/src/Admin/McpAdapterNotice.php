<?php
/**
 * Site-wide "MCP Adapter plugin is required" notice that installs it for you.
 *
 * @package AgentConnectorForWp
 */

declare( strict_types=1 );

namespace AgentConnectorForWp\Admin;

use AgentConnectorForWp\Services\PluginDirectory;
use AgentConnectorForWp\Support\Config;
use AgentConnectorForWp\Support\McpAdapterPlugin;

defined( 'ABSPATH' ) || exit;

/**
 * Shown on every wp-admin page while Agent Connector is switched on but the
 * canonical MCP Adapter plugin is missing, inactive, or too old.
 *
 * Without the adapter there is no MCP server, so nothing an agent could
 * connect to. This is the notice the adapter's installation guide asks
 * dependents to show; on top of it, because WordPress can only offer to
 * install wordpress.org-hosted dependencies and MCP Adapter is not listed
 * there yet, the notice installs it itself, fetching the adapter's release zip
 * from GitHub (REST route /mcp-adapter/install).
 *
 * When the viewer can install plugins, the notice does not wait for a click:
 * it starts the install the moment it renders, shows progress, and reloads
 * the page once the adapter is active. The button only appears when the
 * automatic attempt failed (so the operator can retry after fixing the cause)
 * or when the site cannot install plugins at all. A failed attempt is
 * remembered per browser session so a broken site is not hammered on every
 * admin page load.
 *
 * Not dismissible: it describes a hard requirement, and it disappears on its
 * own once the adapter is active.
 */
final class McpAdapterNotice {

	public function register(): void {
		add_action( 'admin_notices', array( $this, 'render' ) );
		add_action( 'network_admin_notices', array( $this, 'render' ) );
	}

	public function render(): void {
		if ( ! current_user_can( Config::CAP ) || ! Config::is_enabled() ) {
			return;
		}

		$status = McpAdapterPlugin::status();
		if ( McpAdapterPlugin::STATUS_READY === $status ) {
			return;
		}

		$can_install = current_user_can( 'install_plugins' ) && ! ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS );
		$activate    = McpAdapterPlugin::is_installed_inactive();
		$install_url = rest_url( 'agent-connector-for-wp/v1/mcp-adapter/install' );
		$release_url = 'https://github.com/WordPress/mcp-adapter/releases/latest';

		if ( McpAdapterPlugin::STATUS_OUTDATED === $status ) {
			$body = sprintf(
				/* translators: 1: installed MCP Adapter version, 2: minimum required version */
				__( 'Agent Connector is running, but the installed MCP Adapter plugin (version %1$s) is too old. Update MCP Adapter to version %2$s or newer to bring the MCP server back.', 'agent-connector-for-wp' ),
				(string) McpAdapterPlugin::version(),
				McpAdapterPlugin::MIN_VERSION
			);
		} elseif ( $activate ) {
			$body = __( 'Agent Connector is running, but the MCP Adapter plugin it depends on is installed and not active. Activate it to bring the MCP server back; until then agents have nothing to connect to.', 'agent-connector-for-wp' );
		} else {
			$body = __( 'Agent Connector is running, but the MCP Adapter plugin it depends on is not installed. Install and activate it to bring the MCP server back; until then agents have nothing to connect to.', 'agent-connector-for-wp' );
		}

		$auto_install = $can_install && McpAdapterPlugin::STATUS_MISSING === $status;
		$installing   = $activate
			? __( 'Activating MCP Adapter…', 'agent-connector-for-wp' )
			: __( 'Installing MCP Adapter from GitHub…', 'agent-connector-for-wp' );
		?>
		<div class="notice notice-error" id="acfw-mcp-adapter-notice">
			<p>
				<strong><?php esc_html_e( 'Agent Connector needs the MCP Adapter plugin.', 'agent-connector-for-wp' ); ?></strong>
				<span id="acfw-mcp-adapter-body"><?php echo esc_html( $body ); ?></span>
			</p>
			<p>
				<?php if ( $auto_install ) : ?>
					<span id="acfw-mcp-adapter-progress"><span class="spinner is-active" style="float:none;margin:0 6px 0 0;"></span><?php echo esc_html( $installing ); ?></span>
					<button type="button" class="button button-primary" id="acfw-mcp-adapter-install" style="display:none;">
						<?php $activate ? esc_html_e( 'Activate', 'agent-connector-for-wp' ) : esc_html_e( 'Install & Activate', 'agent-connector-for-wp' ); ?>
					</button>
				<?php endif; ?>
				<a class="button" id="acfw-mcp-adapter-download" href="<?php echo esc_url( $release_url ); ?>" target="_blank" rel="noopener noreferrer"<?php echo $auto_install ? ' style="display:none;"' : ''; ?>>
					<?php esc_html_e( 'Download from GitHub', 'agent-connector-for-wp' ); ?>
				</a>
				<span id="acfw-mcp-adapter-error" style="display:none;color:#b32d2e;margin-left:8px;"></span>
			</p>
		</div>
		<?php if ( $auto_install ) : ?>
		<script>
			( function () {
				var button   = document.getElementById( 'acfw-mcp-adapter-install' );
				var download = document.getElementById( 'acfw-mcp-adapter-download' );
				var progress = document.getElementById( 'acfw-mcp-adapter-progress' );
				var error    = document.getElementById( 'acfw-mcp-adapter-error' );
				if ( ! button || ! progress ) {
					return;
				}
				var failedKey = 'acfw-mcp-adapter-install-failed';
				var storage;
				try { storage = window.sessionStorage; } catch ( e ) { storage = null; }

				function showButton() {
					progress.style.display = 'none';
					button.style.display   = '';
					download.style.display = '';
					button.disabled        = false;
				}

				function install() {
					button.disabled        = true;
					button.style.display   = 'none';
					download.style.display = 'none';
					progress.style.display = '';
					error.style.display    = 'none';
					fetch( <?php echo wp_json_encode( esc_url_raw( $install_url ) ); ?>, {
						method: 'POST',
						headers: { 'X-WP-Nonce': <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?> },
					} ).then( function ( response ) {
						return response.json().then( function ( data ) {
							if ( ! response.ok || ! data || ! data.success ) {
								throw new Error( ( data && data.message ) ? data.message : response.statusText );
							}
							if ( storage ) { storage.removeItem( failedKey ); }
							progress.textContent = <?php echo wp_json_encode( __( 'MCP Adapter is active. Reloading…', 'agent-connector-for-wp' ) ); ?>;
							window.location.reload();
						} );
					} ).catch( function ( err ) {
						if ( storage ) { storage.setItem( failedKey, '1' ); }
						showButton();
						button.textContent  = <?php echo wp_json_encode( __( 'Try again', 'agent-connector-for-wp' ) ); ?>;
						error.textContent   = err && err.message ? err.message : <?php echo wp_json_encode( __( 'Installation failed.', 'agent-connector-for-wp' ) ); ?>;
						error.style.display = 'inline';
					} );
				}

				button.addEventListener( 'click', install );

				// Start automatically, unless an earlier attempt in this browser
				// session already failed; then wait for an explicit retry.
				if ( storage && storage.getItem( failedKey ) ) {
					showButton();
					return;
				}
				install();
			} )();
		</script>
		<?php endif; ?>
		<?php
	}
}
