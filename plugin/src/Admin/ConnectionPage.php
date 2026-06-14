<?php
/**
 * The single "Connection" admin screen: configure abilities + connect an agent.
 *
 * @package AgentConnectorForWp
 */

declare( strict_types=1 );

namespace AgentConnectorForWp\Admin;

use AgentConnectorForWp\Support\Config;
use AgentConnectorForWp\Support\Connection;
use WP_Application_Passwords;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * One screen to run the whole plugin.
 *
 * Registered unconditionally (even while the plugin is off) because it is the
 * only place to switch it on. It folds together what used to be two screens:
 *
 *   - Configure: the master Enable toggle (which runs the MCP server and exposes
 *     third-party abilities) and the production override.
 *   - Connect: mint an application password and render copy-paste connection
 *     artifacts for an MCP client.
 *
 * Plus domain-lock status / reconnect. The optional "Built-in abilities" status,
 * toggle, and warnings are injected by the separate Default Abilities plugin
 * through the agent_connector_for_wp_render_* hooks.
 *
 * Settings POSTs go through admin-post.php; the connection generator uses
 * admin-ajax so the secret never lands in page source.
 */
final class ConnectionPage {

	public const MENU_SLUG = 'agent-connector-for-wp';

	private const SAVE_ACTION      = 'agent_connector_for_wp_save_settings';
	private const RECONNECT_ACTION = 'agent_connector_for_wp_reconnect';
	private const AJAX_ACTION      = 'rfa_generate_connection';

