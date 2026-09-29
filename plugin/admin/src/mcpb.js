// ─── MCP Bundle (.mcpb) builder ──────────────────────────────────────────────
//
// An MCP Bundle (https://github.com/modelcontextprotocol/mcpb) is a zip with a
// manifest.json that hosts (Claude Desktop first among them) install in one
// click. Ours runs the same @automattic/mcp-wordpress-remote proxy as the
// manual mcpServers config, through npx, so it needs Node.js on the machine
// just like that config does.
//
// The connection details, application password included, are written straight
// into the manifest so installing is a single click with nothing to configure.
// The file is therefore a credential: the Connect page warns the operator not
// to share it, and changing the password means downloading a new file. It's
// built entirely client-side, so an existing password never leaves the
// browser.

import JSZip from 'jszip'

const MANIFEST_VERSION = '0.3'

const PROXY_PACKAGE = '@automattic/mcp-wordpress-remote@latest'

// MCPB requires server.entry_point to be a file in the bundle, but hosts start
// the server from mcp_config (the npx command), so the file is a placeholder.
const ENTRY_POINT_SOURCE = '// Placeholder: this bundle runs its server from mcp_config (npx).\n'

// The file name offered for download: the server name, which is already a
// filesystem-safe slug.
export function mcpbFileName(serverName) {
  return `${serverName}.mcpb`
}

export function buildManifest({ serverName, serverUrl, siteName, username, password }) {
  let host = serverUrl
  try { host = new URL(serverUrl).host } catch {}
  const label = siteName || host

  return {
    manifest_version: MANIFEST_VERSION,
    name: serverName,
    display_name: `${label} (WordPress)`,
    version: '1.0.0',
    description: `Gives the agent access to the WordPress site ${host} through Agent Connector for WP.`,
    author: { name: 'Soflyy' },
    server: {
      type: 'node',
      entry_point: 'server/index.js',
      // The same proxy and env as the manual mcpServers config.
      mcp_config: {
        command: 'npx',
        args: ['-y', PROXY_PACKAGE],
        // Fixed values rather than user_config: a manifest with user_config
        // makes the host show a Configure form before the server starts, and
        // Claude Desktop won't save one whose values are all pre-filled
        // defaults, so the install gets stuck there.
        env: {
          WP_API_URL: serverUrl,
          WP_API_USERNAME: username,
          WP_API_PASSWORD: password,
          OAUTH_ENABLED: 'false',
        },
      },
    },
    icon: 'icon.png',
    compatibility: {
      platforms: ['darwin', 'win32', 'linux'],
      runtimes: { node: '>=18.0.0' },
    },
  }
}

const ICON_SIZE = 512

// The plugin's plug mark (lucide "plug", as in the admin header) on its indigo
// tile: the icon for sites without a Site Icon.
const FALLBACK_ICON_SVG = `<svg xmlns="http://www.w3.org/2000/svg" width="${ICON_SIZE}" height="${ICON_SIZE}" viewBox="0 0 24 24">
<rect width="24" height="24" rx="5.5" fill="#4f46e5"/>
<g transform="translate(4.5 4.5) scale(0.625)" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
<path d="M12 22v-5"/><path d="M9 8V2"/><path d="M15 8V2"/><path d="M18 8v5a4 4 0 0 1-4 4h-4a4 4 0 0 1-4-4V8Z"/>
</g></svg>`

function loadImage(src, crossOrigin) {
  return new Promise((resolve, reject) => {
    const img = new Image()
    if (crossOrigin) img.crossOrigin = 'anonymous'
    img.onload = () => resolve(img)
    img.onerror = () => reject(new Error('Could not load ' + src))
    img.src = src
  })
}

// Draws an image onto a square canvas and returns it as PNG, the format the
// manifest's icon field expects. Rejects if the canvas is tainted (a Site
// Icon on a CDN without CORS headers), so the caller can fall back.
async function toPng(src, crossOrigin) {
  const img = await loadImage(src, crossOrigin)
  const canvas = document.createElement('canvas')
  canvas.width = canvas.height = ICON_SIZE
  canvas.getContext('2d').drawImage(img, 0, 0, ICON_SIZE, ICON_SIZE)
  return new Promise((resolve, reject) => {
    try {
      canvas.toBlob((blob) => (blob ? resolve(blob) : reject(new Error('Empty icon'))), 'image/png')
    } catch (e) {
      reject(e)
    }
  })
}

// The site's own Site Icon, since the extension is named after the site;
// the plugin mark when there isn't one or it can't be read.
async function buildIcon(siteIcon) {
  if (siteIcon) {
    try { return await toPng(siteIcon, true) } catch {}
  }
  return toPng('data:image/svg+xml;charset=utf-8,' + encodeURIComponent(FALLBACK_ICON_SVG))
}

// The bundle's text files by path. Separate from buildMcpb() so they can be
// generated outside a browser (the icon needs a canvas).
export function mcpbFiles(params) {
  return {
    'manifest.json': JSON.stringify(buildManifest(params), null, 2),
    'server/index.js': ENTRY_POINT_SOURCE,
  }
}

export async function buildMcpb(params) {
  const zip = new JSZip()
  for (const [path, content] of Object.entries(mcpbFiles(params))) zip.file(path, content)
  zip.file('icon.png', await buildIcon(params.siteIcon))
  return zip.generateAsync({ type: 'blob', compression: 'DEFLATE' })
}

export async function downloadMcpb(params) {
  const blob = await buildMcpb(params)
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = mcpbFileName(params.serverName)
  document.body.appendChild(a)
  a.click()
  a.remove()
  setTimeout(() => URL.revokeObjectURL(url), 1000)
}
