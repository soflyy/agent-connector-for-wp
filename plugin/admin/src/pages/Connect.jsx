import React, { useState, useEffect, useRef } from 'react'
import {
  Plug, ArrowLeft, ArrowRight, ExternalLink, RefreshCw,
  AlertTriangle, Terminal, FileCode, Link, MessageSquare, Copy, Check, KeyRound, Lock, Sparkles, Eye, EyeOff, Play, Settings, ShieldCheck,
  MousePointer2, Bot, SquareTerminal, Bird, Rocket, Pi, Search, ChevronDown, ChevronRight, Package, Download, Image as ImageIcon,
} from 'lucide-react'
import { SiOpenai, SiAnthropic, SiGooglegemini, SiWindsurf, SiZedindustries } from 'react-icons/si'
import { VscVscode } from 'react-icons/vsc'
import { api, initial, DEMO_URL } from '../api'
import { downloadMcpb } from '../mcpb'

// On local environments the site isn't reachable over the internet, so OAuth
// only works for agents running on the same machine (CLI tools); hosted
// agents can't complete the flow. The Connect page defaults to the
// application password there and flags OAuth as possibly not working, but
// still allows it. Everywhere else OAuth is the default.
//
// CLI agents are exempt from all of that: they run on the same machine as the
// site, so localhost resolves for them and the sign-in completes normally.
// Showing them a "may not work locally" caveat would just be wrong (see the
// `cli` flag on AGENTS below).
function isLocalHostname(rawHost) {
  const host = String(rawHost).toLowerCase().replace(/^\[|\]$/g, '')
  if (!host) return false
  // localhost itself and any *.localhost / *.local / *.test domain.
  if (host === 'localhost') return true
  if (/\.(localhost|local|test)$/.test(host)) return true
  // Loopback addresses.
  if (host === '::1' || host === '0.0.0.0' || /^127\.\d{1,3}\.\d{1,3}\.\d{1,3}$/.test(host)) return true
  // Private LAN ranges, not reachable from the internet either.
  return /^(10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.)\d/.test(host)
}

function isLocalEnvironment() {
  if (initial.envType === 'local') return true
  const hosts = []
  try { hosts.push(new URL(initial.serverUrl).hostname) } catch {}
  if (window.location?.hostname) hosts.push(window.location.hostname)
  return hosts.some(isLocalHostname)
}

// `cli: true` marks an agent that runs on the operator's own machine, so it
// can reach a local site and needs none of the local-environment caveats.
//
// The two video fields are per auth method and are not interchangeable:
// `videoUrl` walks through the application-password + proxy setup, which looks
// nothing like the OAuth flow. `oauthVideoUrl` is the OAuth walkthrough; while
// it's empty the OAuth instructions simply show no video link (see Block).
// Ordered by expected popularity — the first POPULAR_COUNT entries are the
// picker's collapsed set, so this order is UI, not just cosmetics.
//
// Agents without dedicated instructions fall back to the generic "Other"
// blocks (see the `|| perAgent.other` / find('other') lookups).
//
// ChatGPT covers the ChatGPT desktop app, which now includes Codex: its OAuth
// steps add a Create MCP App (connected from OpenAI's cloud, hence no `cli`),
// its application-password steps configure Codex's local MCP servers.
//
// `mcpb: true` offers a one-click MCP Bundle download (see ../mcpb.js) ahead
// of the manual application-password instructions. `passwordOnly: true` marks
// an entry the OAuth flow can't serve, so the method picker is skipped.
const AGENTS = [
  { id: 'claude-desktop', label: 'Claude Desktop',  Icon: SiAnthropic, bg: '#fef3e8', fg: '#c2410c', mcpb: true, videoUrl: 'https://www.loom.com/share/b4d96754bae04d2e9ab6288ad3bb970b', oauthVideoUrl: '' },
  { id: 'chatgpt',        label: 'ChatGPT',         Icon: SiOpenai,    bg: '#e8f5f0', fg: '#0d8c6b', videoUrl: 'https://www.loom.com/share/086dbe81a3eb4ea3bfb0a45f7f4d9779', oauthVideoUrl: '' },
  { id: 'cursor',         label: 'Cursor',          Icon: MousePointer2,   bg: '#f4f4f5', fg: '#18181b', cli: true, videoUrl: '', oauthVideoUrl: '' },
  { id: 'claude-code',    label: 'Claude Code CLI', Icon: SiAnthropic, bg: '#fef3e8', fg: '#c2410c', cli: true, videoUrl: 'https://www.loom.com/share/75a123e662f84118bfea5b5c4e2593eb', oauthVideoUrl: '' },
  { id: 'codex-cli',      label: 'Codex CLI',       Icon: SiOpenai,    bg: '#e8f5f0', fg: '#0d8c6b', cli: true, videoUrl: 'https://www.loom.com/share/cbea0194fcdd44d08f3a2f6c1c655bcc', oauthVideoUrl: '' },
  { id: 'gemini-cli',     label: 'Gemini CLI',      Icon: SiGooglegemini,  bg: '#eef2ff', fg: '#4285f4', cli: true, videoUrl: '', oauthVideoUrl: '' },
  { id: 'vscode-copilot', label: 'VS Code Copilot', Icon: VscVscode,       bg: '#e7f0fb', fg: '#0078d4', cli: true, videoUrl: '', oauthVideoUrl: '' },
  { id: 'cline',          label: 'Cline',           Icon: Bot,             bg: '#f3e8ff', fg: '#7c3aed', cli: true, videoUrl: '', oauthVideoUrl: '' },
  { id: 'windsurf',       label: 'Windsurf / Devin', Icon: SiWindsurf,      bg: '#e6fbf4', fg: '#0d9488', cli: true, videoUrl: '', oauthVideoUrl: '' },
  { id: 'zed',            label: 'Zed',             Icon: SiZedindustries, bg: '#e8eefe', fg: '#1d4ed8', cli: true, videoUrl: '', oauthVideoUrl: '' },
  { id: 'opencode',       label: 'OpenCode',        Icon: SquareTerminal,  bg: '#f1f5f9', fg: '#334155', cli: true, videoUrl: '', oauthVideoUrl: '' },
  { id: 'goose',          label: 'Goose',           Icon: Bird,            bg: '#fef3c7', fg: '#b45309', cli: true, videoUrl: '', oauthVideoUrl: '' },
  { id: 'antigravity',    label: 'Antigravity',     Icon: Rocket,          bg: '#e0f2fe', fg: '#0369a1', cli: true, videoUrl: '', oauthVideoUrl: '' },
  { id: 'pi',             label: 'Pi',              Icon: Pi,              bg: '#fdf2f8', fg: '#db2777', cli: true, videoUrl: '', oauthVideoUrl: '' },
  { id: 'mcpb',           label: 'One-click install (.mcpb)', Icon: Package, bg: '#fff7ed', fg: '#ea580c', cli: true, passwordOnly: true, mcpb: true, videoUrl: '', oauthVideoUrl: '' },
  { id: 'other',          label: 'Other',            Icon: Sparkles,    bg: '#f1f5f9', fg: '#64748b', videoUrl: '', oauthVideoUrl: '' },
]

// ─── Client-side artifact builder ────────────────────────────────────────────

const PROXY_PACKAGE = '@automattic/mcp-wordpress-remote'

