import React, { useState, useEffect, useRef } from 'react'
import {
  Plug, ArrowLeft, ArrowRight, ExternalLink, RefreshCw,
  AlertTriangle, Terminal, FileCode, Link, MessageSquare, Copy, Check, KeyRound, Lock, Sparkles, Eye, EyeOff, Play, Settings,
} from 'lucide-react'
import { SiOpenai, SiAnthropic } from 'react-icons/si'
import { api, initial, DEMO_URL } from '../api'

const AGENTS = [
  { id: 'codex-cli',      label: 'Codex CLI',       Icon: SiOpenai,    bg: '#e8f5f0', fg: '#0d8c6b', videoUrl: 'https://www.loom.com/share/cbea0194fcdd44d08f3a2f6c1c655bcc' },
  { id: 'codex-desktop',  label: 'Codex Desktop',   Icon: SiOpenai,    bg: '#e8f5f0', fg: '#0d8c6b', videoUrl: 'https://www.loom.com/share/086dbe81a3eb4ea3bfb0a45f7f4d9779' },
  { id: 'claude-code',    label: 'Claude Code CLI', Icon: SiAnthropic, bg: '#fef3e8', fg: '#c2410c', videoUrl: 'https://www.loom.com/share/75a123e662f84118bfea5b5c4e2593eb' },
  { id: 'claude-desktop', label: 'Claude Desktop',  Icon: SiAnthropic, bg: '#fef3e8', fg: '#c2410c', videoUrl: 'https://www.loom.com/share/b4d96754bae04d2e9ab6288ad3bb970b' },
  { id: 'other',          label: 'Other',            Icon: Sparkles,    bg: '#f1f5f9', fg: '#64748b', videoUrl: '' },
]

// ─── Client-side artifact builder ────────────────────────────────────────────

const PROXY_PACKAGE = '@automattic/mcp-wordpress-remote'

function shellArg(s) {
  return "'" + s.replace(/'/g, "'\\''") + "'"
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
            'Copy the command below',
            'Open your terminal and paste it',
            'Codex CLI will confirm the server was added',
          ],
        }],
      },
      {
        id: 'codex-desktop',
        label: 'Codex Desktop',
        blocks: [
          {
            kind: 'command', title: 'Terminal command',
            hint: 'Run this in your terminal to add the server automatically (Node.js required).',
            value: codexCliCmd,
            steps: [
              '<a href="https://developers.openai.com/codex/cli" target="_blank" rel="noreferrer" class="underline">Install Codex CLI</a>',
              'Copy the command below',
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
              'Open Codex Desktop → <strong>Settings</strong>',
              'Click <strong>MCP Servers</strong>',
              'Click <strong>Add Server</strong>',
              'Manually enter the MCP server settings below',
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
            'Copy the command below',
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
            'Copy the JSON below',
            'Open <code>claude_desktop_config.json</code>',
            'Merge the <code>mcpServers</code> entry into the file — don\'t replace the whole file',
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
            'Add an MCP server with the settings below',
          ],
        }],
      },
    ],
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
            Clipboard unavailable — text is selected, press <strong className="text-gray-300">Ctrl+C</strong> / <strong className="text-gray-300">⌘C</strong> to copy
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

