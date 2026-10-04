// ─── ChatGPT plugin (.zip) builder ───────────────────────────────────────────
//
// A ChatGPT plugin (https://developers.openai.com/plugins/build/plugins) to
// upload in ChatGPT's Plugins. It comes in two forms, each in the layout that
// ChatGPT's upload accepted in testing:
//
// - Application password: the Agent Plugins layout, plugin.json and mcp.json
//   at the root, with one stdio server running the same
//   @automattic/mcp-wordpress-remote proxy as the manual setup, through npx
//   (so it needs Node.js and ChatGPT's desktop app). Like the .mcpb, the
//   connection details, password included, are written into the file, which
//   is therefore a credential; the Connect page says so.
// - OAuth (no password): Codex's native layout, .codex-plugin/plugin.json and
//   .mcp.json, pointing ChatGPT at this site's MCP endpoint. ChatGPT signs in
//   over OAuth, and the file holds no credential at all.
//
// On sites running Oxygen 6 or Breakdance, either form also carries the
// builder-kit skills from the soflyy/skills marketplace (see ./builderKit.js)
// in skills/, where both layouts pick them up.
//
// Built entirely client-side, so an existing password never leaves the
// browser.

import JSZip from 'jszip'
import { fetchBuilderKitSkills } from './builderKit'
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

  const identity = {
    name: pluginName(serverName),
    version: '1.0.0',
    description,
    author: { name: 'Soflyy', url: WEBSITE },
    homepage: WEBSITE,
  }
  const ui = {
    displayName: `${label} (WordPress)`,
    shortDescription: `Build and edit ${host}`,
    longDescription: description,
    developerName: 'Soflyy',
    category: 'Productivity',
    capabilities: ['Read', 'Write'],
    websiteURL: WEBSITE,
  }
  const icons = { composerIcon: ICON, logo: ICON }

  if (!password) {
    return {
      '.codex-plugin/plugin.json': JSON.stringify({
        ...identity,
        mcpServers: './.mcp.json',
        interface: { ...ui, ...(prompts.length ? { defaultPrompt: prompts[0] } : {}), ...icons },
      }, null, 2),
      '.mcp.json': JSON.stringify({
        mcpServers: { [serverName]: { type: 'http', url: serverUrl } },
      }, null, 2),
    }
  }

  return {
    'plugin.json': JSON.stringify({
      $schema: PLUGIN_SCHEMA,
      ...identity,
      extensions: { 'com.openai': { interface: { ...ui, defaultPrompt: prompts, ...icons } } },
    }, null, 2),
    'mcp.json': JSON.stringify({
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
    }, null, 2),
  }
}

export async function buildChatgptPlugin(params) {
  const zip = new JSZip()
  for (const [path, content] of Object.entries(chatgptPluginFiles(params))) zip.file(path, content)
  zip.file('assets/icon.png', await buildIcon(params.siteIcon))
  if (params.builderKit) {
    // The plugin still works without the skills, so GitHub being unreachable
    // doesn't stop the download.
    try {
      for (const [path, content] of Object.entries(await fetchBuilderKitSkills())) zip.file(`skills/${path}`, content)
    } catch (e) {
      console.warn('Building the ChatGPT plugin without the builder-kit skills:', e)
    }
  }
  return zip.generateAsync({ type: 'blob', compression: 'DEFLATE' })
}

export async function downloadChatgptPlugin(params) {
  saveFile(await buildChatgptPlugin(params), pluginFileName(params.serverName))
}