// Double-quoted rather than single-quoted: these commands are copy-pasted
// into whatever terminal the operator has (bash/zsh, PowerShell, or Windows
// cmd.exe). cmd.exe doesn't treat single quotes as quote characters at all —
// they pass through literally into the argument — so a single-quoted
// argument containing a space or special character breaks `codex`/`claude`
// CLI's own parsing on Windows. Double quotes are understood by all three.
function shellArg(s) {
  return '"' + s.replace(/[\\"]/g, '\\$&') + '"'
}

function buildArtifacts(serverName, serverUrl, username, password, siteName) {
  const env = {
    WP_API_URL: serverUrl,
    WP_API_USERNAME: username,
    WP_API_PASSWORD: password,
    OAUTH_ENABLED: 'false',
  }
  const serverEntry = { command: 'npx', args: ['-y', PROXY_PACKAGE], env }

  const codexCliCmd = [
    'codex', 'mcp', 'add', shellArg(serverName),
    ...Object.entries(env).flatMap(([k, v]) => ['--env', shellArg(`${k}=${v}`)]),
    '--', 'npx', '-y', shellArg(PROXY_PACKAGE),
  ].join(' ')

  const claudeCodeCmd = [
    'claude', 'mcp', 'add', shellArg(serverName),
    ...Object.entries(env).flatMap(([k, v]) => ['--env', shellArg(`${k}=${v}`)]),
    '--', 'npx', '-y', shellArg(PROXY_PACKAGE),
  ].join(' ')

  const vscodeConfig = JSON.stringify({ name: serverName, ...serverEntry })
  const vscodeDeeplink = 'vscode:mcp/install?' + encodeURIComponent(vscodeConfig)
  const vscodeCLI = 'code --add-mcp ' + shellArg(vscodeConfig)

  const cursorConfig = btoa(JSON.stringify(serverEntry))
  const cursorDeeplink = `cursor://anysphere.cursor-deeplink/mcp/install?name=${encodeURIComponent(serverName)}&config=${encodeURIComponent(cursorConfig)}`

  const geminiCmd = [
    'gemini', 'mcp', 'add',
    ...Object.entries(env).flatMap(([k, v]) => ['-e', shellArg(`${k}=${v}`)]),
    shellArg(serverName), 'npx', '-y', shellArg(PROXY_PACKAGE),
  ].join(' ')

  const agentPrompt = [
    'Configure an MCP server for me.',
    '',
    `Server name: ${serverName}`,
    '',
    'Command:',
    'npx',
    '',
    'Arguments:',
    '-y',
    PROXY_PACKAGE,
    '',
    'Environment variables:',
    '',
    `WP_API_URL=${env.WP_API_URL}`,
    '',
    `WP_API_USERNAME=${env.WP_API_USERNAME}`,
    '',
    `WP_API_PASSWORD=${env.WP_API_PASSWORD}`,
    '',
    'OAUTH_ENABLED=false',
    '',
    'Requirements:',
    '',
    'Perform the installation and configuration yourself whenever possible.',
    'Detect the operating system and MCP client automatically.',
    'Verify that node, npm, and npx are available.',
    "If Node.js is missing, attempt to install the latest LTS version automatically using the platform's standard installation method. Only ask the user for help if elevated permissions, security restrictions, interactive approval, or platform limitations prevent automatic installation.",
    'If automatic Node.js installation is not possible, explain exactly why and provide the official Node.js download location.',
    'Locate the MCP configuration file automatically.',
    'Create a backup before making any changes.',
    'Add this MCP server without removing, replacing, or modifying any existing MCP servers.',
    'Validate the configuration before saving.',
    `Verify that the package ${PROXY_PACKAGE} is available.`,
    'If possible, perform a basic startup test to confirm the MCP server can launch.',
    'Show exact errors if any step fails.',
    'Do not make assumptions about configuration file locations when they can be discovered automatically.',
    'If the MCP client must be identified by the user, ask only for that information and continue.',
    'After configuration changes are made, instruct the user to completely restart the MCP client because many MCP clients do not reliably hot-reload server configurations.',
    'If automatic configuration is not possible, provide the exact file path that must be edited and the exact configuration that must be added.',
    'Never overwrite unrelated configuration.',
    'Never claim success unless the configuration was actually written or the server was verified to already exist.',
    'If any part of the process cannot be verified, explicitly state what remains unverified.',
    'Prefer taking action over providing instructions.',
    'Optimize for completing the task correctly on the first attempt with minimal user involvement.',
    '',
    'At the end, report only:',
    '',
    'What was changed.',
    'Any errors encountered.',
    'Whether a restart is required.',
    'Any remaining manual steps.',
  ].join('\n')

  const codexToml = [
    '[[mcp_servers]]',
    `name = ${JSON.stringify(serverName)}`,
    `command = "npx"`,
    `args = ["-y", ${JSON.stringify(PROXY_PACKAGE)}]`,
    `env = { ${Object.entries(env).map(([k, v]) => `${k} = ${JSON.stringify(v)}`).join(', ')} }`,
  ].join('\n')

  return {
    url: serverUrl,
    username,
    agents: [
      {
        id: 'codex-cli',
        label: 'Codex CLI',
        blocks: [{
          kind: 'command', title: 'Terminal command',
          hint: 'Run this in your terminal to add the server to Codex CLI. It writes to ~/.codex/config.toml automatically (Node.js required).',
          value: codexCliCmd,
          steps: [
            'Copy the command above',
            'Open your terminal and paste it',
            'Codex CLI will confirm the server was added',
          ],
        }],
      },
      {
        id: 'chatgpt',
        label: 'ChatGPT',
        blocks: [
          {
            kind: 'command', title: 'Terminal command',
            hint: 'Run this in your terminal to add the server automatically (Node.js required).',
            value: codexCliCmd,
            steps: [
              '<a href="https://developers.openai.com/codex/cli" target="_blank" rel="noreferrer" class="underline">Install Codex CLI</a>',
              'Copy the command above',
              'Open your terminal and paste it',
            ],
          },
          {
            kind: 'fields', title: 'MCP server settings',
            hint: null,
            noVideo: true,
            stepsTitle: 'Manual install',
            value: [
              { label: 'Transport',       value: 'STDIO' },
              { label: 'Name',            value: serverName },
              { label: 'Command',         value: 'npx' },
              { label: 'Argument 1',      value: '-y' },
              { label: 'Argument 2',      value: `${PROXY_PACKAGE}@latest` },
              { heading: 'Environment Variables' },
              { label: 'WP_API_URL',      value: env.WP_API_URL },
              { label: 'WP_API_USERNAME', value: env.WP_API_USERNAME },
              { label: 'WP_API_PASSWORD', value: env.WP_API_PASSWORD },
            ],
            steps: [
              'Open the ChatGPT desktop app → <strong>Settings</strong>',
              'Click <strong>MCP Servers</strong>',
              'Click <strong>Add Server</strong>',
              'Manually enter the MCP server settings above',
            ],
          },
        ],
      },
      {
        id: 'claude-code',
        label: 'Claude Code CLI',
        blocks: [{
          kind: 'command', title: 'Terminal command',
          hint: 'Run this in your terminal to add the server to Claude Code (Node.js required).',
          value: claudeCodeCmd,
          steps: [
            'Copy the command above',
            'Open your terminal and paste it',
            'Claude Code will confirm the server was added',
          ],
        }],
      },
      {
        id: 'claude-desktop',
        label: 'Claude Desktop',
        blocks: [{
          kind: 'json', title: 'mcpServers config',
          hint: 'Add this to your <code>claude_desktop_config.json</code>. Find it at:<br>· <strong>macOS:</strong> <code>~/Library/Application Support/Claude/claude_desktop_config.json</code><br>· <strong>Windows:</strong> <code>%APPDATA%\\Claude\\claude_desktop_config.json</code>',
          value: JSON.stringify({ mcpServers: { [serverName]: { command: 'npx', args: ['-y', PROXY_PACKAGE + '@latest'], env: { WP_API_URL: env.WP_API_URL, WP_API_USERNAME: env.WP_API_USERNAME, WP_API_PASSWORD: env.WP_API_PASSWORD } } } }, null, 2),
          steps: [
            'Copy the JSON above',
            'Open <code>claude_desktop_config.json</code>',
            'Merge the <code>mcpServers</code> entry into the file. Don\'t replace the whole file',
            'Save and restart Claude Desktop',
          ],
        }],
      },
      {
        id: 'other',
        label: 'Other',
        blocks: [{
          kind: 'fields', title: 'MCP server settings',
          hint: null,
          value: [
            { label: 'Name',            value: serverName },
            { label: 'Command',         value: 'npx' },
            { label: 'Arguments',       value: `-y ${PROXY_PACKAGE}@latest` },
            { label: 'WP_API_URL',      value: env.WP_API_URL },
            { label: 'WP_API_USERNAME', value: env.WP_API_USERNAME },
            { label: 'WP_API_PASSWORD', value: env.WP_API_PASSWORD },
          ],
          steps: [
            'Add an MCP server with the settings above',
          ],
        }],
      },
    ],
  }
}

// ─── OAuth (remote URL) artifact builder ─────────────────────────────────────
//
// The recommended path: OAuth-capable clients only need the MCP endpoint URL.
// They fetch it, receive a 401 pointing at this site's .well-known discovery
// document, self-register (Dynamic Client Registration), and open the consent
// page for the admin to approve — no application password, no local proxy, no
// Node.js. See src/OAuth/Server.php.

// Screenshots for the OAuth steps, keyed by `<agent>/<step>`, as paths under
// assets/images/connect/. A key without an entry renders a placeholder (see
// StepScreenshot).
const OAUTH_SCREENSHOTS = {
  'claude-desktop/customize': 'claude-desktop/01-customize.webp',
  'claude-desktop/connectors': 'claude-desktop/02-connectors.webp',
  'claude-desktop/add': 'claude-desktop/03-add-custom-connector.webp',
  'claude-desktop/name-url': 'claude-desktop/04-name-and-url.webp',
  'claude-desktop/options': 'claude-desktop/05-add.webp',
  'claude-desktop/connect': 'claude-desktop/06-connect.webp',
  'claude-desktop/authorize': 'claude-desktop/07-authorize.webp',
  'claude-desktop/connected': 'claude-desktop/08-connected.webp',
  'chatgpt/customize': 'chatgpt/01-customize.webp',
  'chatgpt/create-mcp-app': 'chatgpt/02-create-mcp-app.webp',
  'chatgpt/form': 'chatgpt/03-form.webp',
  'chatgpt/continue': 'chatgpt/04-continue.webp',
  'chatgpt/authorize': 'chatgpt/05-authorize.webp',
}

// A step with a screenshot under it; `extra` adds step fields such as `copy`.
function shot(key, html, alt, extra = {}) {
  const path = OAUTH_SCREENSHOTS[key]
  return { html, screenshot: { src: path ? `${initial.assetsUrl}images/connect/${path}` : '', alt }, ...extra }
}

// The final step most clients share: this site's own consent page.
const authorizeStep = (lead) => shot('authorize', `${lead}, log in if asked, and click <strong>Authorize</strong>`, 'This site\'s Authorize page')

// Instructions as of September 2026. Clients move these menus often; when one
// changes, update its entry here (and its screenshots).
function buildOAuth(serverName, serverUrl) {
  // Blocks for clients configured by pasting the URL: it's shown at the top
  // and again inside the step that says to paste it (`copy`).
  const guide = { kind: 'url', title: 'MCP Server URL', value: serverUrl }
  const withUrl = { copy: serverUrl }

  // `--transport http` is Streamable HTTP. `login` runs the OAuth sign-in up
  // front, so the first tool call just works instead of stopping to
  // authenticate.
  const claudeCodeHttp = [
    `claude mcp add --transport http --scope user ${shellArg(serverName)} ${shellArg(serverUrl)}`,
    `claude mcp login ${shellArg(serverName)}`,
  ].join('\n')

  // `--url` registers a remote (Streamable HTTP) server; Codex detects OAuth
  // and starts the sign-in itself.
  const codexCliHttp = `codex mcp add ${shellArg(serverName)} --url ${shellArg(serverUrl)}`

  const geminiHttp = `gemini mcp add --transport http --scope user ${shellArg(serverName)} ${shellArg(serverUrl)}`

  // VS Code takes { name, type: 'http', url }; Cursor's install link carries
  // only the inner server entry, base64-encoded.
  const vscodeDeeplink = 'vscode:mcp/install?' + encodeURIComponent(JSON.stringify({ name: serverName, type: 'http', url: serverUrl }))
  const cursorDeeplink = `cursor://anysphere.cursor-deeplink/mcp/install?name=${encodeURIComponent(serverName)}&config=${encodeURIComponent(btoa(JSON.stringify({ url: serverUrl })))}`

  const json = (value) => JSON.stringify(value, null, 2)

  const perAgent = {
    'claude-desktop': [{
      ...guide,
      steps: [
        shot('claude-desktop/customize', 'In Claude, click <strong>Customize</strong> in the sidebar', 'Customize in the Claude sidebar'),
        shot('claude-desktop/connectors', 'Open the <strong>Connectors</strong> tab', 'Connectors tab'),
        shot('claude-desktop/add', 'Click <strong>Add</strong>, then <strong>Add custom connector</strong>', 'Add custom connector menu'),
        shot('claude-desktop/name-url', 'Enter a name for your site, paste the MCP Server URL, and click <strong>Continue</strong>', 'Name and MCP server URL fields', withUrl),
        shot('claude-desktop/options', 'Keep the detected options (<strong>Sign in now</strong> and <strong>Register automatically</strong>) and click <strong>Add</strong>', 'Authentication options and the Add button'),
        shot('claude-desktop/connect', 'Click <strong>Connect</strong>', 'Connect button'),
        shot('claude-desktop/authorize', 'Your browser opens this site: log in if asked, then click <strong>Authorize</strong>', 'This site\'s Authorize page'),
        shot('claude-desktop/connected', 'Done. Back in Claude, your site is connected and you can choose which tools need your approval', 'Connected site with tool permissions'),
      ],
    }],
    'chatgpt': [{
      ...guide,
      steps: [
        shot('chatgpt/customize', 'In ChatGPT, click <strong>Customize</strong> in the sidebar', 'Customize in the ChatGPT sidebar'),
        shot('chatgpt/create-mcp-app', 'In <strong>Plugins</strong>, click <strong>Add</strong>, then <strong>Create MCP App</strong>', 'Add → Create MCP App'),
        shot('chatgpt/form', 'Enter a name for your site, paste the MCP Server URL, set <strong>Authentication</strong> to <strong>OAuth</strong>, tick <strong>I understand and want to continue</strong>, and click <strong>Create</strong>', 'Create MCP App form', withUrl),
        shot('chatgpt/continue', 'Click <strong>Continue to</strong> your site', 'Continue to your site'),
        shot('chatgpt/authorize', 'Your browser opens this site: log in if asked, then click <strong>Authorize</strong>', 'This site\'s Authorize page'),
      ],
    }],
    'cursor': [{
      kind: 'deeplink', title: 'One-click install', button: 'Add to Cursor',
      value: cursorDeeplink,
      steps: [
        shot('cursor/install', 'Click <strong>Add to Cursor</strong> above and confirm <strong>Install</strong> in Cursor', 'Cursor install prompt'),
        shot('cursor/connect', 'In <strong>Customize</strong> → <strong>MCPs</strong>, click <strong>Authenticate</strong> on the server', 'Customize → MCPs → Authenticate'),
        authorizeStep('Your browser opens this site'),
      ],
    }, {
      kind: 'json', title: 'Or add it by hand',
      hint: 'Add this to <code>~/.cursor/mcp.json</code>, restart Cursor, then click <strong>Authenticate</strong> in <strong>Customize</strong> → <strong>MCPs</strong>.',
      value: json({ mcpServers: { [serverName]: { url: serverUrl } } }),
    }],
    'claude-code': [{
      kind: 'command', title: 'Terminal commands',
      value: claudeCodeHttp,
      steps: [
        'Copy both commands above',
        'Open your terminal and run them in order',
        authorizeStep('The second command opens this site in your browser'),
      ],
    }],
    'codex-cli': [{
      kind: 'command', title: 'Terminal command',
      value: codexCliHttp,
      steps: [
        'Copy the command above',
        'Open your terminal and paste it',
        authorizeStep(`Codex opens this site in your browser (if it doesn't, run <code>codex mcp login ${serverName}</code>)`),
      ],
    }],
    'gemini-cli': [{
      kind: 'command', title: 'Terminal command',
      value: geminiHttp,
      steps: [
        'Copy the command above and run it in your terminal',
        `Start <code>gemini</code> and run <code>/mcp auth ${serverName}</code>`,
        authorizeStep('Your browser opens this site'),
      ],
    }],
    'vscode-copilot': [{
      kind: 'deeplink', title: 'One-click install', button: 'Add to VS Code',
      value: vscodeDeeplink,
      steps: [
        shot('vscode/install', 'Click <strong>Add to VS Code</strong> above and click <strong>Install</strong> on the server page', 'VS Code server Install page'),
        shot('vscode/allow', 'When VS Code says the server wants to authenticate, click <strong>Allow</strong>', 'Authentication prompt'),
        authorizeStep('Your browser opens this site'),
      ],
    }, {
      ...guide, title: 'Or add it by hand',
      steps: [
        shot('vscode/add-server', 'Open the Command Palette and run <strong>MCP: Add Server</strong>', 'MCP: Add Server'),
        shot('vscode/http', 'Choose <strong>HTTP</strong>, paste the MCP Server URL, and give it a name', 'Server URL prompt', withUrl),
        'Choose <strong>Global</strong> to use it in every workspace, then allow the sign-in as above',
      ],
    }],
    'cline': [{
      ...guide,
      steps: [
        shot('cline/customize', 'In the Cline panel, click <strong>Customize</strong> (the wrench), then the <strong>MCP</strong> tab', 'Customize → MCP'),
        shot('cline/add', 'Click <strong>Add Remote Server</strong>, enter a name, paste the MCP Server URL, and choose <strong>Streamable HTTP</strong>', 'Add Remote Server form', withUrl),
        shot('cline/authenticate', 'Click <strong>Add Server</strong>, then <strong>Authenticate</strong> on the server', 'Authenticate button'),
        authorizeStep('Your browser opens this site'),
      ],
    }],
    'windsurf': [{
      kind: 'json', title: 'MCP config',
      hint: 'Windsurf is now Devin Desktop. Add this to <code>~/.config/devin/mcp_config.json</code>, merging it with any servers already there.',
      value: json({ mcpServers: { [serverName]: { url: serverUrl } } }),
      steps: [
        'Copy the JSON above into <code>~/.config/devin/mcp_config.json</code> and save',
        shot('windsurf/customizations', 'In Devin Desktop, open <strong>Open customizations</strong> from the new-tab menu', 'Customizations → MCP servers'),
        shot('windsurf/authenticate', 'If the server says <strong>Needs auth</strong>, click <strong>Authenticate</strong>', 'Authenticate button'),
        authorizeStep('Your browser opens this site'),
      ],
    }],
    'zed': [{
      ...guide,
      steps: [
        shot('zed/settings', 'Open <strong>Settings</strong> → <strong>AI</strong> → <strong>MCP Servers</strong>', 'Settings → AI → MCP Servers'),
        shot('zed/add', 'Click <strong>Add Server</strong> → <strong>Add Remote Server</strong> and paste the MCP Server URL', 'Add Remote Server', withUrl),
        shot('zed/authenticate', 'Click <strong>Authenticate</strong> on the server', 'Authenticate button'),
        authorizeStep('Your browser opens this site'),
      ],
    }],
    'opencode': [{
      kind: 'json', title: 'opencode.json',
      hint: 'Add this to your project\'s <code>opencode.json</code>, or to <code>~/.config/opencode/opencode.json</code> for every project.',
      value: json({ $schema: 'https://opencode.ai/config.json', mcp: { [serverName]: { type: 'remote', url: serverUrl, enabled: true } } }),
      steps: [
        'Copy the JSON above into <code>opencode.json</code>, merging it with anything already there',
        `Run <code>opencode mcp auth ${serverName}</code> in your terminal`,
        authorizeStep('Your browser opens this site'),
      ],
    }],
    'goose': [{
      ...guide,
      steps: [
        shot('goose/extensions', 'In Goose, open the sidebar and click <strong>Extensions</strong> → <strong>Add custom extension</strong>', 'Extensions → Add custom extension'),
        shot('goose/form', 'Enter a name, set <strong>Type</strong> to <strong>Streamable HTTP</strong>, and paste the MCP Server URL as the <strong>Endpoint</strong>', 'Custom extension form', withUrl),
        authorizeStep('Click <strong>Add Extension</strong>. When your browser opens this site'),
      ],
    }],
    'antigravity': [{
      kind: 'json', title: 'mcp_config.json',
      hint: 'Antigravity needs <code>serverUrl</code> (not <code>url</code>). Merge this with any servers already in the file.',
      value: json({ mcpServers: { [serverName]: { serverUrl } } }),
      steps: [
        shot('antigravity/manage', 'In the agent panel, click <strong>…</strong> → <strong>MCP Servers</strong> → <strong>Manage MCP Servers</strong> → <strong>View raw config</strong>', 'Manage MCP Servers → View raw config'),
        'Paste the JSON above into the file and save',
        shot('antigravity/authenticate', 'Open <strong>Agent Settings</strong> → <strong>Customizations</strong> and click <strong>Authenticate</strong> next to the server', 'Customizations → Authenticate'),
        authorizeStep('In your browser, sign in to this site'),
        shot('antigravity/code', 'Copy the code shown, paste it back in Antigravity, and click <strong>Submit</strong>', 'Pasting the authorization code'),
      ],
    }],
    'pi': [{
      kind: 'command', title: 'Install MCP support',
      hint: 'Pi has no built-in MCP support; the community <code>pi-mcp-adapter</code> extension adds it.',
      value: 'pi install npm:pi-mcp-adapter',
      steps: [
        'Run the command above, then restart Pi',
      ],
    }, {
      kind: 'json', title: 'MCP config',
      hint: 'Add this to <code>~/.config/mcp/mcp.json</code>, merging it with any servers already there.',
      value: json({ mcpServers: { [serverName]: { url: serverUrl, auth: 'oauth' } } }),
      steps: [
        'Copy the JSON above into <code>~/.config/mcp/mcp.json</code> and save',
        `In Pi, run <code>/mcp-auth ${serverName}</code>`,
        authorizeStep('Your browser opens this site'),
      ],
    }],
    'other': [{
      ...guide,
      steps: [
        { html: 'Add a remote (Streamable HTTP) MCP server with this URL', ...withUrl },
        authorizeStep('When prompted, open the sign-in page'),
      ],
    }],
  }

  return {
    url: serverUrl,
    agents: AGENTS.map((a) => ({ id: a.id, label: a.label, blocks: perAgent[a.id] || perAgent.other })),
  }
}

// ─── Block renderer ───────────────────────────────────────────────────────────

async function copyToClipboard(text, el) {
  if (el) el.select()
  if (navigator.clipboard?.writeText) {
    try { await navigator.clipboard.writeText(text); return true } catch {}
  }
  const tmp = el ?? (() => {
    const t = document.createElement('textarea')
    t.value = text
    t.style.cssText = 'position:fixed;opacity:0;top:0;left:0'
    document.body.appendChild(t)
    t.select()
    return t
  })()
  try { return document.execCommand('copy') } catch { return false } finally {
    if (!el) tmp.remove()
  }
}


function CodeContent({ block }) {
  const [copied, setCopied] = useState(false)
  const [copyFailed, setCopyFailed] = useState(false)
  const textareaRef = useRef(null)
  const isCommand = block.kind === 'command'

  useEffect(() => {
    const el = textareaRef.current
    if (!el) return
    el.style.height = 'auto'
    el.style.height = Math.max(el.scrollHeight, 120) + 'px'
  }, [block.value])

  async function selectAndCopy() {
    const ok = await copyToClipboard(block.value, textareaRef.current)
    if (ok) {
      setCopied(true)
      setCopyFailed(false)
      setTimeout(() => setCopied(false), 2000)
    } else {
      setCopyFailed(true)
      setTimeout(() => setCopyFailed(false), 8000)
    }
  }

  return (
    <div className="rounded-xl overflow-hidden border border-gray-800 bg-gray-950">
      {isCommand && (
        <div className="flex items-center gap-1.5 px-4 py-2.5 border-b border-gray-800 bg-gray-900">
          <span className="w-3 h-3 rounded-full bg-red-500/60" />
          <span className="w-3 h-3 rounded-full bg-yellow-500/60" />
          <span className="w-3 h-3 rounded-full bg-green-500/60" />
        </div>
      )}

      <div className="relative">
        {isCommand && (
          <span className="absolute left-4 top-4 text-green-400 font-mono text-sm select-none pointer-events-none">$&nbsp;</span>
        )}
        <textarea
          ref={textareaRef}
          readOnly
          value={block.value}
          onClick={selectAndCopy}
          style={{ height: 'auto', minHeight: '120px', overflow: 'hidden' }}
          className={[
            'w-full bg-transparent text-gray-200 text-sm font-mono leading-relaxed resize-none border-0 focus:outline-none cursor-pointer p-4',
            isCommand ? 'pl-10' : '',
          ].join(' ')}
        />
      </div>

      <div className="p-4 pt-0 space-y-2">
        <button
          onClick={selectAndCopy}
          className={[
            'w-full flex items-center justify-center gap-2 py-3 rounded-lg text-base font-semibold transition-all',
            copied ? 'bg-green-600 text-white' : 'bg-indigo-600 hover:bg-indigo-500 text-white',
          ].join(' ')}
        >
          {copied ? <Check className="w-5 h-5" /> : <Copy className="w-5 h-5" />}
          {copied ? 'Copied!' : 'Copy'}
        </button>
        {copyFailed && (
          <p className="text-center text-xs text-gray-400">
            Clipboard unavailable. The text is selected, so press <strong className="text-gray-300">Ctrl+C</strong> / <strong className="text-gray-300">⌘C</strong> to copy
          </p>
        )}
      </div>
    </div>
  )
}

function FieldsContent({ fields }) {
  const [copied, setCopied] = useState(null) // 'label-i' | 'value-i'

  async function copyItem(text, key) {
    const ok = await copyToClipboard(text)
    if (ok) {
      setCopied(key)
      setTimeout(() => setCopied(null), 2000)
    }
  }

  return (
    <div className="rounded-xl overflow-hidden border border-gray-200 divide-y divide-gray-100">
      {fields.map((field, i) => field.heading ? (
        <div key={i} className="px-3 py-1.5 bg-gray-50">
          <span className="text-xs font-semibold text-gray-400 uppercase tracking-wider">{field.heading}</span>
        </div>
      ) : (
        <div key={i} className="flex items-center bg-white hover:bg-gray-50 transition-colors divide-x divide-gray-100">
          <div className="flex items-center gap-1 px-3 py-2.5 w-52 flex-shrink-0">
            <span className="flex-1 text-xs font-medium text-gray-500 font-mono">{field.label}</span>
            <button
              onClick={() => copyItem(field.label, `label-${i}`)}
              className="p-1 rounded text-gray-300 hover:text-indigo-600 hover:bg-indigo-50 transition-colors"
              aria-label={`Copy key ${field.label}`}
            >
              {copied === `label-${i}` ? <Check className="w-3 h-3 text-green-500" /> : <Copy className="w-3 h-3" />}
            </button>
          </div>
          <div className="flex items-center gap-1 px-3 py-2.5 flex-1 min-w-0">
            <span className="flex-1 font-mono text-sm text-gray-800 truncate">{field.value}</span>
            <button
              onClick={() => copyItem(field.value, `value-${i}`)}
              className="flex-shrink-0 p-1 rounded text-gray-300 hover:text-indigo-600 hover:bg-indigo-50 transition-colors"
              aria-label={`Copy value ${field.label}`}
            >
              {copied === `value-${i}` ? <Check className="w-3 h-3 text-green-500" /> : <Copy className="w-3 h-3" />}
            </button>
          </div>
        </div>
      ))}
    </div>
  )
}

// Builds the .mcpb in the browser on click (block.value holds its params).
function McpbContent({ block }) {
  const [state, setState] = useState('idle') // 'idle' | 'building' | 'done' | 'error'

  async function download() {
    setState('building')
    try {
      await downloadMcpb(block.value)
      setState('done')
    } catch {
      setState('error')
    }
  }

  return (
    <div className="space-y-2">
      <button
        onClick={download}
        disabled={state === 'building'}
        className={[
          'w-full flex items-center justify-center gap-2 py-3 rounded-lg text-base font-semibold text-white transition-all disabled:opacity-60',
          state === 'done' ? 'bg-green-600' : 'bg-indigo-600 hover:bg-indigo-500',
        ].join(' ')}
      >
        {state === 'done' ? <Check className="w-5 h-5" /> : state === 'building' ? <RefreshCw className="w-5 h-5 animate-spin" /> : <Download className="w-5 h-5" />}
        {state === 'done' ? 'Downloaded. Download again' : block.button}
      </button>
      {state === 'error' && (
        <p className="text-center text-sm text-red-600">Couldn't build the file. Please try again.</p>
      )}
    </div>
  )
}

// A URL in a light, input-like field with a copy button. The host is
// emphasized so the site is easy to recognize at a glance.
function UrlField({ value }) {
  const [copied, setCopied] = useState(false)
  const m = /^(\w+:\/\/)([^/]*)(.*)$/.exec(value) || ['', '', value, '']

  async function copy() {
    if (await copyToClipboard(value)) {
      setCopied(true)
      setTimeout(() => setCopied(false), 2000)
    }
  }

  return (
    <div className="flex items-center gap-2 rounded-lg border border-gray-200 bg-gray-50 pl-3.5 pr-1 py-1">
      <code className="flex-1 min-w-0 truncate font-mono text-sm text-gray-500 bg-transparent p-0 m-0">
        {m[1]}<span className="text-gray-900">{m[2]}</span>{m[3]}
      </code>
      <button
        onClick={copy}
        aria-label="Copy MCP Server URL"
        className={[
          'flex-shrink-0 flex items-center gap-1.5 px-3 py-1.5 rounded-md border text-sm font-medium transition-colors',
          copied ? 'border-green-200 bg-green-50 text-green-700' : 'border-gray-200 bg-white text-gray-700 hover:bg-gray-100',
        ].join(' ')}
      >
        {copied ? <Check className="w-4 h-4" /> : <Copy className="w-4 h-4" />}
        {copied ? 'Copied' : 'Copy'}
      </button>
    </div>
  )
}

// A step's screenshot, or a placeholder until its src is set. Every one sits
// centered in a frame with the same minimum height, so small and large
// captures read as one set; a tall capture grows its frame rather than
// shrinking to unreadable.
// Screenshots are 2x (retina) captures, so they're shown at no more than half
// their pixel width.
function StepScreenshot({ screenshot }) {
  const [maxWidth, setMaxWidth] = useState(null)
  return (
    <div className="flex items-center justify-center min-h-[350px] rounded-xl bg-gray-100 p-6">
      {screenshot.src ? (
        <img
          src={screenshot.src}
          alt={screenshot.alt}
          loading="lazy"
          onLoad={(e) => setMaxWidth(e.currentTarget.naturalWidth / 2)}
          style={maxWidth ? { maxWidth: `min(100%, ${maxWidth}px)` } : undefined}
          className="w-auto h-auto max-w-full rounded-lg shadow-md"
        />
      ) : (
        <span className="flex items-center gap-2 text-xs text-gray-400">
          <ImageIcon className="w-4 h-4" />
          Screenshot: {screenshot.alt}
        </span>
      )}
    </div>
  )
}

function Block({ block, videoUrl }) {
  const titleIcon = block.kind === 'mcpb'
    ? <Package className="w-4 h-4" />
    : block.kind === 'command'
    ? <Terminal className="w-4 h-4" />
    : block.kind === 'json'
    ? <FileCode className="w-4 h-4" />
    : block.kind === 'deeplink'
    ? <Link className="w-4 h-4" />
    : block.kind === 'url'
    ? <Link className="w-4 h-4" />
    : block.kind === 'fields'
    ? <Settings className="w-4 h-4" />
    : <MessageSquare className="w-4 h-4" />

  // What to copy or click comes first; the instructions follow it.
  return (
    <div className="rounded-xl border border-gray-200 bg-white overflow-hidden">
      <div className="p-6 space-y-3">
        <div className="flex items-center gap-2 text-sm font-semibold text-gray-900">
          <span className="text-gray-400">{titleIcon}</span>
          {block.title}
        </div>
        {block.hint && <p className="text-sm text-gray-500" dangerouslySetInnerHTML={{ __html: block.hint }} />}

        {block.kind === 'url' ? (
          <UrlField value={block.value} />
        ) : block.kind === 'deeplink' ? (
          <a
            href={block.value}
            className="w-full flex items-center justify-center gap-2 py-3 bg-indigo-600 hover:bg-indigo-500 text-white text-base font-semibold rounded-lg transition-colors"
          >
            <ExternalLink className="w-5 h-5" />
            {block.button || block.title}
          </a>
        ) : block.kind === 'fields' ? (
          <FieldsContent fields={block.value} />
        ) : block.kind === 'mcpb' ? (
          <McpbContent block={block} />
        ) : (
          <CodeContent block={block} />
        )}
      </div>

      {block.steps?.length > 0 && (
        <div className="border-t border-gray-100 px-6 py-5 space-y-4">
          <p className="text-sm font-medium text-gray-500">{block.stepsTitle || 'How to install'}</p>
          <ol className="space-y-5">
            {block.steps.map((step, i) => (
              <li key={i} className="flex items-start gap-3">
                <span className="flex-shrink-0 w-6 h-6 rounded-full bg-gray-100 text-gray-600 text-xs font-semibold flex items-center justify-center">
                  {i + 1}
                </span>
                {/* A step is an HTML string, or { html, copy, screenshot } to add a copyable URL and an image under it. */}
                <div className="flex-1 min-w-0 space-y-3 pt-0.5">
                  <p className="text-base text-gray-700" dangerouslySetInnerHTML={{ __html: typeof step === 'string' ? step : step.html }} />
                  {step.copy && <UrlField value={step.copy} />}
                  {step.screenshot && <StepScreenshot screenshot={step.screenshot} />}
                </div>
              </li>
            ))}
          </ol>
          {videoUrl && !block.noVideo && (
            <a
              href={videoUrl}
              target="_blank"
              rel="noreferrer"
              className="inline-flex items-center gap-2 px-4 py-2 bg-white border border-gray-300 text-gray-700 text-sm font-semibold rounded-lg shadow-sm hover:bg-gray-50 transition-colors"
            >
              <Play className="w-4 h-4 text-indigo-500" />
              Watch video instructions
            </a>
          )}
        </div>
      )}
    </div>
  )
}

// Shared layout shell — every step uses this exact wrapper for consistent width
const SHELL = 'max-w-3xl mx-auto space-y-8'

function BackLink({ onClick, label = 'Back' }) {
  return (
    <button
      onClick={onClick}
      className="flex items-center gap-2 text-base text-gray-400 hover:text-gray-700 transition-colors"
    >
      <ArrowLeft className="w-4 h-4" />
      {label}
    </button>
  )
}

// ─── Step 1: Welcome ──────────────────────────────────────────────────────────

function WelcomeStep({ status, onStart }) {
  if (!status.active) {
    return (
      <div className={SHELL}>
        <div className="rounded-2xl border border-amber-200 bg-amber-50 p-10 text-center space-y-4">
          <AlertTriangle className="w-10 h-10 text-amber-500 mx-auto" />
          <h2 className="text-xl font-semibold text-amber-900">Agent Connector isn't active</h2>
          <p className="text-base text-amber-700 max-w-xs mx-auto">
            Enable the MCP server in{' '}
            <button className="underline font-medium" onClick={() => { window.location.hash = '/settings' }}>
              Settings
            </button>
            {' '}before connecting an agent.
          </p>
        </div>
      </div>
    )
  }

  return (
    <div className={SHELL}>
      <div className="bg-white rounded-2xl border border-gray-200 shadow-sm p-12 text-center space-y-6">
        <div className="flex justify-center">
          <div className="w-20 h-20 rounded-full bg-indigo-50 flex items-center justify-center">
            <Plug className="w-10 h-10 text-indigo-500" />
          </div>
        </div>
        <div className="space-y-3">
          <h1 className="text-2xl font-bold text-gray-900">Connect an agent to this site</h1>
          <p className="text-gray-500 leading-relaxed">
            Give any AI agent access to this WordPress site over MCP. It takes about 30 seconds.
          </p>
        </div>
        <div className="flex flex-col items-center gap-3">
          <button
            onClick={onStart}
            className="inline-flex items-center justify-center gap-2 px-8 py-3.5 bg-indigo-600 hover:bg-indigo-500 text-white text-base font-semibold rounded-lg transition-colors"
          >
            Get started
            <ArrowRight className="w-5 h-5" />
          </button>
          {DEMO_URL && (
            <a
              href={DEMO_URL}
              target="_blank"
              rel="noreferrer"
              className="flex items-center gap-1.5 text-sm text-gray-400 hover:text-gray-600 transition-colors"
            >
              <Play className="w-3.5 h-3.5" />
              Watch a 2-minute walkthrough
            </a>
          )}
        </div>
      </div>
    </div>
  )
}

// ─── Step 2: Pick agent ───────────────────────────────────────────────────────

// AGENTS is ordered by expected popularity; the first POPULAR_COUNT show by
// default, the rest live behind "More agents". "Other" is always visible —
// including while a search filter matches nothing — so there's always an exit.
const POPULAR_COUNT = 6

function AgentBar({ agent, onPick }) {
  const { Icon } = agent
  return (
    <button
      onClick={() => onPick(agent.id)}
      className="group flex items-center gap-3 px-4 py-3 rounded-xl border border-gray-200 bg-white hover:border-indigo-300 hover:shadow-sm transition-all text-left"
    >
      <div className="w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0" style={{ backgroundColor: agent.bg }}>
        <Icon size={20} style={{ color: agent.fg }} />
      </div>
      <span className="flex-1 text-sm font-semibold text-gray-800 truncate">{agent.label}</span>
      <ChevronRight className="w-4 h-4 text-gray-300 group-hover:text-indigo-500 transition-colors flex-shrink-0" />
    </button>
  )
}

function PickStep({ onPick, onBack }) {
  const [expanded, setExpanded] = useState(false)
  const [query, setQuery] = useState('')

  const other = AGENTS.find((a) => a.id === 'other')
  const rest = AGENTS.filter((a) => a.id !== 'other')
  const q = query.trim().toLowerCase()
  const shown = expanded
    ? rest.filter((a) => !q || a.label.toLowerCase().includes(q))
    : rest.slice(0, POPULAR_COUNT)

  return (
    <div className="space-y-10">
      <div className={SHELL}>
        <BackLink onClick={onBack} />
      </div>

      <div className="text-center space-y-2">
        <h1 className="text-3xl font-bold text-gray-900">Which agent are you connecting?</h1>
        <p className="text-gray-500 text-base">We'll give you the exact setup instructions.</p>
      </div>

      <div className="max-w-2xl mx-auto space-y-3">
        {expanded && (
          <div className="relative">
            <Search className="absolute left-4 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400 pointer-events-none" />
            <input
              autoFocus
              type="text"
              value={query}
              onChange={(e) => setQuery(e.target.value)}
              onKeyDown={(e) => { if (e.key === 'Enter' && shown.length > 0) onPick(shown[0].id) }}
              placeholder="Search agents…"
              className="w-full border border-gray-300 rounded-xl pl-11 pr-4 py-3 text-base text-gray-900 focus:outline-none focus:ring-2 focus:ring-indigo-400"
            />
          </div>
        )}

        <div className="grid sm:grid-cols-2 gap-3">
          {shown.map((agent) => <AgentBar key={agent.id} agent={agent} onPick={onPick} />)}
          <AgentBar agent={other} onPick={onPick} />
          {!expanded && (
            <button
              onClick={() => setExpanded(true)}
              className="flex items-center justify-center gap-2 px-4 py-3 rounded-xl border border-dashed border-gray-300 text-sm font-semibold text-gray-500 hover:border-indigo-300 hover:text-indigo-600 transition-all"
            >
              <ChevronDown className="w-4 h-4" />
              More agents
            </button>
          )}
        </div>

        {expanded && (
          <div className="text-center pt-1">
            <button
              onClick={() => { setExpanded(false); setQuery('') }}
              className="text-sm text-gray-400 hover:text-gray-700 underline underline-offset-2 transition-colors"
            >
              Show fewer agents
            </button>
          </div>
        )}
      </div>
    </div>
  )
}

// ─── Step 3: Generate password + instructions ─────────────────────────────────

function GeneratedPasswordNotice({ password }) {
  const [open, setOpen] = useState(false)
  const [copied, setCopied] = useState(false)
  const [copyFailed, setCopyFailed] = useState(false)
  const inputRef = useRef(null)

  async function copy() {
    const ok = await copyToClipboard(password, inputRef.current)
    if (ok) {
      setCopied(true)
      setCopyFailed(false)
      setTimeout(() => setCopied(false), 2000)
    } else {
      setCopyFailed(true)
      setTimeout(() => setCopyFailed(false), 8000)
    }
  }

  return (
    <div className="rounded-lg border border-green-200 bg-green-50 overflow-hidden text-sm">
      <div className="flex items-center gap-2.5 px-4 py-3">
        <Check className="w-4 h-4 text-green-600 flex-shrink-0" />
        <span className="font-medium text-green-800">App password created</span>
        <span className="text-green-600 hidden sm:inline">Store it somewhere safe; it won't be shown again.</span>
        <button
          onClick={() => setOpen((o) => !o)}
          className="ml-auto flex items-center gap-1.5 text-green-700 hover:text-green-900 font-medium transition-colors"
        >
          {open ? <EyeOff className="w-4 h-4" /> : <Eye className="w-4 h-4" />}
          {open ? 'Hide' : 'Show password'}
        </button>
      </div>
      {open && (
        <div className="border-t border-green-200 bg-white px-4 py-3 space-y-2">
          <div className="flex gap-2">
            <input
              ref={inputRef}
              readOnly
              value={password}
              className="flex-1 font-mono text-sm bg-gray-50 border border-gray-200 rounded-lg px-3 py-2 text-gray-800 focus:outline-none"
              onClick={(e) => e.target.select()}
            />
            <button
              onClick={copy}
              className={[
                'flex items-center gap-1.5 px-3 py-2 rounded-lg text-sm font-medium transition-all flex-shrink-0',
                copied ? 'bg-green-600 text-white' : 'bg-white border border-gray-300 text-gray-700 hover:bg-gray-50',
              ].join(' ')}
            >
              {copied ? <Check className="w-4 h-4" /> : <Copy className="w-4 h-4" />}
              {copied ? 'Copied' : 'Copy'}
            </button>
          </div>
          {copyFailed && (
            <p className="text-xs text-gray-500">
              Clipboard unavailable. The text is selected, so press <strong>Ctrl+C</strong> / <strong>⌘C</strong> to copy
            </p>
          )}
        </div>
      )}
    </div>
  )
}

// The application-password + local-proxy flow: the agent runs Automattic's
// mcp-wordpress-remote proxy locally (npx) and authenticates with a WordPress
// application password. Shown first on local environments; also the path for
// clients that can't reach a remote MCP server directly.
function AppPasswordFlow({ selectedAgent, status }) {
  const agentMeta = AGENTS.find((a) => a.id === selectedAgent) || AGENTS[0]
  const defaultName = `${agentMeta.label} App Password`

  const [mode, setMode] = useState('generate') // 'generate' | 'existing'
  const [name, setName] = useState(defaultName)
  const [existingPw, setExistingPw] = useState('')
  const [generating, setGenerating] = useState(false)
  const [error, setError] = useState(null)
  const [generatedPassword, setGeneratedPassword] = useState(null)
  const [connection, setConnection] = useState(null)
  const [showManual, setShowManual] = useState(false)

  async function handleGenerate() {
    setGenerating(true)
    setError(null)
    try {
      const data = await api.generate({ name })
      setGeneratedPassword(data.password)
      setConnection(data)
    } catch (e) {
      setError(e.message)
    } finally {
      setGenerating(false)
    }
  }

  function handleUseExisting() {
    setConnection(buildArtifacts(
      initial.serverName,
      initial.serverUrl,
      initial.username,
      existingPw,
      initial.siteName,
    ))
  }

  // Agents without dedicated blocks (client- or server-built) get the generic
  // "Other" instructions.
  const agentData = connection?.agents?.find((a) => a.id === selectedAgent)
    ?? connection?.agents?.find((a) => a.id === 'other')

  // The .mcpb is built client-side from whichever password is in play, so it
  // works for both the generated (server-built) and existing paths.
  let blocks = agentData?.blocks ?? []
  let manualBlocks = []
  if (connection && agentMeta.mcpb) {
    const mcpbBlock = {
      kind: 'mcpb', title: 'One-click install',
      hint: 'Download the file below and double click it. Requires Node.js. This file includes your application password, so keep it private.',
      button: 'Download .mcpb file',
      value: {
        serverName: initial.serverName,
        serverUrl: initial.serverUrl,
        siteName: initial.siteName,
        siteIcon: initial.siteIcon,
        username: connection.username ?? initial.username,
        password: generatedPassword ?? existingPw,
      },
    }
    // The agent's own instructions (Claude Desktop's JSON config) stay
    // available behind the "Or install manually" toggle.
    manualBlocks = agentMeta.id === 'mcpb' ? [] : blocks
    blocks = [mcpbBlock]
  }

  if (connection) {
    return (
      <div className="space-y-6">
        {generatedPassword && <GeneratedPasswordNotice password={generatedPassword} />}
        <div className="space-y-6">
          {blocks.length
            ? blocks.map((block, i) => <Block key={i} block={block} videoUrl={agentMeta.videoUrl} />)
            : <p className="text-base text-gray-400">No configuration available for this agent.</p>
          }
          {manualBlocks.length > 0 && (
            <div className="space-y-6">
              <button
                onClick={() => setShowManual((v) => !v)}
                className="flex items-center gap-2 text-base font-semibold text-gray-500 hover:text-gray-800 transition-colors"
              >
                {showManual ? <ChevronDown className="w-4 h-4" /> : <ChevronRight className="w-4 h-4" />}
                Or install manually
              </button>
              {showManual && manualBlocks.map((block, i) => <Block key={i} block={block} videoUrl={agentMeta.videoUrl} />)}
            </div>
          )}
        </div>
      </div>
    )
  }

  if (mode === 'generate') {
    return (
      <div className="bg-white rounded-2xl border border-gray-200 shadow-sm p-8 space-y-6">
        <div className="flex items-center gap-4">
          <div className="w-12 h-12 rounded-full bg-indigo-50 flex items-center justify-center flex-shrink-0">
            <KeyRound className="w-6 h-6 text-indigo-500" />
          </div>
          <div>
            <h2 className="text-xl font-bold text-gray-900">Generate an application password</h2>
            <p className="text-base text-gray-500 mt-0.5">A WordPress credential scoped to your account.</p>
          </div>
        </div>

        {!status.pwAvailable && (
          <div className="flex items-start gap-2.5 p-4 bg-red-50 border border-red-200 rounded-xl text-base text-red-700">
            <AlertTriangle className="w-5 h-5 mt-0.5 flex-shrink-0" />
            <span>
              Application passwords require HTTPS and aren't available on this site. On a
              production environment WordPress only allows them over HTTPS; over plain HTTP they
              work only when the site's environment type is <code className="font-mono">local</code>{' '}
              (set <code className="font-mono">WP_ENVIRONMENT_TYPE</code> to{' '}
              <code className="font-mono">local</code> in <code className="font-mono">wp-config.php</code>).
            </span>
          </div>
        )}

        <div className="space-y-2">
          <label className="block text-base font-medium text-gray-700">Password name</label>
          <input
            type="text"
            value={name}
            onChange={(e) => setName(e.target.value)}
            className="w-full border border-gray-300 rounded-lg px-4 py-3 text-base text-gray-900 focus:outline-none focus:ring-2 focus:ring-indigo-400"
          />
        </div>

        {error && (
          <div className="p-4 bg-red-50 border border-red-200 rounded-xl text-base text-red-700">{error}</div>
        )}

        <div className="flex items-center gap-4">
          <button
            onClick={handleGenerate}
            disabled={generating || !status.pwAvailable}
            className="inline-flex items-center gap-2 px-7 py-3 bg-indigo-600 hover:bg-indigo-500 disabled:opacity-50 text-white text-base font-semibold rounded-lg transition-colors"
          >
            {generating
              ? <><RefreshCw className="w-5 h-5 animate-spin" /> Generating…</>
              : <><KeyRound className="w-5 h-5" /> Generate App Password</>
            }
          </button>
          <button
            onClick={() => setMode('existing')}
            className="text-base text-gray-400 hover:text-gray-700 underline underline-offset-2 transition-colors"
          >
            I already have one
          </button>
        </div>
      </div>
    )
  }

  return (
    <div className="bg-white rounded-2xl border border-gray-200 shadow-sm p-8 space-y-6">
      <div className="flex items-center gap-4">
        <div className="w-12 h-12 rounded-full bg-gray-100 flex items-center justify-center flex-shrink-0">
          <Lock className="w-6 h-6 text-gray-500" />
        </div>
        <div>
          <h2 className="text-xl font-bold text-gray-900">Use an existing password</h2>
          <p className="text-base text-gray-500 mt-0.5">Built into the connection strings locally and never sent to the server.</p>
        </div>
      </div>

      <div className="space-y-2">
        <label className="block text-base font-medium text-gray-700">Application password</label>
        <input
          type="text"
          value={existingPw}
          onChange={(e) => setExistingPw(e.target.value)}
          placeholder="xxxx xxxx xxxx xxxx xxxx xxxx"
          className="w-full border border-gray-300 rounded-lg px-4 py-3 text-base font-mono text-gray-900 focus:outline-none focus:ring-2 focus:ring-indigo-400"
        />
      </div>

      <div className="flex items-center gap-4">
        <button
          onClick={handleUseExisting}
          className="inline-flex items-center gap-2 px-7 py-3 bg-indigo-600 hover:bg-indigo-500 text-white text-base font-semibold rounded-lg transition-colors"
        >
          <ArrowRight className="w-5 h-5" />
          Use this password
        </button>
        <button
          onClick={() => setMode('generate')}
          className="text-base text-gray-400 hover:text-gray-700 underline underline-offset-2 transition-colors"
        >
          Generate a new one instead
        </button>
      </div>
    </div>
  )
}

const AUTH_METHODS = [
  {
    id: 'oauth',
    label: 'OAuth',
    Icon: ShieldCheck,
    description: 'Sign in and approve access. No password needed.',
  },
  {
    id: 'password',
    label: 'Application Password',
    Icon: KeyRound,
    description: 'Use a WordPress application password.',
  },
]

function MethodPicker({ method, onSelect, localCaveat }) {
  return (
    <div className="grid sm:grid-cols-2 gap-4">
      {AUTH_METHODS.map(({ id, label, Icon, description }) => {
        const isSelected = method === id
        const isRecommended = localCaveat ? id === 'password' : id === 'oauth'
        return (
          <button
            key={id}
            onClick={() => onSelect(id)}
            className={[
              'flex flex-col items-start gap-2 p-5 rounded-2xl border-2 text-left transition-all',
              isSelected
                ? 'border-indigo-500 bg-indigo-50 shadow-md'
                : 'border-gray-200 bg-white hover:border-gray-300 hover:shadow-sm',
            ].join(' ')}
          >
            <div className="flex items-center gap-2 flex-wrap">
              <Icon className={`w-5 h-5 ${isSelected ? 'text-indigo-600' : 'text-gray-400'}`} />
              <span className={`text-base font-semibold ${isSelected ? 'text-indigo-700' : 'text-gray-800'}`}>
                {label}
              </span>
              {isRecommended ? (
                <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-green-100 text-green-700 text-xs font-semibold uppercase tracking-wide">
                  <Sparkles className="w-3 h-3" /> Recommended
                </span>
              ) : localCaveat && id === 'oauth' && (
                <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-amber-100 text-amber-700 text-xs font-semibold uppercase tracking-wide">
                  <AlertTriangle className="w-3 h-3" /> May not work locally
                </span>
              )}
            </div>
            <span className={`text-sm ${isSelected ? 'text-indigo-900/70' : 'text-gray-500'}`}>{description}</span>
          </button>
        )
      })}
    </div>
  )
}

function GenerateStep({ selectedAgent, status, onBack }) {
  const agentMeta = AGENTS.find((a) => a.id === selectedAgent) || AGENTS[0]

  // OAuth is the default. On a local site a hosted agent can't complete the
  // sign-in, so the application password leads instead and OAuth is flagged as
  // possibly not working, though it stays selectable. A CLI agent runs on this
  // machine, so localhost resolves for it and none of that applies.
  const localCaveat = isLocalEnvironment() && ! agentMeta.cli

  // An agent the OAuth flow can't serve (the .mcpb entry) goes straight to
  // the application password, with no method picker.
  const passwordOnly = !!agentMeta.passwordOnly
  const [method, setMethod] = useState(passwordOnly || localCaveat ? 'password' : 'oauth')

  const oauth = buildOAuth(initial.serverName, initial.serverUrl)
  const agentData = oauth.agents.find((a) => a.id === selectedAgent)

  return (
    <div className={SHELL}>
      <BackLink onClick={onBack} label="Choose a different agent" />

      <h1 className="text-2xl font-bold text-gray-900">
        Connect <span style={{ color: agentMeta.fg }}>{agentMeta.label}</span>
      </h1>

      {!passwordOnly && (
        <div className="space-y-4">
          <h2 className="text-xl font-bold text-gray-900">How do you want to authenticate?</h2>
          <MethodPicker method={method} onSelect={setMethod} localCaveat={localCaveat} />
        </div>
      )}

      {method === 'oauth' ? (
        <div className="space-y-4">
          {localCaveat && (
            <div className="flex items-start gap-2.5 p-4 bg-amber-50 border border-amber-200 rounded-xl text-base text-amber-800">
              <AlertTriangle className="w-5 h-5 mt-0.5 flex-shrink-0 text-amber-500" />
              <span>
                This site is local, so the agent may not be able to reach it to sign in. If OAuth
                fails, use an application password instead.
              </span>
            </div>
          )}
          <div className="space-y-6">
            {agentData?.blocks?.length
              ? agentData.blocks.map((block, i) => <Block key={i} block={block} videoUrl={agentMeta.oauthVideoUrl} />)
              : <p className="text-base text-gray-400">No configuration available for this agent.</p>
            }
          </div>
        </div>
      ) : (
        <div className="space-y-4">
          {!agentMeta.mcpb && (
            <p className="text-base text-gray-500">
              Authenticate with a WordPress application password. The agent connects through a local <code>npx</code> proxy, so Node.js is required.
            </p>
          )}
          <AppPasswordFlow selectedAgent={selectedAgent} status={status} />
        </div>
      )}
    </div>
  )
}

// ─── Root ─────────────────────────────────────────────────────────────────────

export default function Connect({ status }) {
  const [step, setStep] = useState('welcome')
  const [selectedAgent, setSelectedAgent] = useState('codex-cli')

  if (step === 'welcome') {
    return <WelcomeStep status={status} onStart={() => setStep('pick')} />
  }

  if (step === 'pick') {
    return (
      <PickStep
        onPick={(id) => { setSelectedAgent(id); setStep('generate') }}
        onBack={() => setStep('welcome')}
      />
    )
  }

  return (
    <GenerateStep
      selectedAgent={selectedAgent}
      status={status}
      onBack={() => setStep('pick')}
    />
  )
}
