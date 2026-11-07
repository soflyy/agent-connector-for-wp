<?php
/**
 * Sandbox path model: where AI-written PHP lives and how the PHP-execution
 * boundary is enforced.
 *
 * @package AgentConnectorForWp
 */

declare( strict_types=1 );

namespace AgentConnectorForWp\Support;

defined( 'ABSPATH' ) || exit;

/**
 * The sandbox is an *operational* boundary for generated PHP, not a security
 * isolation boundary for all filesystem writes.
 *
 * It gives the plugin one place to load AI-written PHP "plugins" from, disable
 * them, and recover from crashes (the GitHub build's sandbox loader).
 * The wordpress.org build has no loader, so it refuses PHP writes altogether
 * (see php_writes_allowed()).
 * Authenticated administrators may still read, write, and delete *non-PHP* files anywhere on
 * the filesystem — that is part of the plugin's high-trust model. What this
 * class enforces is narrower: files that can directly affect PHP execution
 * (.php, plus a short list of execution-control files) may only be written
 * inside the sandbox directory, so generated code has a single, recoverable
 * home rather than being scattered across the install.
 */
final class Sandbox {

	/**
	 * Filenames (lowercased) that aren't .php but can still alter how PHP runs
	 * or what the web server serves. Agents can't write them anywhere: in the
	 * sandbox they could undo the deny rules below, and elsewhere they could
	 * change how the site runs PHP.
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
	 * Deny rules for Apache and LiteSpeed: the sandbox's PHP files are loaded by
	 * WordPress (the GitHub build's sandbox loader), never requested over the web. Servers
	 * that ignore .htaccess (nginx, IIS) are covered by the direct-access guard
	 * every sandbox PHP file must start with (see check_direct_access_guard()).
	 */
	private const HTACCESS = <<<'HTACCESS'
# Agent Connector: files in this folder are loaded by WordPress, never served directly.
<IfModule mod_authz_core.c>
	Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
	Order deny,allow
	Deny from all
</IfModule>

HTACCESS;

	/**
	 * The guard every sandbox PHP file must open with, so it stops when
	 * requested directly over the web instead of being loaded by WordPress.
	 */
	public const DIRECT_ACCESS_GUARD = "defined( 'ABSPATH' ) || exit;";

	/**
	 * Ensure the sandbox directory exists, with its deny rules. Best-effort:
	 * returns whether it's a directory afterwards.
	 */
	public static function ensure_dir(): bool {
		$dir = self::dir();
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return false;
		}

		$htaccess = $dir . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			// FS_CHMOD_FILE is only defined once wp-admin's filesystem API has loaded.
			self::filesystem()->put_contents( $htaccess, self::HTACCESS, defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : 0644 );
		}

		return true;
	}

	/**
	 * Whether this build lets agents write PHP files (into the sandbox). Only
	 * the GitHub build loads sandbox files; bin/build-plugin-zip.sh strips the
	 * marked lines from the wordpress.org build, leaving false.
	 */
	public static function php_writes_allowed(): bool {
		$allowed = false;
		// @github-build-start
		$allowed = true;
		// @github-build-end
		return $allowed;
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

		if ( in_array( strtolower( basename( $resolved ) ), self::PHP_EXECUTION_CONTROL_FILES, true ) ) {
			return new \WP_Error(
				'agent_connector_for_wp_execution_control_file',
				'Files that change how the server runs PHP or serves files (.htaccess, .user.ini, php.ini, web.config) cannot be written.',
				array( 'status' => 403 )
			);
		}

		if ( ! self::php_writes_allowed() ) {
			return new \WP_Error(
				'agent_connector_for_wp_php_writes_unavailable',
				'PHP files cannot be written with this version of Agent Connector.',
				array( 'status' => 403 )
			);
		}

		$sandbox = self::normalize_boundary( self::real_or_self( self::dir() ) );
		// Compare the *parent* directory so a brand-new file (which doesn't
		// resolve yet) is still judged by where it would land.
		$parent  = self::normalize_boundary( self::real_or_self( dirname( $resolved ) ) );

		if ( ! self::is_within( $parent, $sandbox ) ) {
			return new \WP_Error(
				'agent_connector_for_wp_php_sandbox_required',
				sprintf(
					'PHP files can only be written inside the sandbox directory: %1$s. Write generated PHP there instead — e.g. a path like "%2$smy-feature.php".',
					self::dir(),
					self::dir()
				),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Require a sandbox PHP file to stop when it's requested directly over the
	 * web: its first statement (after any declare(), namespace or use lines)
	 * must be an ABSPATH check that exits, such as
	 * `defined( 'ABSPATH' ) || exit;`.
	 *
	 * @param string $code The file's full contents after the write.
	 * @return true|\WP_Error
	 */
	public static function check_direct_access_guard( string $code ) {
		if ( self::starts_with_guard( $code ) ) {
			return true;
		}

		return new \WP_Error(
			'agent_connector_for_wp_php_guard_required',
			sprintf(
				'PHP files in the sandbox directory must stop when requested directly over the web. Make the first statement %1$s, after the opening <?php tag and any declare(), namespace or use lines, e.g. "<?php\n%1$s\n…".',
				self::DIRECT_ACCESS_GUARD
			),
			array( 'status' => 400 )
		);
	}

	/**
	 * Whether PHP code opens with an ABSPATH exit guard. Code with no PHP tags
	 * (plain text or HTML) passes: requesting it runs nothing.
	 */
	private static function starts_with_guard( string $code ): bool {
		$guard = '(?:defined\s*\(\s*[\'"]ABSPATH[\'"]\s*\)\s*(?:\|\||or)\s*(?:exit|die)\b'
			. '|if\s*\(\s*!\s*defined\s*\(\s*[\'"]ABSPATH[\'"]\s*\)\s*\)\s*\{?\s*(?:exit|die|return)\b)';

		if ( ! function_exists( 'token_get_all' ) ) {
			return 1 === preg_match( '/' . $guard . '/i', $code );
		}

		// Reduce the file to its statements: drop comments, whitespace, inline
		// HTML and the opening tags (an echo tag stays, since it runs code).
		$statements = '';
		$has_php    = false;
		foreach ( token_get_all( $code ) as $token ) {
			if ( is_array( $token ) ) {
				if ( T_OPEN_TAG === $token[0] || T_OPEN_TAG_WITH_ECHO === $token[0] ) {
					$has_php = true;
				}
				if ( in_array( $token[0], array( T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_INLINE_HTML ), true ) ) {
					continue;
				}
				$statements .= ( T_CLOSE_TAG === $token[0] ? ';' : $token[1] ) . ' ';
			} else {
				$statements .= $token . ' ';
			}
		}

		if ( ! $has_php ) {
			return true;
		}

		$preamble = '(?:(?:declare|namespace|use)\b[^;{]*;\s*)*';
		return 1 === preg_match( '/^\s*' . $preamble . $guard . '/i', $statements );
	}

	/**
	 * Direct filesystem access, for writing the sandbox's deny rules.
	 */
	private static function filesystem(): \WP_Filesystem_Direct {
		static $filesystem = null;
		if ( null === $filesystem ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
			$filesystem = new \WP_Filesystem_Direct( null );
		}
		return $filesystem;
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
