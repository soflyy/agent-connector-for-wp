<?php
/**
 * Computes the MCP endpoint and builds the copy-paste "connect" artifacts.
 *
 * @package RootForAgents
 */

declare( strict_types=1 );

namespace RootForAgents\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Knows where the MCP server lives and how to phrase a connection for an agent.
 *
 * Root for Agents reuses the MCP Adapter's *default* server rather than
 * registering its own. Its abilities (all flagged mcp.public=true) are surfaced
 * there automatically, reachable through the adapter's discover/execute tools.
 */
final class Connection {

	/**
	 * A stable client name agents will use to label this server.
	 */
	public const CLIENT_NAME = 'wordpress-root-for-agents';

	/**
	 * The MCP Adapter default server's REST namespace and route.
	 *
	 * These mirror WP\MCP\Servers\DefaultServerFactory's defaults. If the
	 * default server is relocated via the adapter's filters, override the final
	 * URL with the 'root_for_agents_mcp_endpoint_url' filter below.
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
		return (string) apply_filters( 'root_for_agents_mcp_endpoint_url', $url );
	}

	/**
	 * Build the Basic auth token (base64 of "user:application-password").
	 *
	 * Application passwords are transmitted exactly like a regular password over
	 * HTTP Basic auth; WordPress core authenticates them on REST requests.
	 */
	public static function basic_token( string $username, string $password ): string {
		return base64_encode( $username . ':' . $password );
	}

	/**
	 * Build every artifact the Connect page offers for copy-paste.
	 *
	 * @return array{
	 *     url:string,
	 *     username:string,
	 *     auth_header:string,
	 *     prompt:string,
	 *     cli:string,
	 *     json:string
	 * }
	 */
	public static function build_artifacts( string $username, string $password ): array {
		$url         = self::endpoint_url();
		$token       = self::basic_token( $username, $password );
		$auth_header = 'Basic ' . $token;
		$name        = self::CLIENT_NAME;

		// 1) A natural-language prompt the user can paste into any coding agent.
		$prompt = implode(
			"\n",
			array(
				'Please configure a new MCP (Model Context Protocol) server for me, then connect to it.',
				'',
				'- Name: ' . $name,
				'- Transport: Streamable HTTP',
				'- URL: ' . $url,
				'- Required HTTP header: Authorization: ' . $auth_header,
				'',
				'This is a WordPress site exposing its abilities over MCP. Once connected, list the',
				'available tools to confirm it works. Use the "mcp-adapter/discover-abilities" tool to',
				'see what this site can do and "mcp-adapter/execute-ability" to run an ability — including',
				'root-for-agents/shell-exec, root-for-agents/php-eval, and the file-read/write/list/delete',
				'abilities, which give you shell, PHP, and filesystem access on the server.',
			)
		);

		// 2) A ready-to-run Claude Code CLI command.
		$cli = sprintf(
			'claude mcp add --transport http %s %s --header %s',
			escapeshellarg( $name ),
			escapeshellarg( $url ),
			escapeshellarg( 'Authorization: ' . $auth_header )
		);

		// 3) A standard mcpServers JSON config block.
		$json = (string) wp_json_encode(
			array(
				'mcpServers' => array(
					$name => array(
						'type'    => 'http',
						'url'     => $url,
						'headers' => array(
							'Authorization' => $auth_header,
						),
					),
				),
			),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		);

		return array(
			'url'         => $url,
			'username'    => $username,
			'auth_header' => $auth_header,
			'prompt'      => $prompt,
			'cli'         => $cli,
			'json'        => $json,
		);
	}
}