function Block({ block, videoUrl }) {
  const titleIcon = block.kind === 'command'
    ? <Terminal className="w-4 h-4" />
    : block.kind === 'json'
    ? <FileCode className="w-4 h-4" />
    : block.kind === 'deeplink'
    ? <Link className="w-4 h-4" />
    : block.kind === 'fields'
    ? <Settings className="w-4 h-4" />
    : <MessageSquare className="w-4 h-4" />

  return (
    <div className="rounded-xl border border-gray-200 bg-white overflow-hidden">
      {/* Steps — top gray section */}
      {block.steps?.length > 0 && (
        <div className="bg-gray-50 px-6 py-5 space-y-3">
          <p className="text-sm font-semibold text-gray-500 uppercase tracking-wider">{block.stepsTitle || 'How to install'}</p>
          <ol className="space-y-2">
            {block.steps.map((step, i) => (
              <li key={i} className="flex items-start gap-3">
                <span className="flex-shrink-0 mt-0.5 w-5 h-5 rounded-full bg-indigo-100 text-indigo-700 text-xs font-bold flex items-center justify-center">
                  {i + 1}
                </span>
                <span className="text-base text-gray-600" dangerouslySetInnerHTML={{ __html: step }} />
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

      {/* Content — bottom white section */}
      <div className="border-t border-gray-100 p-6 space-y-4">
        <div className="flex items-center gap-2 text-sm font-semibold text-gray-500 uppercase tracking-wider">
          {titleIcon}
          {block.title}
        </div>
        {block.hint && <p className="text-sm text-gray-500" dangerouslySetInnerHTML={{ __html: block.hint }} />}

        {block.kind === 'deeplink' ? (
          <a
            href={block.value}
            className="inline-flex items-center gap-2 px-5 py-2.5 bg-indigo-600 hover:bg-indigo-500 text-white text-base font-medium rounded-lg transition-colors"
          >
            <ExternalLink className="w-5 h-5" />
            {block.button || block.title}
          </a>
        ) : block.kind === 'fields' ? (
          <FieldsContent fields={block.value} />
        ) : (
          <CodeContent block={block} />
        )}
      </div>
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
          <p className="text-base text-amber-700 max-w-sm mx-auto">
            {status.prodBlocked
              ? 'A protection is holding it back: “Block on production environments” is on and this is a production site.'
              : "It's switched off."}{' '}
            Manage this in{' '}
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

function PickStep({ selectedAgent, onSelect, onBack, onContinue }) {
  return (
    <div className="space-y-10">
      <div className={SHELL}>
        <BackLink onClick={onBack} />
      </div>

      <div className="text-center space-y-2">
        <h1 className="text-3xl font-bold text-gray-900">Which agent are you connecting?</h1>
        <p className="text-gray-500 text-base">We'll give you the exact setup instructions.</p>
      </div>

      <div className="flex flex-wrap justify-center gap-4">
        {AGENTS.map((agent) => {
          const isSelected = selectedAgent === agent.id
          const { Icon } = agent
          return (
            <button
              key={agent.id}
              onClick={() => onSelect(agent.id)}
              className={[
                'flex flex-col items-center gap-3 p-6 rounded-2xl border-2 w-40 transition-all',
                isSelected
                  ? 'border-indigo-500 bg-indigo-50 shadow-md'
                  : 'border-gray-200 bg-white hover:border-gray-300 hover:shadow-sm',
              ].join(' ')}
            >
              <div className="w-14 h-14 rounded-2xl flex items-center justify-center flex-shrink-0" style={{ backgroundColor: agent.bg }}>
                <Icon size={28} style={{ color: agent.fg }} />
              </div>
              <span className={`text-sm font-semibold text-center leading-tight ${isSelected ? 'text-indigo-700' : 'text-gray-800'}`}>
                {agent.label}
              </span>
            </button>
          )
        })}
      </div>

      <div className="flex justify-center">
        <button
          onClick={onContinue}
          className="inline-flex items-center gap-2 px-8 py-3.5 bg-indigo-600 hover:bg-indigo-500 text-white text-base font-semibold rounded-lg transition-colors"
        >
          Continue
          <ArrowRight className="w-5 h-5" />
        </button>
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
        <span className="text-green-600 hidden sm:inline">— store it somewhere safe; it won't be shown again.</span>
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
              Clipboard unavailable — text is selected, press <strong>Ctrl+C</strong> / <strong>⌘C</strong> to copy
            </p>
          )}
        </div>
      )}
    </div>
  )
}

function GenerateStep({ selectedAgent, status, onBack }) {
  const agentMeta = AGENTS.find((a) => a.id === selectedAgent) || AGENTS[0]
  const defaultName = `${agentMeta.label} App Password`

  const [mode, setMode] = useState('generate') // 'generate' | 'existing'
  const [name, setName] = useState(defaultName)
  const [existingPw, setExistingPw] = useState('')
  const [generating, setGenerating] = useState(false)
  const [error, setError] = useState(null)
  const [generatedPassword, setGeneratedPassword] = useState(null)
  const [connection, setConnection] = useState(null)

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

  const agentData = connection?.agents?.find((a) => a.id === selectedAgent)

  return (
    <div className={SHELL}>
      <BackLink onClick={onBack} label="Choose a different agent" />

      <h1 className="text-2xl font-bold text-gray-900">
        Connect <span style={{ color: agentMeta.fg }}>{agentMeta.label}</span>
      </h1>

      {connection ? (
        <>
          {generatedPassword && <GeneratedPasswordNotice password={generatedPassword} />}

          <div className="space-y-4">
            <h2 className="text-xl font-bold text-gray-900">How to connect</h2>
            <div className="space-y-6">
              {agentData?.blocks?.length
                ? agentData.blocks.map((block, i) => <Block key={i} block={block} videoUrl={agentMeta.videoUrl} />)
                : <p className="text-base text-gray-400">No configuration available for this agent.</p>
              }
            </div>
          </div>
        </>
      ) : mode === 'generate' ? (
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
      ) : (
        <div className="bg-white rounded-2xl border border-gray-200 shadow-sm p-8 space-y-6">
          <div className="flex items-center gap-4">
            <div className="w-12 h-12 rounded-full bg-gray-100 flex items-center justify-center flex-shrink-0">
              <Lock className="w-6 h-6 text-gray-500" />
            </div>
            <div>
              <h2 className="text-xl font-bold text-gray-900">Use an existing password</h2>
              <p className="text-base text-gray-500 mt-0.5">Built into the connection strings locally — never sent to the server.</p>
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
        selectedAgent={selectedAgent}
        onSelect={setSelectedAgent}
        onBack={() => setStep('welcome')}
        onContinue={() => setStep('generate')}
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
