<?php
/**
 * Loads AI-written PHP "plugins" from the sandbox directory each request, with
 * crash recovery (safe mode) and an admin recovery flow.
 *
 * @package AgentConnectorForWp
 */

declare( strict_types=1 );

namespace AgentConnectorForWp\Services;

use AgentConnectorForWp\Support\Config;
use AgentConnectorForWp\Support\Sandbox;

defined( 'ABSPATH' ) || exit;

/**
 * Auto-loads every *.php file in the sandbox directory, while protecting the
 * site from a sandbox file that fatals.
 *
 * Crash recovery works via register_shutdown_function: while a sandbox file is
 * loading we record which file it is, and on shutdown we check error_get_last()
 * for a fatal. If one occurred we drop a `.crashed` marker (JSON) and, on every
 * subsequent request, enter SAFE MODE — skipping all sandbox files — until an
 * administrator fixes/deletes the offending file and clears the marker.
 *
 * The currently-loading file is tracked so a fatal thrown *deeper* in the call
 * chain (a sandbox file calling into core or a third-party plugin that fatals)
 * is still attributed to the sandbox file that triggered it.
 */
final class SandboxLoader {

	/**
	 * Query parameter to force safe mode for a single request without writing a
	 * marker (e.g. to load wp-admin and clear a marker manually).
	 */
	private const SAFE_MODE_PARAM = 'acfw_safe_mode';

	/**
	 * admin-post action that deletes the .crashed marker to leave safe mode.
	 */
	public const CLEAR_ACTION = 'agent_connector_for_wp_clear_safe_mode';

	/**
	 * The sandbox file currently being required, or null when no file is loading.
	 *
	 * A reference to this is closed over by the shutdown handler; setting it back
	 * to null after the load loop makes the handler a no-op for unrelated fatals.
	 */
	private ?string $current_file = null;

	/**
	 * Run the loader. Called once, early, on boot.
	 *
	 * Registers the admin notice + recovery handler unconditionally (so operators
	 * can always recover), then either skips loading (safe mode) or loads the
	 * sandbox files with crash detection armed.
	 */
	public function run(): void {
		add_action( 'admin_post_' . self::CLEAR_ACTION, array( $this, 'handle_clear_safe_mode' ) );

		if ( ! Sandbox::ensure_dir() ) {
			return;
		}

		$is_safe_mode = $this->is_safe_mode();

		// Tell operators why their sandbox files aren't running, and how to recover.
		if ( $is_safe_mode ) {
			add_action( 'admin_notices', array( $this, 'render_safe_mode_notice' ) );
		}

		if ( $is_safe_mode ) {
			return;
		}

		$files = glob( Sandbox::dir() . '*.php' );
		if ( empty( $files ) ) {
			return;
		}

		register_shutdown_function( array( $this, 'detect_crash_on_shutdown' ) );

		foreach ( $files as $file ) {
			$this->current_file = $file;
			require_once $file;
		}

		// Loading completed cleanly; disarm the crash handler for the rest of the
		// request so an unrelated later fatal isn't blamed on the sandbox.
		$this->current_file = null;
	}

