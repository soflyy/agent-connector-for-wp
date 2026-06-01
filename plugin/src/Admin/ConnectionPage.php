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
 *     third-party abilities), the production override, and the opt-in toggle for
 *     this plugin's own powerful "built-in" abilities (off by default).
 *   - Connect: mint an application password and render copy-paste connection
 *     artifacts for an MCP client.
 *
 * Plus domain-lock status / reconnect. Settings POSTs go through admin-post.php;
 * the connection generator uses admin-ajax so the secret never lands in page
 * source.
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
		$builtin_enabled = Config::builtin_abilities_enabled();
		$builtin_active  = Config::builtin_abilities_active();
		$locked_host     = Config::locked_host();
		$declared_host   = Config::declared_host();
		$lock_mismatch   = $active && '' !== $locked_host && $locked_host !== $declared_host;
		$notice          = isset( $_GET['acfw_notice'] ) ? sanitize_key( wp_unslash( $_GET['acfw_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Agent Connector for WP — Connection', 'agent-connector-for-wp' ); ?></h1>

			<?php $this->render_status_notice( $notice ); ?>

			<p style="max-width:50em;">
				<?php esc_html_e( 'Agent Connector runs an MCP server for this site. When enabled, it exposes the abilities registered by other plugins; its own powerful built-in abilities (shell, PHP, filesystem, WP-CLI) are a separate opt-in below.', 'agent-connector-for-wp' ); ?>
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
				<tr>
					<th scope="row"><?php esc_html_e( 'Built-in abilities', 'agent-connector-for-wp' ); ?></th>
					<td>
						<?php if ( $builtin_active ) : ?>
							<span style="color:#996800;font-weight:600;"><?php esc_html_e( 'ON', 'agent-connector-for-wp' ); ?></span>
							<p class="description"><?php esc_html_e( 'Shell, PHP eval, filesystem, WP-CLI, env-inspect and admin-login abilities are exposed.', 'agent-connector-for-wp' ); ?></p>
						<?php elseif ( $builtin_enabled && ! $active ) : ?>
							<span style="color:#646970;font-weight:600;"><?php esc_html_e( 'On, but inactive', 'agent-connector-for-wp' ); ?></span>
							<p class="description"><?php esc_html_e( 'Will be exposed once the MCP server is active.', 'agent-connector-for-wp' ); ?></p>
						<?php else : ?>
							<span style="color:#646970;font-weight:600;"><?php esc_html_e( 'Off', 'agent-connector-for-wp' ); ?></span>
							<p class="description"><?php esc_html_e( 'The plugin\'s own abilities are not exposed.', 'agent-connector-for-wp' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
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

			<h2><?php esc_html_e( 'Abilities', 'agent-connector-for-wp' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::SAVE_ACTION ); ?>" />
				<?php wp_nonce_field( self::SAVE_ACTION ); ?>
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

					<tr>
						<th scope="row"><?php esc_html_e( 'Built-in abilities', 'agent-connector-for-wp' ); ?></th>
						<td>
							<div class="notice notice-warning inline" style="margin:0 0 .9em;max-width:46em;">
								<p>
									<strong><?php esc_html_e( 'Powerful:', 'agent-connector-for-wp' ); ?></strong>
									<?php esc_html_e( 'These are Agent Connector\'s own abilities — arbitrary shell commands, PHP evaluation, filesystem read/write, WP-CLI, and a one-time admin login link. They grant admin-equivalent control of this site to anyone holding an application password. Off by default; enable only when you want an agent to have that access.', 'agent-connector-for-wp' ); ?>
								</p>
							</div>
							<label>
								<input type="checkbox" name="acfw_builtin_abilities" value="1" <?php checked( $builtin_enabled ); ?> />
								<?php esc_html_e( 'Expose Agent Connector\'s built-in abilities over MCP.', 'agent-connector-for-wp' ); ?>
							</label>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'Third-party abilities', 'agent-connector-for-wp' ); ?></th>
						<td>
							<p class="description" style="margin-top:.3em;max-width:46em;">
								<?php esc_html_e( 'Always active while the plugin is enabled: every ability registered by another plugin via the WordPress Abilities API is exposed over the MCP server. There is nothing to configure here — manage those from the plugins that provide them.', 'agent-connector-for-wp' ); ?>
							</p>
						</td>
					</tr>
				</table>
				<?php submit_button( $enabled ? __( 'Save changes', 'agent-connector-for-wp' ) : __( 'Enable plugin', 'agent-connector-for-wp' ) ); ?>
			</form>

			<?php if ( $active ) : ?>
				<?php $this->render_domain_lock( $locked_host, $declared_host, $lock_mismatch ); ?>
				<?php $this->render_connect_section( $builtin_active ); ?>
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
			<?php esc_html_e( 'Agent Connector only runs on the domain it was enabled on. If this site is cloned or moved to another domain, its built-in abilities are blocked until an administrator reconnects here — so a copied database (and its application passwords) can\'t silently grant access on a different site.', 'agent-connector-for-wp' ); ?>
		</p>

		<?php if ( $lock_mismatch ) : ?>
			<div class="notice notice-error inline" style="max-width:48em;">
				<p>
					<strong><?php esc_html_e( 'Domain mismatch — built-in abilities are blocked.', 'agent-connector-for-wp' ); ?></strong>
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
	 *
	 * @param bool $builtin_active Whether built-in abilities are exposed (changes the warning).
	 */
	private function render_connect_section( bool $builtin_active ): void {
		$user           = wp_get_current_user();
		$pw_available   = $this->app_passwords_available( $user instanceof \WP_User ? $user : null );
		$is_super_admin = function_exists( 'is_super_admin' ) && is_super_admin( $user->ID );
		$endpoint       = Connection::endpoint_url();
		?>
		<hr />
		<h2><?php esc_html_e( 'Connect an agent', 'agent-connector-for-wp' ); ?></h2>
		<p style="max-width:48em;">
			<?php esc_html_e( 'Generate everything a coding agent needs to connect over MCP: a WordPress application password, the server URL, and a copy-paste prompt or config.', 'agent-connector-for-wp' ); ?>
		</p>

		<div class="notice notice-warning inline" style="max-width:48em;">
			<p>
				<strong><?php esc_html_e( 'Heads up:', 'agent-connector-for-wp' ); ?></strong>
				<?php
				if ( $builtin_active ) {
					esc_html_e( 'Built-in abilities are on, so anyone holding this application password can run shell commands, evaluate PHP, and read/write files on this server as you. Treat it like an SSH key. The password is shown only once — copy it now.', 'agent-connector-for-wp' );
				} else {
					esc_html_e( 'This application password lets the agent act as you over MCP. Treat it like a credential. It is shown only once — copy it now.', 'agent-connector-for-wp' );
				}
				?>
			</p>
		</div>

		<?php if ( ! $is_super_admin && $builtin_active ) : ?>
			<div class="notice notice-error inline" style="max-width:48em;">
				<p><?php esc_html_e( 'Your account is not a super admin. The built-in abilities require super-admin access, so they will reject the connection. Sign in as a super admin first.', 'agent-connector-for-wp' ); ?></p>
			</div>
		<?php endif; ?>

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
				<?php
				$this->render_result_block(
					'rfa-prompt',
					__( 'Paste into your agent', 'agent-connector-for-wp' ),
					__( 'A plain-English prompt for Claude or any coding agent — it will set up the MCP server itself.', 'agent-connector-for-wp' )
				);
				$this->render_result_block(
					'rfa-cli',
					__( 'Claude Code CLI', 'agent-connector-for-wp' ),
					__( 'Run this in your terminal to add the server to Claude Code. It runs the mcp-wordpress-remote proxy via npx (Node.js required).', 'agent-connector-for-wp' )
				);
				$this->render_result_block(
					'rfa-json',
					__( 'mcpServers JSON', 'agent-connector-for-wp' ),
					__( 'Drop this into an MCP client config file (e.g. .mcp.json). It launches the mcp-wordpress-remote proxy via npx (Node.js required).', 'agent-connector-for-wp' )
				);
				?>
			</div>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render one labelled, copyable result block.
	 *
	 * @param string $id    Base element id.
	 * @param string $title Section heading.
	 * @param string $hint  Short description.
	 */
	private function render_result_block( string $id, string $title, string $hint ): void {
		?>
		<div class="rfa-block" style="margin-top:1.5em;">
			<h3 style="margin-bottom:.2em;"><?php echo esc_html( $title ); ?></h3>
			<p class="description" style="margin-top:0;"><?php echo esc_html( $hint ); ?></p>
			<textarea id="<?php echo esc_attr( $id ); ?>" readonly rows="6" class="large-text code" style="font-family:monospace;"></textarea>
			<p>
				<button type="button" class="button rfa-copy" data-target="<?php echo esc_attr( $id ); ?>">
					<?php esc_html_e( 'Copy', 'agent-connector-for-wp' ); ?>
				</button>
			</p>
		</div>
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
		$builtin     = isset( $_POST['acfw_builtin_abilities'] ) && '1' === $_POST['acfw_builtin_abilities']; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		update_option( Config::ENABLED_OPTION, $enable, true );
		update_option( Config::PRODUCTION_OVERRIDE_OPTION, $allow_prod, true );
		update_option( Config::BUILTIN_ABILITIES_OPTION, $builtin, true );

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
				'prompt' => $artifacts['prompt'],
				'cli'    => $artifacts['cli'],
				'json'   => $artifacts['json'],
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
	var btn = document.getElementById( 'rfa-generate' );
	var status = document.getElementById( 'rfa-status' );
	var results = document.getElementById( 'rfa-results' );

	function setText( id, value ) {
		var el = document.getElementById( id );
		if ( el ) { el.value = value; }
	}

	if ( btn ) {
		btn.addEventListener( 'click', function () {
			btn.disabled = true;
			status.textContent = ( cfg.i18n && cfg.i18n.generating ) || 'Generating…';

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
				if ( ! res || ! res.success ) {
					var msg = ( res && res.data && res.data.message ) || ( cfg.i18n && cfg.i18n.failed ) || 'Error';
					status.textContent = msg;
					btn.disabled = false;
					return;
				}
				setText( 'rfa-prompt', res.data.prompt );
				setText( 'rfa-cli', res.data.cli );
				setText( 'rfa-json', res.data.json );
				results.style.display = 'block';
				status.textContent = '';
				btn.textContent = ( cfg.i18n && cfg.i18n.button ) || 'Generate connection';
				btn.disabled = false;
			} )
			.catch( function () {
				status.textContent = ( cfg.i18n && cfg.i18n.failed ) || 'Error';
				btn.disabled = false;
			} );
		} );
	}

	var copyButtons = document.querySelectorAll( '.rfa-copy' );
	Array.prototype.forEach.call( copyButtons, function ( cb ) {
		cb.addEventListener( 'click', function () {
			var target = document.getElementById( cb.getAttribute( 'data-target' ) );
			if ( ! target ) { return; }
			var done = function () {
				var original = cb.textContent;
				cb.textContent = ( cfg.i18n && cfg.i18n.copied ) || 'Copied!';
				setTimeout( function () { cb.textContent = original; }, 1500 );
			};
			if ( navigator.clipboard && navigator.clipboard.writeText ) {
				navigator.clipboard.writeText( target.value ).then( done, function () {
					target.select();
					document.execCommand( 'copy' );
					done();
				} );
			} else {
				target.select();
				document.execCommand( 'copy' );
				done();
			}
		} );
	} );
} )();
JS;
	}
}
