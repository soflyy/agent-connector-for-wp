<?php
/**
 * Site-wide "MCP Adapter plugin is required" notice.
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
 * Installing and updating the adapter is WordPress's job, not this plugin's:
 * the `Requires Plugins: mcp-adapter` header in agent-connector-for-wp.php
 * blocks activation without it and points at the wordpress.org listing. This
 * notice exists for the one case the header cannot cover — a site that updated
 * from a release which still bundled the adapter, and so is running with the
 * dependency missing — plus an adapter too old to work with. It explains the
 * problem and links to the screen that fixes it.
 *
 * Not dismissible: it describes a hard requirement, and it disappears on its
 * own once a supported adapter is active.
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

		$installed = null !== PluginDirectory::mcp_adapter_file();

		if ( McpAdapterPlugin::STATUS_OUTDATED === $status ) {
			$body = sprintf(
				/* translators: 1: installed MCP Adapter version, 2: minimum required version */
				__( 'Agent Connector is running, but the installed MCP Adapter plugin (version %1$s) is too old. Update MCP Adapter to version %2$s or newer to bring the MCP server back.', 'agent-connector-for-wp' ),
				(string) McpAdapterPlugin::version(),
				McpAdapterPlugin::MIN_VERSION
			);
		} elseif ( $installed ) {
			$body = __( 'Agent Connector is running, but the MCP Adapter plugin it requires is not active. Activate it to bring the MCP server back; until then agents have nothing to connect to.', 'agent-connector-for-wp' );
		} else {
			$body = __( 'Agent Connector is running, but the MCP Adapter plugin it requires is not installed. Install and activate it to bring the MCP server back; until then agents have nothing to connect to.', 'agent-connector-for-wp' );
		}

		// Send the operator to the screen that resolves their case: the Plugins
		// screen when a copy is already there (to activate or update it), the
		// wordpress.org install search when none is.
		if ( $installed ) {
			$action_url  = self_admin_url( 'plugins.php' );
			$action_text = __( 'Go to Plugins', 'agent-connector-for-wp' );
		} else {
			$action_url  = self_admin_url( 'plugin-install.php?tab=search&type=term&s=' . rawurlencode( 'MCP Adapter' ) );
			$action_text = __( 'Install MCP Adapter', 'agent-connector-for-wp' );
		}

		$can_install = current_user_can( 'install_plugins' ) && ! ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS );
		?>
		<div class="notice notice-error" id="acfw-mcp-adapter-notice">
			<p>
				<strong><?php esc_html_e( 'Agent Connector needs the MCP Adapter plugin.', 'agent-connector-for-wp' ); ?></strong>
				<?php echo esc_html( $body ); ?>
			</p>
			<?php if ( $can_install ) : ?>
				<p>
					<a class="button button-primary" href="<?php echo esc_url( $action_url ); ?>">
						<?php echo esc_html( $action_text ); ?>
					</a>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}
}
