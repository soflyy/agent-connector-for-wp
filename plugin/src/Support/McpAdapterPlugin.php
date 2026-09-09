<?php
/**
 * Runtime check for the canonical "MCP Adapter" plugin this plugin depends on.
 *
 * @package AgentConnectorForWp
 */

declare( strict_types=1 );

namespace AgentConnectorForWp\Support;

use AgentConnectorForWp\Services\PluginDirectory;

defined( 'ABSPATH' ) || exit;

/**
 * Agent Connector no longer bundles wordpress/mcp-adapter. It depends on the
 * canonical MCP Adapter plugin, which is the integration path the adapter
 * project recommends (https://github.com/WordPress/mcp-adapter/pull/288 and
 * docs/getting-started/installation.md in that repo).
 *
 * The adapter's preferred mechanism, a `Requires Plugins: mcp-adapter` header,
 * only works for plugins listed on wordpress.org, and MCP Adapter is not there
 * yet (WordPress/mcp-adapter#178). Declaring it now would make this plugin
 * impossible to activate with no install link offered. Until the listing
 * exists, this plugin follows the guide's "checking availability with code"
 * path instead: a `plugins_loaded` check for the WP\MCP\Core\McpAdapter class,
 * a WP_MCP_VERSION floor, and an admin notice while either fails. Once the
 * adapter is on wordpress.org, add the header to agent-connector-for-wp.php
 * and this class keeps working as the runtime check behind it.
 *
 * Because the adapter is not on wordpress.org, this plugin also offers to
 * fetch the release zip from the adapter's GitHub Releases itself, using the
 * same URL the adapter's installation guide gives for WP-CLI (see
 * PluginDirectory::mcp_adapter_download_url() and Admin\McpAdapterNotice).
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

	/**
	 * Whether the MCP Adapter plugin is installed but not active. Distinguishes
	 * "activate the copy you have" from "download it" in the admin notice.
	 */
	public static function is_installed_inactive(): bool {
		return null !== PluginDirectory::mcp_adapter_file() && ! PluginDirectory::is_mcp_adapter_active();
	}
}
