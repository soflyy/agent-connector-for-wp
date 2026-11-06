// ─── ChatGPT plugin (.zip) builder ───────────────────────────────────────────
//
// Uses Codex's native layout (.codex-plugin/plugin.json + .mcp.json) for both
// sign-in methods; a root plugin.json would hide large tools. Password ZIPs
// embed the credential; OAuth ZIPs hold none. Built client-side.

import JSZip from 'jszip'
import { PROXY_PACKAGE, WEBSITE, buildIcon, saveFile } from './mcpb.js'

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
    version: '1.0.1',
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

  const server = password ? {
    type: 'stdio',
    command: 'npx',
    args: ['-y', PROXY_PACKAGE],
    env: {
      WP_API_URL: serverUrl,
      WP_API_USERNAME: username,
      WP_API_PASSWORD: password,
      OAUTH_ENABLED: 'false',
    },
  } : { type: 'http', url: serverUrl }

  // At most 128 characters.
  const defaultPrompt = prompts[0] ? Array.from(prompts[0]).slice(0, 128).join('') : ''
  return {
    '.codex-plugin/plugin.json': JSON.stringify({
      ...identity,
      mcpServers: './.mcp.json',
      interface: { ...ui, ...(defaultPrompt ? { defaultPrompt } : {}), ...icons },
    }, null, 2),
    '.mcp.json': JSON.stringify({
      mcpServers: { [serverName]: server },
    }, null, 2),
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
