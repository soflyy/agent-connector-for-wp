// ─── ChatGPT plugin (.zip) builder ───────────────────────────────────────────
//
// A ChatGPT plugin (https://developers.openai.com/plugins/build/plugins) is a
// folder in the Agent Plugins format: plugin.json at its root, mcp.json for
// the MCP servers it bundles, and assets/. Ours bundles one stdio server, the
// same @automattic/mcp-wordpress-remote proxy as the manual setup, through
// npx, so it needs Node.js.
//
// Like the .mcpb, the connection details, application password included, are
// written into the file so the upload is all there is to it. The file is
// therefore a credential, and the Connect page says so. It's built entirely
// client-side, so an existing password never leaves the browser.

import JSZip from 'jszip'
import { PROXY_PACKAGE, WEBSITE, buildIcon, saveFile } from './mcpb'

const PLUGIN_SCHEMA = 'https://agent-plugins.org/schemas/1.0.0/plugin.schema.json'
const MCP_SCHEMA = 'https://agent-plugins.org/schemas/1.0.0/mcp.schema.json'

const ICON = './assets/icon.png'

// The plugin name must be lowercase letters, digits, dots and single hyphens,
// at most 64 characters. The server name is already a lowercase slug, so this
// only guards the length and repeated hyphens.
export function pluginName(serverName) {
  return serverName.replace(/-{2,}/g, '-').slice(0, 64).replace(/[-.]+$/, '') || 'wordpress'
}

export function pluginFileName(serverName) {
  return `${pluginName(serverName)}-chatgpt-plugin.zip`
}

// The plugin's text files by path. Separate from buildChatgptPlugin() so they
// can be generated outside a browser (the icon needs a canvas).
export function chatgptPluginFiles({ serverName, serverUrl, siteName, username, password, prompts = [] }) {
  let host = serverUrl
  try { host = new URL(serverUrl).host } catch {}
  const label = siteName || host
  const description = `Gives the agent access to the WordPress site ${host} through Agent Connector for WP.`

  const manifest = {
    $schema: PLUGIN_SCHEMA,
    name: pluginName(serverName),
    version: '1.0.0',
    description,
    author: { name: 'Soflyy', url: WEBSITE },
    homepage: WEBSITE,
    extensions: {
      'com.openai': {
        interface: {
          displayName: `${label} (WordPress)`,
          shortDescription: `Build and edit ${host}`,
          longDescription: description,
          developerName: 'Soflyy',
          category: 'Productivity',
          capabilities: ['Read', 'Write'],
          websiteURL: WEBSITE,
          defaultPrompt: prompts,
          composerIcon: ICON,
          logo: ICON,
        },
      },
    },
  }

  const mcp = {
    $schema: MCP_SCHEMA,
    mcpServers: {
      [serverName]: {
        type: 'stdio',
        command: 'npx',
        args: ['-y', PROXY_PACKAGE],
        env: {
          WP_API_URL: serverUrl,
          WP_API_USERNAME: username,
          WP_API_PASSWORD: password,
          OAUTH_ENABLED: 'false',
        },
      },
    },
  }

  return {
    'plugin.json': JSON.stringify(manifest, null, 2),
    'mcp.json': JSON.stringify(mcp, null, 2),
  }
}

export async function buildChatgptPlugin(params) {
  const zip = new JSZip()
  for (const [path, content] of Object.entries(chatgptPluginFiles(params))) zip.file(path, content)
  zip.file('assets/icon.png', await buildIcon(params.siteIcon))
  return zip.generateAsync({ type: 'blob', compression: 'DEFLATE' })
}

export async function downloadChatgptPlugin(params) {
  saveFile(await buildChatgptPlugin(params), pluginFileName(params.serverName))
}