	/**
	 * Whether this request should run in safe mode: a persisted `.crashed` marker
	 * exists, or safe mode was forced via the query parameter.
	 */
	public function is_safe_mode(): bool {
		if ( file_exists( $this->crashed_marker_path() ) ) {
			return true;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only toggle, no state change.
		return isset( $_GET[ self::SAFE_MODE_PARAM ] ) && '1' === $_GET[ self::SAFE_MODE_PARAM ];
	}

	/**
	 * Shutdown callback: if a sandbox file never returned control to the load
	 * loop, persist a `.crashed` marker so the next request enters safe mode.
	 */
	public function detect_crash_on_shutdown(): void {
		// No sandbox file was loading when the request ended → not our crash.
		if ( null === $this->current_file ) {
			return;
		}

		// Reaching shutdown with $current_file still set means the
		// require_once() of that file never returned control to the loop.
		// That happens for a genuine PHP fatal (E_ERROR/E_PARSE/...), but
		// just as easily for a deliberate exit()/die() in the file's own
		// top-level code — e.g. a stray `if (php_sapi_name() !== 'cli') {
		// exit(...); }` guard, which is not a PHP error at all. Both halt
		// every future request identically, so both must trip safe mode;
		// only checking error_get_last() misses the exit()/die() case
		// entirely and leaves the site down with no recovery.
		$error = error_get_last();
		$fatal = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR;
		$is_fatal_error = null !== $error && 0 !== ( (int) $error['type'] & $fatal );

		if ( $is_fatal_error ) {
			$data = $error;
		} else {
			$data = array(
				'message' => 'Sandbox file ended the request early (exit/die) instead of returning control to the loader.',
			);
		}
		$data['sandbox_file'] = $this->current_file;

		$json = wp_json_encode( $data );
		if ( false === $json ) {
			$json = '{}';
		}

		// Suppress errors: we're already in shutdown after a fatal.
		@file_put_contents( $this->crashed_marker_path(), $json, LOCK_EX ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
	}

	/**
	 * Admin notice shown to capable users while safe mode is active, explaining
	 * what crashed and how to recover.
	 */
	public function render_safe_mode_notice(): void {
		if ( ! current_user_can( Config::CAP ) ) {
			return;
		}

		$marker  = $this->crashed_marker_path();
		$crashed = file_exists( $marker );

		$details = '';
		if ( $crashed ) {
			$raw  = (string) @file_get_contents( $marker ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
			$data = json_decode( $raw, true );
			if ( is_array( $data ) && ! empty( $data['sandbox_file'] ) ) {
				$file    = (string) $data['sandbox_file'];
				$message = isset( $data['message'] ) ? (string) $data['message'] : '';
				$details = sprintf(
					' %s <code>%s</code>%s',
					esc_html__( 'The crash was attributed to:', 'agent-connector-for-wp' ),
					esc_html( $file ),
					'' === $message ? '' : ' — ' . esc_html( $message )
				);
			}
		}

		$clear_button = '';
		if ( $crashed ) {
			$clear_button = sprintf(
				'<form method="post" action="%1$s" style="display:inline;margin-left:.5em;"><input type="hidden" name="action" value="%2$s" />%3$s%4$s</form>',
				esc_url( admin_url( 'admin-post.php' ) ),
				esc_attr( self::CLEAR_ACTION ),
				wp_nonce_field( self::CLEAR_ACTION, '_wpnonce', true, false ),
				sprintf(
					'<button type="submit" class="button button-small">%s</button>',
					esc_html__( 'Clear safe mode', 'agent-connector-for-wp' )
				)
			);
		}

		printf(
			'<div class="notice notice-error"><p><strong>%1$s</strong> %2$s%3$s</p><p>%4$s%5$s</p></div>',
			esc_html__( 'Agent Connector for WP — sandbox safe mode is active.', 'agent-connector-for-wp' ),
			esc_html__( 'A sandbox PHP file caused a fatal error, so all sandbox files are temporarily disabled to keep the site up.', 'agent-connector-for-wp' ),
			wp_kses( $details, array( 'code' => array() ) ),
			esc_html__( 'To recover: fix or delete the broken file in the sandbox directory, then clear safe mode by deleting the ".crashed" marker (or use the button).', 'agent-connector-for-wp' ),
			wp_kses(
				$clear_button,
				array(
					'form'   => array(
						'method' => array(),
						'action' => array(),
						'style'  => array(),
					),
					'input'  => array(
						'type'  => array(),
						'name'  => array(),
						'value' => array(),
						'id'    => array(),
					),
					'button' => array(
						'type'  => array(),
						'class' => array(),
					),
				)
			)
		);
	}

	/**
	 * admin-post: delete the `.crashed` marker to leave safe mode.
	 *
	 * Mirrors ConnectionPage's handler style: cap + nonce check, then redirect
	 * back with a status flag.
	 */
	public function handle_clear_safe_mode(): void {
		if ( ! current_user_can( Config::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'agent-connector-for-wp' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::CLEAR_ACTION );

		$marker = $this->crashed_marker_path();
		$ok     = ! file_exists( $marker ) || @unlink( $marker ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => 'agent-connector-for-wp',
					'acfw_notice' => $ok ? 'safe_mode_cleared' : 'safe_mode_clear_failed',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Absolute path to the `.crashed` marker.
	 */
	private function crashed_marker_path(): string {
		return Sandbox::dir() . '.crashed';
	}
}
