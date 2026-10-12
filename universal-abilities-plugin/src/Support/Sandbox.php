<?php
/**
 * Sandbox path model: where AI-written PHP lives and how the PHP-execution
 * boundary is enforced.
 *
 * @package AgentConnectorForWpDefaultAbilities
 */

declare( strict_types=1 );

namespace AgentConnectorForWp\DefaultAbilities\Support;

defined( 'ABSPATH' ) || exit;

/**
 * The sandbox is an *operational* boundary for generated PHP, not a security
 * isolation boundary for all filesystem writes.
 *
 * It gives the plugin one place to load AI-written PHP "plugins" from, disable
 * them, and recover from crashes (see Services\SandboxLoader). Authenticated
 * administrators may still read, write, and delete *non-PHP* files anywhere on
 * the filesystem — that is part of the plugin's high-trust model. What this
 * class enforces is narrower: files that can directly affect PHP execution
 * (.php, plus a short list of execution-control files) may only be written
 * inside the sandbox directory, so generated code has a single, recoverable
 * home rather than being scattered across the install.
 */
final class Sandbox {

	/**
	 * Filenames (lowercased) that aren't .php but can still alter how PHP runs,
	 * so they are confined to the sandbox like .php files.
	 *
	 * @var array<int,string>
	 */
	private const PHP_EXECUTION_CONTROL_FILES = array(
		'.htaccess',
		'.user.ini',
		'php.ini',
		'.php.ini',
		'web.config',
	);

	/**
	 * Absolute path to the sandbox directory, with a trailing slash.
	 *
	 * Override with the AGENT_CONNECTOR_FOR_WP_SANDBOX_DIR constant; otherwise it
	 * lives under wp-content so it survives core/plugin updates.
	 */
	public static function dir(): string {
		if ( defined( 'AGENT_CONNECTOR_FOR_WP_SANDBOX_DIR' ) && is_string( AGENT_CONNECTOR_FOR_WP_SANDBOX_DIR ) ) {
			return trailingslashit( AGENT_CONNECTOR_FOR_WP_SANDBOX_DIR );
		}
		return rtrim( WP_CONTENT_DIR, '/\\' ) . '/agent-connector-for-wp-sandbox/';
	}

	/**
	 * Ensure the sandbox directory exists. Best-effort: returns whether it's a
	 * directory afterwards.
	 */
	public static function ensure_dir(): bool {
		$dir = self::dir();
		if ( is_dir( $dir ) ) {
			return true;
		}
		return (bool) wp_mkdir_p( $dir );
	}

	/**
	 * Whether a path can directly affect PHP execution and must therefore stay
	 * inside the sandbox.
	 *
	 * Deliberately narrow — do not broaden this to every writable file, or the
	 * general filesystem abilities stop working. It's only .php and the handful
	 * of files that change how PHP itself runs.
	 */
	public static function path_requires_sandbox( string $path ): bool {
		$extension = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );
		if ( 'php' === $extension ) {
			return true;
		}

		$filename = strtolower( basename( $path ) );
		return in_array( $filename, self::PHP_EXECUTION_CONTROL_FILES, true );
	}

	/**
	 * Enforce the PHP-execution boundary for a resolved write target.
	 *
	 * Returns true for ordinary (non-PHP) paths — those are allowed anywhere by
	 * design. For PHP-execution paths it returns an agent-actionable WP_Error
	 * unless the file's parent directory is inside the sandbox.
	 *
	 * @param string $resolved Absolute, resolved destination path.
	 * @return true|\WP_Error
	 */
	public static function check_php_execution_boundary( string $resolved ) {
		if ( ! self::path_requires_sandbox( $resolved ) ) {
			return true;
		}

		$sandbox = self::normalize_boundary( self::real_or_self( self::dir() ) );
		// Compare the *parent* directory so a brand-new file (which doesn't
		// resolve yet) is still judged by where it would land.
		$parent  = self::normalize_boundary( self::real_or_self( dirname( $resolved ) ) );

		if ( ! self::is_within( $parent, $sandbox ) ) {
			return new \WP_Error(
				'agent_connector_for_wp_php_sandbox_required',
				sprintf(
					'PHP files and PHP-execution control files (.php, .htaccess, .user.ini, php.ini, web.config) can only be written inside the sandbox directory: %1$s. Write generated PHP there instead — e.g. a path like "%2$smy-feature.php".',
					self::dir(),
					self::dir()
				),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Reject a write whose final path is a symlink, so an agent can't be tricked
	 * (or trick itself) into writing through a link that escapes the intended
	 * target.
	 *
	 * @param string $resolved Absolute, resolved destination path.
	 * @return true|\WP_Error
	 */
	public static function reject_final_symlink( string $resolved ) {
		if ( ! is_link( $resolved ) ) {
			return true;
		}

		return new \WP_Error(
			'agent_connector_for_wp_symlink_write_rejected',
			sprintf( 'Refusing to write through a symlinked path: %s', $resolved ),
			array( 'status' => 403 )
		);
	}

	/**
	 * realpath() with a graceful fallback to the input when the path doesn't
	 * exist yet (new files, sandbox not created).
	 */
	private static function real_or_self( string $path ): string {
		$real = realpath( $path );
		return false === $real ? $path : $real;
	}

	/**
	 * Normalize separators and strip a trailing slash for boundary comparisons.
	 */
	private static function normalize_boundary( string $path ): string {
		$normalized = rtrim( str_replace( '\\', '/', $path ), '/' );
		return '' === $normalized ? '/' : $normalized;
	}

	/**
	 * Whether a normalized path is the boundary directory itself or sits under it.
	 */
	private static function is_within( string $path, string $directory ): bool {
		if ( $path === $directory ) {
			return true;
		}
		if ( '/' === $directory ) {
			return str_starts_with( $path, '/' );
		}
		return str_starts_with( $path, $directory . '/' );
	}
}