	/**
	 * Page hook suffix, captured at registration so assets load only here.
	 */
	private string $hook_suffix = '';

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_' . self::SAVE_ACTION, array( $this, 'handle_save' ) );
		add_action( 'admin_post_' . self::RECONNECT_ACTION, array( $this, 'handle_reconnect' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'handle_generate' ) );
	}

	/**
	 * Register the single top-level "Connection" page.
	 */
	public function register_menu(): void {
		$this->hook_suffix = (string) add_menu_page(
			__( 'Agent Connector for WP', 'agent-connector-for-wp' ),
			__( 'Agent Connector for WP', 'agent-connector-for-wp' ),
			Config::CAP,
			self::MENU_SLUG,
			array( $this, 'render_page' ),
			'dashicons-superhero-alt',
			81
		);

		// Rename the auto-created first submenu item to "Connection".
		add_submenu_page(
			self::MENU_SLUG,
			__( 'Agent Connector — Connection', 'agent-connector-for-wp' ),
			__( 'Connection', 'agent-connector-for-wp' ),
			Config::CAP,
			self::MENU_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue the connection generator's inline script — only on this page.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( '' === $this->hook_suffix || $hook_suffix !== $this->hook_suffix ) {
			return;
		}

		wp_register_script( 'agent-connector-for-wp-connect', false, array(), AGENT_CONNECTOR_FOR_WP_VERSION, true );
		wp_enqueue_script( 'agent-connector-for-wp-connect' );

		wp_localize_script(
			'agent-connector-for-wp-connect',
			'AgentConnectorForWpConnect',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'action'  => self::AJAX_ACTION,
				'nonce'   => wp_create_nonce( self::AJAX_ACTION ),
				'i18n'    => array(
					'generating' => __( 'Generating…', 'agent-connector-for-wp' ),
					'button'     => __( 'Generate connection', 'agent-connector-for-wp' ),
					'copy'       => __( 'Copy', 'agent-connector-for-wp' ),
					'copied'     => __( 'Copied!', 'agent-connector-for-wp' ),
					'copyLink'   => __( 'Copy link', 'agent-connector-for-wp' ),
					'failed'     => __( 'Something went wrong. Please try again.', 'agent-connector-for-wp' ),
				),
			)
		);

		wp_add_inline_script( 'agent-connector-for-wp-connect', $this->inline_script() );
	}

	/**
	 * Render the Connection screen.
	 */
	public function render_page(): void {
		if ( ! current_user_can( Config::CAP ) ) {
			return;
		}

		$enabled         = Config::is_enabled();          // master toggle.
		$active          = Config::can_boot();             // MCP server actually running.
		$non_prod        = Config::is_non_production_env();
		$override        = Config::production_override_enabled();
		$prod_blocked    = Config::is_blocked_by_production();
		$env_type        = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'unknown';
		$mcp_debug       = Config::mcp_debug_enabled();
		$locked_host     = Config::locked_host();
		$declared_host   = Config::declared_host();
		$lock_mismatch   = $active && '' !== $locked_host && $locked_host !== $declared_host;
		$notice          = isset( $_GET['acfw_notice'] ) ? sanitize_key( wp_unslash( $_GET['acfw_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Agent Connector for WP — Connection', 'agent-connector-for-wp' ); ?></h1>

			<?php $this->render_status_notice( $notice ); ?>

			<p style="max-width:50em;">
				<?php esc_html_e( 'Agent Connector runs an MCP server for this site. When enabled, it exposes the abilities registered by other plugins over MCP.', 'agent-connector-for-wp' ); ?>
			</p>

			<h2><?php esc_html_e( 'Status', 'agent-connector-for-wp' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'MCP server', 'agent-connector-for-wp' ); ?></th>
					<td>
						<?php if ( $active ) : ?>
							<span style="color:#1a7f37;font-weight:600;"><?php esc_html_e( 'ACTIVE', 'agent-connector-for-wp' ); ?></span>
							<p class="description"><?php esc_html_e( 'The MCP server is running. Third-party abilities are exposed.', 'agent-connector-for-wp' ); ?></p>
						<?php elseif ( $prod_blocked ) : ?>
							<span style="color:#b32d2e;font-weight:600;"><?php esc_html_e( 'Enabled, but inactive', 'agent-connector-for-wp' ); ?></span>
							<p class="description"><?php esc_html_e( 'This is a production environment, so the Enable toggle alone does not activate it. Tick the production override below.', 'agent-connector-for-wp' ); ?></p>
						<?php else : ?>
							<span style="color:#646970;font-weight:600;"><?php esc_html_e( 'Disabled', 'agent-connector-for-wp' ); ?></span>
							<p class="description"><?php esc_html_e( 'The plugin is inert. Its MCP server exposes nothing.', 'agent-connector-for-wp' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
				<?php
				/**
				 * Fires inside the Status table, after the MCP server row.
				 *
				 * The Default Abilities plugin hooks this to render its "Built-in
				 * abilities" status row. Each callback must echo one or more
				 * <tr>…</tr> rows.
				 *
				 * @since 1.13.0
				 */
				do_action( 'agent_connector_for_wp_render_status_rows' );
				?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Environment type', 'agent-connector-for-wp' ); ?></th>
					<td>
						<code><?php echo esc_html( $env_type ); ?></code>
						<?php if ( ! $non_prod ) : ?>
							<span class="description"><?php esc_html_e( '— treated as production; enabling requires the override below.', 'agent-connector-for-wp' ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
			</table>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::SAVE_ACTION ); ?>" />
				<?php wp_nonce_field( self::SAVE_ACTION ); ?>
				<h2><?php esc_html_e( 'Abilities', 'agent-connector-for-wp' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Enable Agent Connector', 'agent-connector-for-wp' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="acfw_enabled" value="1" <?php checked( $enabled ); ?> />
								<?php esc_html_e( 'Run the MCP server for this site.', 'agent-connector-for-wp' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'Exposes abilities registered by other plugins (third-party abilities are always active while enabled). Also locks the plugin to this site\'s current domain (see below).', 'agent-connector-for-wp' ); ?>
							</p>
						</td>
					</tr>

					<?php if ( ! $non_prod ) : ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'Production override', 'agent-connector-for-wp' ); ?></th>
							<td>
								<div class="notice notice-error inline" style="margin:0 0 .9em;max-width:46em;">
									<p>
										<strong><?php esc_html_e( 'Danger:', 'agent-connector-for-wp' ); ?></strong>
										<?php esc_html_e( 'This site reports a production environment type. Running Agent Connector here exposes your site to agents over MCP, with no sandbox. Leave this unticked unless you fully accept that risk.', 'agent-connector-for-wp' ); ?>
									</p>
								</div>
								<label>
									<input type="checkbox" name="acfw_allow_production" value="1" <?php checked( $override ); ?> />
									<?php esc_html_e( 'I understand the risk — run Agent Connector on this production environment anyway.', 'agent-connector-for-wp' ); ?>
								</label>
							</td>
						</tr>
					<?php endif; ?>

					<?php
					/**
					 * Fires inside the Abilities settings table.
					 *
					 * The Default Abilities plugin hooks this to render its "Built-in
					 * abilities" warning + opt-in toggle. Each callback must echo one
					 * or more <tr>…</tr> rows.
					 *
					 * @since 1.13.0
					 */
					do_action( 'agent_connector_for_wp_render_settings_rows' );
					?>

					<tr>
						<th scope="row"><?php esc_html_e( 'Third-party abilities', 'agent-connector-for-wp' ); ?></th>
						<td>
							<p class="description" style="margin-top:.3em;max-width:46em;">
								<?php esc_html_e( 'Always active while the plugin is enabled: every ability registered by another plugin via the WordPress Abilities API is exposed over the MCP server. There is nothing to configure here — manage those from the plugins that provide them.', 'agent-connector-for-wp' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Debug', 'agent-connector-for-wp' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Debug', 'agent-connector-for-wp' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="acfw_mcp_debug" value="1" <?php checked( $mcp_debug ); ?> />
								<?php esc_html_e( 'Log MCP events to the database.', 'agent-connector-for-wp' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'Records every MCP request — including the raw JSON-RPC request and response bodies — and shows them on the MCP Events page. Bodies can contain sensitive data, so leave this off unless you are debugging.', 'agent-connector-for-wp' ); ?>
							</p>
						</td>
					</tr>
				</table>
				<?php submit_button( $enabled ? __( 'Save changes', 'agent-connector-for-wp' ) : __( 'Enable plugin', 'agent-connector-for-wp' ) ); ?>
			</form>

			<?php if ( $active ) : ?>
				<?php $this->render_domain_lock( $locked_host, $declared_host, $lock_mismatch ); ?>
				<?php $this->render_connect_section(); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Domain-lock status + reconnect.
	 */
	private function render_domain_lock( string $locked_host, string $declared_host, bool $lock_mismatch ): void {
		?>
		<hr />
		<h2><?php esc_html_e( 'Domain lock', 'agent-connector-for-wp' ); ?></h2>
		<p style="max-width:48em;">
			<?php esc_html_e( 'Agent Connector only runs on the domain it was enabled on. If this site is cloned or moved to another domain, its abilities are blocked until an administrator reconnects here — so a copied database (and its application passwords) can\'t silently grant access on a different site.', 'agent-connector-for-wp' ); ?>
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
		<?php
	}

	/**
	 * Connection generator: mint an app password + show copy-paste artifacts.
	 */
	private function render_connect_section(): void {
		$user         = wp_get_current_user();
		$pw_available = $this->app_passwords_available( $user instanceof \WP_User ? $user : null );
		$endpoint     = Connection::endpoint_url();

		$default_heads_up = __( 'This application password lets the agent act as you over MCP. Treat it like a credential. It is shown only once — copy it now.', 'agent-connector-for-wp' );
		/**
		 * Filters the connection "Heads up" text. The Default Abilities plugin
		 * strengthens it when its powerful abilities are exposed.
		 *
		 * @since 1.13.0
		 *
		 * @param string $heads_up The default heads-up message.
		 */
		$heads_up = (string) apply_filters( 'agent_connector_for_wp_connect_heads_up', $default_heads_up );
		?>
		<hr />
		<h2><?php esc_html_e( 'Connect an agent', 'agent-connector-for-wp' ); ?></h2>
		<p style="max-width:48em;">
			<?php esc_html_e( 'Generate everything a coding agent needs to connect over MCP: a WordPress application password, the server URL, and a copy-paste prompt or config.', 'agent-connector-for-wp' ); ?>
		</p>

		<div class="notice notice-warning inline" style="max-width:48em;">
			<p>
				<strong><?php esc_html_e( 'Heads up:', 'agent-connector-for-wp' ); ?></strong>
				<?php echo esc_html( $heads_up ); ?>
			</p>
		</div>

		<?php
		/**
		 * Fires after the connection heads-up notice. The Default Abilities plugin
		 * hooks this to warn when its abilities require super-admin access. Each
		 * callback echoes its own notice markup.
		 *
		 * @since 1.13.0
		 */
		do_action( 'agent_connector_for_wp_render_connect_notices' );
		?>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'MCP server URL', 'agent-connector-for-wp' ); ?></th>
				<td><code><?php echo esc_html( $endpoint ); ?></code></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Authenticating as', 'agent-connector-for-wp' ); ?></th>
				<td><code><?php echo esc_html( $user->user_login ); ?></code></td>
			</tr>
		</table>

		<?php if ( ! $pw_available ) : ?>
			<div class="notice notice-error inline" style="max-width:48em;">
				<p>
					<?php
					echo wp_kses(
						__( 'Application passwords are not available on this site. They require an HTTPS connection (or a local environment), and must not be disabled via the <code>wp_is_application_passwords_available</code> filter. Enable them, then reload this page.', 'agent-connector-for-wp' ),
						array( 'code' => array() )
					);
					?>
				</p>
			</div>
		<?php else : ?>
			<p>
				<button type="button" class="button button-primary button-hero" id="rfa-generate">
					<?php esc_html_e( 'Generate connection', 'agent-connector-for-wp' ); ?>
				</button>
			</p>
			<p class="description" id="rfa-status" role="status" aria-live="polite"></p>

			<div id="rfa-results" style="display:none;max-width:60em;">
				<h2 class="nav-tab-wrapper" id="rfa-agent-tabs" role="tablist"></h2>
				<div id="rfa-agent-blocks"></div>
			</div>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render the post-redirect status notice.
	 */
	private function render_status_notice( string $notice ): void {
		$messages = array(
			'enabled'      => array( 'success', __( 'Agent Connector is now enabled and locked to this domain.', 'agent-connector-for-wp' ) ),
			'disabled'     => array( 'success', __( 'Agent Connector is now disabled. The plugin is inert.', 'agent-connector-for-wp' ) ),
			'saved'        => array( 'success', __( 'Settings saved.', 'agent-connector-for-wp' ) ),
			'prod_blocked' => array( 'warning', __( 'Saved, but Agent Connector is inactive: this is a production environment. Tick the production override to activate it.', 'agent-connector-for-wp' ) ),
			'reconnected'  => array( 'success', __( 'Reconnected — abilities are allowed on this domain again.', 'agent-connector-for-wp' ) ),
			'safe_mode_cleared'     => array( 'success', __( 'Sandbox safe mode cleared. Sandbox files will load again on the next request.', 'agent-connector-for-wp' ) ),
			'safe_mode_clear_failed' => array( 'error', __( 'Could not clear sandbox safe mode — delete the ".crashed" marker file manually.', 'agent-connector-for-wp' ) ),
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
	 * admin-post: persist the toggles (and lock the domain on first enable).
	 */
	public function handle_save(): void {
		$this->verify( self::SAVE_ACTION );

		$was_enabled = Config::is_enabled();
		$enable      = isset( $_POST['acfw_enabled'] ) && '1' === $_POST['acfw_enabled']; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$allow_prod  = isset( $_POST['acfw_allow_production'] ) && '1' === $_POST['acfw_allow_production']; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$mcp_debug   = isset( $_POST['acfw_mcp_debug'] ) && '1' === $_POST['acfw_mcp_debug']; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		update_option( Config::ENABLED_OPTION, $enable, true );
		update_option( Config::PRODUCTION_OVERRIDE_OPTION, $allow_prod, true );
		update_option( Config::MCP_DEBUG_OPTION, $mcp_debug, true );

		/**
		 * Fires while saving the Connection screen settings, after the core options
		 * are persisted. The nonce and capability for this POST are already
		 * verified. The Default Abilities plugin hooks this to persist its toggle
		 * from $_POST.
		 *
		 * @since 1.13.0
		 */
		do_action( 'agent_connector_for_wp_settings_saved' );

		if ( $enable ) {
			if ( ! $was_enabled ) {
				Config::lock_to_current_host();
			}
			if ( ! Config::can_boot() ) {
				$this->redirect( 'prod_blocked' );
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
	 * AJAX: mint a fresh application password and return the connection artifacts.
	 */
	public function handle_generate(): void {
		if ( ! check_ajax_referer( self::AJAX_ACTION, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed. Reload the page and try again.', 'agent-connector-for-wp' ) ), 403 );
		}

		if ( ! current_user_can( Config::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'agent-connector-for-wp' ) ), 403 );
		}

		$user = wp_get_current_user();
		if ( ! ( $user instanceof \WP_User ) || ! $user->exists() ) {
			wp_send_json_error( array( 'message' => __( 'Could not determine the current user.', 'agent-connector-for-wp' ) ), 400 );
		}

		if ( ! $this->app_passwords_available( $user ) ) {
			wp_send_json_error( array( 'message' => __( 'Application passwords are not available on this site.', 'agent-connector-for-wp' ) ), 400 );
		}

		// A unique name per click; the operator chose "always create a new one".
		$name = sprintf(
			/* translators: %s: date/time the password was created. */
			__( 'Agent Connector for WP (MCP) — %s', 'agent-connector-for-wp' ),
			gmdate( 'Y-m-d H:i:s' )
		) . ' [' . wp_generate_password( 4, false ) . ']';

		$created = WP_Application_Passwords::create_new_application_password(
			$user->ID,
			array( 'name' => $name )
		);

		if ( $created instanceof WP_Error ) {
			wp_send_json_error( array( 'message' => $created->get_error_message() ), 500 );
		}

		// create_new_application_password() returns [ plaintext_password, item ].
		$password = is_array( $created ) ? (string) ( $created[0] ?? '' ) : '';
		if ( '' === $password ) {
			wp_send_json_error( array( 'message' => __( 'Failed to generate an application password.', 'agent-connector-for-wp' ) ), 500 );
		}

		$artifacts = Connection::build_artifacts( $user->user_login, $password );

		wp_send_json_success(
			array(
				'url'    => $artifacts['url'],
				'agents' => $artifacts['agents'],
			)
		);
	}

	/**
	 * Whether application passwords can be created for the given user.
	 */
	private function app_passwords_available( ?\WP_User $user ): bool {
		if ( ! class_exists( WP_Application_Passwords::class ) || ! function_exists( 'wp_is_application_passwords_available' ) ) {
			return false;
		}
		if ( ! wp_is_application_passwords_available() ) {
			return false;
		}
		if ( $user instanceof \WP_User && function_exists( 'wp_is_application_passwords_available_for_user' ) ) {
			return (bool) wp_is_application_passwords_available_for_user( $user );
		}
		return true;
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

	/**
	 * The connection generator's vanilla-JS behaviour (generate + copy).
	 */
	private function inline_script(): string {
		return <<<'JS'
( function () {
	var cfg = window.AgentConnectorForWpConnect || {};
	var i18n = cfg.i18n || {};
	var btn = document.getElementById( 'rfa-generate' );
	var status = document.getElementById( 'rfa-status' );
	var results = document.getElementById( 'rfa-results' );
	var tabs = document.getElementById( 'rfa-agent-tabs' );
	var blocks = document.getElementById( 'rfa-agent-blocks' );
	var agents = [];
	var uid = 0;

	function el( tag, props, text ) {
		var node = document.createElement( tag );
		if ( props ) {
			Object.keys( props ).forEach( function ( k ) { node.setAttribute( k, props[ k ] ); } );
		}
		if ( text != null ) { node.textContent = text; }
		return node;
	}

	// Copy text to the clipboard, flashing the button label on success.
	function copyValue( value, cb ) {
		var done = function () {
			var original = cb.getAttribute( 'data-label' ) || cb.textContent;
			cb.textContent = i18n.copied || 'Copied!';
			setTimeout( function () { cb.textContent = original; }, 1500 );
		};
		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( value ).then( done, function () { fallbackCopy( value, done ); } );
		} else {
			fallbackCopy( value, done );
		}
	}

	function fallbackCopy( value, done ) {
		var ta = document.createElement( 'textarea' );
		ta.value = value;
		ta.style.position = 'fixed';
		ta.style.opacity = '0';
		document.body.appendChild( ta );
		ta.select();
		try { document.execCommand( 'copy' ); } catch ( e ) {}
		document.body.removeChild( ta );
		done();
	}

	// Build the DOM for one block (textarea + copy, or a deeplink button).
	function renderBlock( block ) {
		var wrap = el( 'div', { 'class': 'rfa-block', 'style': 'margin-top:1.5em;' } );
		wrap.appendChild( el( 'h3', { 'style': 'margin-bottom:.2em;' }, block.title ) );
		if ( block.hint ) {
			wrap.appendChild( el( 'p', { 'class': 'description', 'style': 'margin-top:0;' }, block.hint ) );
		}

		if ( block.kind === 'deeplink' ) {
			var actions = el( 'p' );
			var link = el( 'a', { 'class': 'button button-primary', 'href': '#' }, block.button || ( i18n.copy || 'Copy' ) );
			link.setAttribute( 'href', block.value );
			actions.appendChild( link );
			actions.appendChild( document.createTextNode( ' ' ) );
			var linkCopy = el( 'button', { 'type': 'button', 'class': 'button' }, i18n.copyLink || 'Copy link' );
			linkCopy.setAttribute( 'data-label', i18n.copyLink || 'Copy link' );
			linkCopy.addEventListener( 'click', function () { copyValue( block.value, linkCopy ); } );
			actions.appendChild( linkCopy );
			wrap.appendChild( actions );
			return wrap;
		}

		var id = 'rfa-out-' + ( ++uid );
		var ta = el( 'textarea', { 'id': id, 'readonly': 'readonly', 'rows': '6', 'class': 'large-text code', 'style': 'font-family:monospace;' } );
		ta.value = block.value;
		wrap.appendChild( ta );
		var actions2 = el( 'p' );
		var copy = el( 'button', { 'type': 'button', 'class': 'button' }, i18n.copy || 'Copy' );
		copy.setAttribute( 'data-label', i18n.copy || 'Copy' );
		copy.addEventListener( 'click', function () { copyValue( ta.value, copy ); } );
		actions2.appendChild( copy );
		wrap.appendChild( actions2 );
		return wrap;
	}

	function selectAgent( index ) {
		var tabEls = tabs.querySelectorAll( '.nav-tab' );
		Array.prototype.forEach.call( tabEls, function ( t, i ) {
			var active = i === index;
			t.classList.toggle( 'nav-tab-active', active );
			t.setAttribute( 'aria-selected', active ? 'true' : 'false' );
		} );
		var agent = agents[ index ];
		blocks.innerHTML = '';
		if ( ! agent || ! agent.blocks ) { return; }
		agent.blocks.forEach( function ( block ) { blocks.appendChild( renderBlock( block ) ); } );
	}

	function renderTabs() {
		tabs.innerHTML = '';
		agents.forEach( function ( agent, i ) {
			var tab = el( 'a', { 'class': 'nav-tab', 'href': '#', 'role': 'tab' }, agent.label );
			tab.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				selectAgent( i );
			} );
			tabs.appendChild( tab );
		} );
	}

	if ( btn ) {
		btn.addEventListener( 'click', function () {
			btn.disabled = true;
			status.textContent = i18n.generating || 'Generating…';

			var body = new URLSearchParams();
			body.set( 'action', cfg.action );
			body.set( 'nonce', cfg.nonce );

			fetch( cfg.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
				body: body.toString()
			} )
			.then( function ( r ) { return r.json(); } )
			.then( function ( res ) {
				if ( ! res || ! res.success || ! res.data || ! res.data.agents ) {
					var msg = ( res && res.data && res.data.message ) || i18n.failed || 'Error';
					status.textContent = msg;
					btn.disabled = false;
					return;
				}
				agents = res.data.agents;
				renderTabs();
				selectAgent( 0 );
				results.style.display = 'block';
				status.textContent = '';
				btn.textContent = i18n.button || 'Generate connection';
				btn.disabled = false;
			} )
			.catch( function () {
				status.textContent = i18n.failed || 'Error';
				btn.disabled = false;
			} );
		} );
	}
} )();
JS;
	}
}
