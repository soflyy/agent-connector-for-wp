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
	 * A stable client name agents will use to label this server.
	 */
	public const CLIENT_NAME = 'wordpress-agent-connector-for-wp';

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
	 * Build every artifact the Connect page offers for copy-paste.
	 *
	 * @return array{
	 *     url:string,
	 *     username:string,
	 *     prompt:string,
	 *     cli:string,
	 *     json:string
	 * }
	 */
	public static function build_artifacts( string $username, string $password ): array {
		$url     = self::endpoint_url();
		$name    = self::CLIENT_NAME;
		$package = self::PROXY_PACKAGE;
		$env     = self::proxy_env( $username, $password );

		// 1) A natural-language prompt the user can paste into any coding agent.
		$prompt = implode(
			"\n",
			array(
				'Please configure a new MCP (Model Context Protocol) server for me, then connect to it.',
				'',
				'- Name: ' . $name,
				'- Transport: stdio, via Automattic\'s mcp-wordpress-remote proxy run with npx',
				'- Command: npx -y ' . $package,
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
				'shell, PHP, and filesystem access on the server.',
			)
		);

		// 2) A ready-to-run Claude Code CLI command (stdio server with env vars).
		$cli_parts = array( 'claude', 'mcp', 'add', escapeshellarg( $name ) );
		foreach ( $env as $key => $value ) {
			$cli_parts[] = '--env';
			$cli_parts[] = escapeshellarg( $key . '=' . $value );
		}
		$cli_parts[] = '--';
		$cli_parts[] = 'npx';
		$cli_parts[] = '-y';
		$cli_parts[] = escapeshellarg( $package );
		$cli         = implode( ' ', $cli_parts );

		// 3) A standard mcpServers JSON config block.
		$json = (string) wp_json_encode(
			array(
				'mcpServers' => array(
					$name => array(
						'command' => 'npx',
						'args'    => array( '-y', $package ),
						'env'     => $env,
					),
				),
			),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		);

		return array(
			'url'      => $url,
			'username' => $username,
			'prompt'   => $prompt,
			'cli'      => $cli,
			'json'     => $json,
		);
	}
}
