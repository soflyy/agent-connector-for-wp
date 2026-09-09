<?php
/**
 * Runtime check for the canonical "MCP Adapter" plugin this plugin depends on.
 *
 * @package AgentConnectorForWp
 */

declare( strict_types=1 );

namespace AgentConnectorForWp\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Agent Connector no longer bundles wordpress/mcp-adapter. It depends on the
 * canonical MCP Adapter plugin, declared with the `Requires Plugins` header in
 * agent-connector-for-wp.php — the integration path the adapter project
 * recommends (https://github.com/WordPress/mcp-adapter/pull/288 and
 * docs/getting-started/installation.md in that repo).
 *
 * The header does the heavy lifting: WordPress refuses to activate this plugin
 * until MCP Adapter is installed and active, offers to install it from
 * wordpress.org, and refuses to deactivate it while this plugin is running.
 * Nothing here needs to install or update the adapter.
 *
 * What the header does not cover is a site that updated from a release which
 * still bundled the adapter: it stays active with the dependency suddenly
 * missing. This class is the runtime check for that case — the "checking
 * availability with code" path from the adapter's installation guide — so the
 * bootstrap can skip adapter-dependent wiring instead of fataling, and
 * Admin\McpAdapterNotice can tell the operator what to do.
 */
final class McpAdapterPlugin {

	/**
	 * Oldest adapter release this plugin is known to work with. Matches the
	 * `^0.5` Composer constraint the bundled copy used to carry.
	 */
	public const MIN_VERSION = '0.5.0';

	/** The adapter is loaded and new enough. */
	public const STATUS_READY = 'ready';

	/** No adapter classes are loaded at all (plugin not installed, or inactive). */
	public const STATUS_MISSING = 'missing';

	/** The adapter is loaded but older than MIN_VERSION. */
	public const STATUS_OUTDATED = 'outdated';

	/**
	 * Whether the adapter's core class is loaded, whoever provides it.
	 *
	 * Reliable from `plugins_loaded` onwards: the MCP Adapter plugin registers
	 * its autoloader while its main file loads, so the class resolves as soon
	 * as every active plugin has been included.
	 */
	public static function is_loaded(): bool {
		return class_exists( '\\WP\\MCP\\Core\\McpAdapter' );
	}

	/**
	 * The loaded adapter's version, or null when it cannot be determined.
	 *
	 * The plugin defines WP_MCP_VERSION while its main file loads. Older
	 * releases, or a copy some other plugin still bundles as a library, may not
	 * define it before `plugins_loaded`; the class constant is the fallback.
	 */
	public static function version(): ?string {
		if ( defined( 'WP_MCP_VERSION' ) && is_string( \WP_MCP_VERSION ) && '' !== \WP_MCP_VERSION ) {
			return \WP_MCP_VERSION;
		}

		if ( self::is_loaded() && defined( '\\WP\\MCP\\Core\\McpAdapter::VERSION' ) ) {
			$version = constant( '\\WP\\MCP\\Core\\McpAdapter::VERSION' );
			if ( is_string( $version ) && '' !== $version ) {
				return $version;
			}
		}

		return null;
	}

	/**
	 * One of the STATUS_* constants.
	 *
	 * An adapter whose version cannot be read is treated as ready: the class is
	 * there, and refusing to run on an unknown version would only turn a
	 * working site into a broken one.
	 */
	public static function status(): string {
		if ( ! self::is_loaded() ) {
			return self::STATUS_MISSING;
		}

		$version = self::version();
		if ( null !== $version && version_compare( $version, self::MIN_VERSION, '<' ) ) {
			return self::STATUS_OUTDATED;
		}

		return self::STATUS_READY;
	}

	/**
	 * Whether the adapter can be used for MCP server wiring right now.
	 */
	public static function is_ready(): bool {
		return self::STATUS_READY === self::status();
	}
}
