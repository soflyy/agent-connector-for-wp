<?php
/**
 * Computes the MCP endpoint and builds the copy-paste "connect" artifacts.
 *
 * @package AgentConnectorForWp
 */

declare( strict_types=1 );

namespace AgentConnectorForWp\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Knows where the MCP server lives and how to phrase a connection for an agent.
 *
 * Agent Connector for WP reuses the MCP Adapter's *default* server rather than
 * registering its own. Its abilities (all flagged mcp.public=true) are surfaced
 * there automatically, reachable through the adapter's discover/execute tools.
 *
 * The connection artifacts drive Automattic's mcp-wordpress-remote proxy
 * (https://www.npmjs.com/package/@automattic/mcp-wordpress-remote), a small
 * stdio MCP server the agent runs locally via npx. The proxy connects to this
 * site's MCP endpoint and authenticates with the operator's WordPress
 * application password. We use the proxy (rather than a direct HTTP transport)
 * because it's the broadly-supported way to reach a WordPress MCP server from
 * clients that only speak stdio, and it handles auth/transport details for us.
 */
final class Connection {

	/**
	 * Fallback client name when the site name can't be slugified.
	 */
	public const CLIENT_NAME = 'wordpress-agent-connector-for-wp';

	/**
	 * The MCP server name agents use to label this server.
	 *
	 * Derived from the site name (slugified) so each connected site gets a
	 * recognizable, distinct entry. Falls back to CLIENT_NAME when the site
	 * name is empty or slugifies to nothing (e.g. non-Latin titles).
	 */
	public static function server_name(): string {
		$slug = sanitize_title( (string) get_bloginfo( 'name' ) );

		return '' !== $slug ? $slug . '-wp' : self::CLIENT_NAME;
	}

	/**
	 * The npm package providing the stdio proxy the agent runs locally.
	 */
	public const PROXY_PACKAGE = '@automattic/mcp-wordpress-remote';

	/**
	 * The MCP Adapter default server's REST namespace and route.
	 *
	 * These mirror WP\MCP\Servers\DefaultServerFactory's defaults. If the
	 * default server is relocated via the adapter's filters, override the final
	 * URL with the 'agent_connector_for_wp_mcp_endpoint_url' filter below.
	 */
	private const DEFAULT_NAMESPACE = 'mcp';
	private const DEFAULT_ROUTE     = 'mcp-adapter-default-server';

	/**
	 * Absolute URL of the MCP (Streamable HTTP) endpoint.
	 */
	public static function endpoint_url(): string {
		$url = rest_url( self::DEFAULT_NAMESPACE . '/' . self::DEFAULT_ROUTE );

		/**
		 * Filters the MCP endpoint URL advertised on the Connect page.
		 *
		 * Override this if you have relocated the MCP Adapter default server via
		 * the 'mcp_adapter_default_server_config' filter.
		 *
		 * @param string $url The default server endpoint URL.
		 */
		return (string) apply_filters( 'agent_connector_for_wp_mcp_endpoint_url', $url );
	}

	/**
	 * Environment variables the mcp-wordpress-remote proxy needs.
	 *
	 * WP_API_URL must be the full endpoint path (the proxy treats a bare domain
	 * as a legacy install). OAUTH_ENABLED is disabled because we authenticate
	 * with an application password via the proxy's legacy auth path rather than
	 * the interactive OAuth 2.1 flow.
	 *
	 * @return array<string,string>
	 */
	public static function proxy_env( string $username, string $password ): array {
		return array(
			'WP_API_URL'      => self::endpoint_url(),
			'WP_API_USERNAME' => $username,
			'WP_API_PASSWORD' => $password,
			'OAUTH_ENABLED'   => 'false',
		);
	}

	/**
	 * The shared inner MCP server entry: how to launch the stdio proxy.
	 *
	 * This is the object every "mcpServers"-style client nests under the server
	 * name, and the payload Cursor/VS Code deeplinks carry. Kept in one place so
	 * the command/args never drift between formats.
	 *
	 * @param array<string,string> $env Proxy environment variables.
	 * @return array{command:string,args:array<int,string>,env:array<string,string>}
	 */
	private static function server_entry( array $env ): array {
		return array(
			'command' => 'npx',
			'args'    => array( '-y', self::PROXY_PACKAGE ),
			'env'     => $env,
		);
	}

	/**
	 * A natural-language prompt any coding agent can act on to self-configure.
	 *
	 * @param array<string,string> $env Proxy environment variables.
	 */
	private static function agent_prompt( string $name, array $env ): string {
		return implode(
			"\n",
			array(
				'Please configure a new MCP (Model Context Protocol) server for me, then connect to it.',
				'',
				'- Name: ' . $name,
				'- Transport: stdio, via Automattic\'s mcp-wordpress-remote proxy run with npx',
				'- Command: npx -y ' . self::PROXY_PACKAGE,
				'- Environment variables:',
				'    WP_API_URL=' . $env['WP_API_URL'],
				'    WP_API_USERNAME=' . $env['WP_API_USERNAME'],
				'    WP_API_PASSWORD=' . $env['WP_API_PASSWORD'],
				'    OAUTH_ENABLED=false',
				'',
				'This is a WordPress site exposing its abilities over MCP. The proxy connects to it and',
				'authenticates with the WordPress application password above. Once connected, list the',
				'available tools to confirm it works. Use the "discover-abilities" tool to see what this',
				'site can do and "execute-ability" to run one — including agent-connector-for-wp/shell-exec,',
				'agent-connector-for-wp/php-eval, and the file-read/write/list/delete abilities, which give you',
				'shell, PHP, and filesystem access on the server. To drive the WordPress admin in a browser,',
				'run agent-connector-for-wp/create-admin-login-link and open the returned URL: it logs the',
				'browser into wp-admin once, without a username or password.',
			)
		);
	}

	/**
	 * A standard "mcpServers" JSON config block (Claude Desktop, Cursor, Windsurf…).
	 *
	 * @param array<string,string> $env Proxy environment variables.
	 */
	private static function mcp_servers_json( string $name, array $env ): string {
		return (string) wp_json_encode(
			array(
				'mcpServers' => array(
					$name => self::server_entry( $env ),
				),
			),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		);
	}

	/**
	 * A ready-to-run `claude mcp add` command (stdio server with env vars).
	 *
	 * @param array<string,string> $env Proxy environment variables.
	 */
	private static function claude_code_cli( string $name, array $env ): string {
		$parts = array( 'claude', 'mcp', 'add', escapeshellarg( $name ) );
		foreach ( $env as $key => $value ) {
			$parts[] = '--env';
			$parts[] = escapeshellarg( $key . '=' . $value );
		}
		$parts[] = '--';
		$parts[] = 'npx';
		$parts[] = '-y';
		$parts[] = escapeshellarg( self::PROXY_PACKAGE );

		return implode( ' ', $parts );
	}

	/**
	 * A `gemini mcp add` command. Env flags (`-e KEY=VAL`) precede the name.
	 *
	 * @param array<string,string> $env Proxy environment variables.
	 */
	private static function gemini_cli( string $name, array $env ): string {
		$parts = array( 'gemini', 'mcp', 'add' );
		foreach ( $env as $key => $value ) {
			$parts[] = '-e';
			$parts[] = escapeshellarg( $key . '=' . $value );
		}
		$parts[] = escapeshellarg( $name );
		$parts[] = 'npx';
		$parts[] = '-y';
		$parts[] = escapeshellarg( self::PROXY_PACKAGE );

		return implode( ' ', $parts );
	}

	/**
	 * VS Code's install JSON: the server entry plus a top-level "name" key.
	 *
	 * Shared by both the `code --add-mcp` command and the vscode: deeplink.
	 *
	 * @param array<string,string> $env Proxy environment variables.
	 */
	private static function vscode_config_json( string $name, array $env ): string {
		return (string) wp_json_encode(
			array( 'name' => $name ) + self::server_entry( $env ),
			JSON_UNESCAPED_SLASHES
		);
	}

	/**
	 * A `code --add-mcp '{…}'` command (single-quoted JSON for the shell).
	 *
	 * @param array<string,string> $env Proxy environment variables.
	 */
	private static function vscode_cli( string $name, array $env ): string {
		return 'code --add-mcp ' . escapeshellarg( self::vscode_config_json( $name, $env ) );
	}

	/**
	 * A one-click VS Code install deeplink: vscode:mcp/install?<url-encoded JSON>.
	 *
	 * @param array<string,string> $env Proxy environment variables.
	 */
	private static function vscode_deeplink( string $name, array $env ): string {
		return 'vscode:mcp/install?' . rawurlencode( self::vscode_config_json( $name, $env ) );
	}

	/**
	 * A one-click Cursor install deeplink.
	 *
	 * cursor://anysphere.cursor-deeplink/mcp/install?name=<name>&config=<base64 server entry>.
	 * The config carries only the inner server entry (no name key).
	 *
	 * @param array<string,string> $env Proxy environment variables.
	 */
	private static function cursor_deeplink( string $name, array $env ): string {
		$config = base64_encode( (string) wp_json_encode( self::server_entry( $env ), JSON_UNESCAPED_SLASHES ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode

		return 'cursor://anysphere.cursor-deeplink/mcp/install?name=' . rawurlencode( $name ) . '&config=' . rawurlencode( $config );
	}

	/**
	 * Build every artifact the Connect page offers, grouped per agent.
	 *
	 * Each agent has one or more "blocks"; a block is a copyable string or a
	 * one-click deeplink. The shape is consumed verbatim by the Connect page's
	 * inline script, which renders a selector + the chosen agent's blocks.
	 *
	 * @return array{
	 *     url:string,
	 *     username:string,
	 *     agents:array<int,array{id:string,label:string,blocks:array<int,array<string,string>>}>
	 * }
	 */
	public static function build_artifacts( string $username, string $password ): array {
		$url  = self::endpoint_url();
		$name = self::server_name();
		$env  = self::proxy_env( $username, $password );

		$agents = array(
			array(
				'id'     => 'prompt',
				'label'  => __( 'Any agent (prompt)', 'agent-connector-for-wp' ),
				'blocks' => array(
					array(
						'kind'  => 'text',
						'title' => __( 'Paste into your agent', 'agent-connector-for-wp' ),
						'hint'  => __( 'A plain-English prompt for Claude or any coding agent — it will set up the MCP server itself.', 'agent-connector-for-wp' ),
						'value' => self::agent_prompt( $name, $env ),
					),
				),
			),
			array(
				'id'     => 'claude-code',
				'label'  => __( 'Claude Code', 'agent-connector-for-wp' ),
				'blocks' => array(
					array(
						'kind'  => 'command',
						'title' => __( 'Claude Code CLI', 'agent-connector-for-wp' ),
						'hint'  => __( 'Run this in your terminal to add the server to Claude Code. It runs the mcp-wordpress-remote proxy via npx (Node.js required).', 'agent-connector-for-wp' ),
						'value' => self::claude_code_cli( $name, $env ),
					),
				),
			),
			array(
				'id'     => 'claude-desktop',
				'label'  => __( 'Claude Desktop', 'agent-connector-for-wp' ),
				'blocks' => array(
					array(
						'kind'        => 'plugin',
						'title'       => __( 'Claude Plugin', 'agent-connector-for-wp' ),
						'hint'        => __( 'Download the plugin ZIP and install it in Claude — no JSON editing required.', 'agent-connector-for-wp' ),
						'filename'    => $name . '.zip',
						'mcp_json'    => array(
							$name => array(
								'type'    => 'stdio',
								'command' => 'npx',
								'args'    => array( '-y', self::PROXY_PACKAGE ),
								'env'     => $env,
							),
						),
						'plugin_json' => array(
							'name'        => $name,
							'description' => 'WordPress MCP connection for ' . (string) get_bloginfo( 'name' ),
							'author'      => array( 'name' => (string) get_bloginfo( 'name' ) ),
						),
					),
					array(
						'kind'  => 'json',
						'title' => __( 'Or add manually', 'agent-connector-for-wp' ),
						'hint'  => __( 'Add to claude_desktop_config.json (Settings → Developer → Edit Config), then restart.', 'agent-connector-for-wp' ),
						'value' => self::mcp_servers_json( $name, $env ),
					),
				),
			),
			array(
				'id'     => 'cursor',
				'label'  => __( 'Cursor', 'agent-connector-for-wp' ),
				'blocks' => array(
					array(
						'kind'   => 'deeplink',
						'title'  => __( 'One-click install', 'agent-connector-for-wp' ),
						'hint'   => __( 'Opens Cursor and pre-fills the server config for you to approve.', 'agent-connector-for-wp' ),
						'button' => __( 'Add to Cursor', 'agent-connector-for-wp' ),
						'value'  => self::cursor_deeplink( $name, $env ),
					),
					array(
						'kind'  => 'json',
						'title' => __( 'Or add manually', 'agent-connector-for-wp' ),
						'hint'  => __( 'Add this to ~/.cursor/mcp.json (or .cursor/mcp.json in your project).', 'agent-connector-for-wp' ),
						'value' => self::mcp_servers_json( $name, $env ),
					),
				),
			),
			array(
				'id'     => 'vscode',
				'label'  => __( 'VS Code', 'agent-connector-for-wp' ),
				'blocks' => array(
					array(
						'kind'   => 'deeplink',
						'title'  => __( 'One-click install', 'agent-connector-for-wp' ),
						'hint'   => __( 'Opens VS Code and pre-fills the server config for you to approve.', 'agent-connector-for-wp' ),
						'button' => __( 'Add to VS Code', 'agent-connector-for-wp' ),
						'value'  => self::vscode_deeplink( $name, $env ),
					),
					array(
						'kind'  => 'command',
						'title' => __( 'Or run in your terminal', 'agent-connector-for-wp' ),
						'hint'  => __( 'Adds the server to your VS Code user profile via the code CLI.', 'agent-connector-for-wp' ),
						'value' => self::vscode_cli( $name, $env ),
					),
				),
			),
			array(
				'id'     => 'gemini',
				'label'  => __( 'Gemini CLI', 'agent-connector-for-wp' ),
				'blocks' => array(
					array(
						'kind'  => 'command',
						'title' => __( 'Gemini CLI', 'agent-connector-for-wp' ),
						'hint'  => __( 'Run this in your terminal to add the server to the Gemini CLI. It runs the mcp-wordpress-remote proxy via npx (Node.js required).', 'agent-connector-for-wp' ),
						'value' => self::gemini_cli( $name, $env ),
					),
				),
			),
			array(
				'id'     => 'windsurf',
				'label'  => __( 'Windsurf', 'agent-connector-for-wp' ),
				'blocks' => array(
					array(
						'kind'  => 'json',
						'title' => __( 'mcpServers JSON', 'agent-connector-for-wp' ),
						'hint'  => __( 'Add this to ~/.codeium/windsurf/mcp_config.json, then refresh MCP servers in Windsurf.', 'agent-connector-for-wp' ),
						'value' => self::mcp_servers_json( $name, $env ),
					),
				),
			),
		);

		return array(
			'url'      => $url,
			'username' => $username,
			'agents'   => $agents,
		);
	}
}
