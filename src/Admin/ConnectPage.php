<?php
/**
 * Admin menu + "Connect" page.
 *
 * @package RootForAgents
 */

declare( strict_types=1 );

namespace RootForAgents\Admin;

use RootForAgents\Support\Connection;
use RootForAgents\Support\Config;
use WP_Application_Passwords;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the "Root for Agents" admin menu and its Connect page.
 *
 * The Connect page gives the operator a single button that does everything
 * needed to hand a coding agent access: it mints a fresh WordPress application
 * password, figures out the MCP endpoint URL, and renders ready-to-paste
 * instructions (a natural-language prompt, a Claude Code CLI command, and an
 * mcpServers JSON block).
 */
final class ConnectPage {

	private const MENU_SLUG = 'root-for-agents';
	private const AJAX_ACTION = 'rfa_generate_connection';

	/**
	 * The page's hook suffix, captured at registration so assets load only here.
	 */
	private string $hook_suffix = '';

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'handle_generate' ) );
	}

	/**
	 * Add the top-level menu and Connect page.
	 */
	public function register_menu(): void {
		$this->hook_suffix = (string) add_menu_page(
			__( 'Root for Agents', 'root-for-agents' ),
			__( 'Root for Agents', 'root-for-agents' ),
			Config::CAP,
			self::MENU_SLUG,
			array( $this, 'render_page' ),
			'dashicons-superhero-alt',
			81
		);

		// Give the submenu item a friendlier label than the menu title.
		add_submenu_page(
			self::MENU_SLUG,
			__( 'Connect an Agent', 'root-for-agents' ),
			__( 'Connect', 'root-for-agents' ),
			Config::CAP,
			self::MENU_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Register and enqueue the page's inline script — only on the Connect page.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( $hook_suffix !== $this->hook_suffix || '' === $this->hook_suffix ) {
			return;
		}

		wp_register_script( 'root-for-agents-connect', false, array(), ROOT_FOR_AGENTS_VERSION, true );
		wp_enqueue_script( 'root-for-agents-connect' );

		wp_localize_script(
			'root-for-agents-connect',
			'RootForAgentsConnect',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'action'  => self::AJAX_ACTION,
				'nonce'   => wp_create_nonce( self::AJAX_ACTION ),
				'i18n'    => array(
					'generating' => __( 'Generating…', 'root-for-agents' ),
					'button'     => __( 'Generate connection', 'root-for-agents' ),
					'copy'       => __( 'Copy', 'root-for-agents' ),
					'copied'     => __( 'Copied!', 'root-for-agents' ),
					'failed'     => __( 'Something went wrong. Please try again.', 'root-for-agents' ),
				),
			)
		);

		wp_add_inline_script( 'root-for-agents-connect', $this->inline_script() );
	}

	/**
	 * Render the Connect page shell. Secrets are injected client-side after the
	 * operator clicks the button, so nothing sensitive is ever in page source.
	 */
	public function render_page(): void {
		if ( ! current_user_can( Config::CAP ) ) {
			return;
		}

		$user           = wp_get_current_user();
		$pw_available   = $this->app_passwords_available( $user instanceof \WP_User ? $user : null );
		$is_super_admin = function_exists( 'is_super_admin' ) && is_super_admin( $user->ID );
		$endpoint       = Connection::endpoint_url();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Connect an Agent', 'root-for-agents' ); ?></h1>

			<p style="max-width:48em;">
				<?php esc_html_e( 'Generate everything a coding agent needs to connect to this site over MCP: a WordPress application password, the MCP server URL, and a copy-paste prompt or config. Click the button, then hand the result to Claude (or any MCP-capable agent).', 'root-for-agents' ); ?>
			</p>

			<div class="notice notice-warning inline" style="max-width:48em;">
				<p>
					<strong><?php esc_html_e( 'Heads up:', 'root-for-agents' ); ?></strong>
					<?php esc_html_e( 'Anyone holding this application password can run shell commands, evaluate PHP, and read/write files on this server as you. Treat it like an SSH key. The password is shown only once — copy it now.', 'root-for-agents' ); ?>
				</p>
			</div>

			<?php if ( ! $is_super_admin ) : ?>
				<div class="notice notice-error inline" style="max-width:48em;">
					<p>
						<?php esc_html_e( 'Your account is not a super admin. Root for Agents abilities require super-admin access, so the generated connection will be rejected when the agent tries to use them. Sign in as a super admin first.', 'root-for-agents' ); ?>
					</p>
				</div>
			<?php endif; ?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'MCP server URL', 'root-for-agents' ); ?></th>
					<td><code><?php echo esc_html( $endpoint ); ?></code></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Authenticating as', 'root-for-agents' ); ?></th>
					<td><code><?php echo esc_html( $user->user_login ); ?></code></td>
				</tr>
			</table>

			<?php if ( ! $pw_available ) : ?>
				<div class="notice notice-error inline" style="max-width:48em;">
					<p>
						<?php
						echo wp_kses(
							__( 'Application passwords are not available on this site. They require an HTTPS connection (or a local environment), and must not be disabled via the <code>wp_is_application_passwords_available</code> filter. Enable them, then reload this page.', 'root-for-agents' ),
							array( 'code' => array() )
						);
						?>
					</p>
				</div>
			<?php else : ?>
				<p>
					<button type="button" class="button button-primary button-hero" id="rfa-generate">
						<?php esc_html_e( 'Generate connection', 'root-for-agents' ); ?>
					</button>
				</p>
				<p class="description" id="rfa-status" role="status" aria-live="polite"></p>

				<div id="rfa-results" style="display:none;max-width:60em;">
					<?php
					$this->render_result_block(
						'rfa-prompt',
						__( 'Paste into your agent', 'root-for-agents' ),
						__( 'A plain-English prompt for Claude or any coding agent — it will set up the MCP server itself.', 'root-for-agents' )
					);
					$this->render_result_block(
						'rfa-cli',
						__( 'Claude Code CLI', 'root-for-agents' ),
						__( 'Run this in your terminal to add the server to Claude Code.', 'root-for-agents' )
					);
					$this->render_result_block(
						'rfa-json',
						__( 'mcpServers JSON', 'root-for-agents' ),
						__( 'Drop this into an MCP client config file (e.g. .mcp.json).', 'root-for-agents' )
					);
					?>
				</div>
			<?php endif; ?>
		</div>
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
			<h2 style="margin-bottom:.2em;"><?php echo esc_html( $title ); ?></h2>
			<p class="description" style="margin-top:0;"><?php echo esc_html( $hint ); ?></p>
			<textarea id="<?php echo esc_attr( $id ); ?>" readonly rows="6" class="large-text code" style="font-family:monospace;"></textarea>
			<p>
				<button type="button" class="button rfa-copy" data-target="<?php echo esc_attr( $id ); ?>">
					<?php esc_html_e( 'Copy', 'root-for-agents' ); ?>
				</button>
			</p>
		</div>
		<?php
	}

	/**
	 * AJAX: mint a fresh application password and return the connection artifacts.
	 */
	public function handle_generate(): void {
		if ( ! check_ajax_referer( self::AJAX_ACTION, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed. Reload the page and try again.', 'root-for-agents' ) ), 403 );
		}

		if ( ! current_user_can( Config::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'root-for-agents' ) ), 403 );
		}

		$user = wp_get_current_user();
		if ( ! ( $user instanceof \WP_User ) || ! $user->exists() ) {
			wp_send_json_error( array( 'message' => __( 'Could not determine the current user.', 'root-for-agents' ) ), 400 );
		}

		if ( ! $this->app_passwords_available( $user ) ) {
			wp_send_json_error( array( 'message' => __( 'Application passwords are not available on this site.', 'root-for-agents' ) ), 400 );
		}

		// A unique name per click; the operator chose "always create a new one".
		$name = sprintf(
			/* translators: %s: date/time the password was created. */
			__( 'Root for Agents (MCP) — %s', 'root-for-agents' ),
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
			wp_send_json_error( array( 'message' => __( 'Failed to generate an application password.', 'root-for-agents' ) ), 500 );
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
	 * The page's vanilla-JS behaviour (generate + copy), kept dependency-free.
	 */
	private function inline_script(): string {
		return <<<'JS'
( function () {
	var cfg = window.RootForAgentsConnect || {};
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
