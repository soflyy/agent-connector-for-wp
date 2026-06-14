import React, { useState } from 'react'
import {
  Plug, ArrowLeft, ArrowRight, ExternalLink, RefreshCw,
  AlertTriangle, Terminal, FileCode, Link, MessageSquare,
} from 'lucide-react'
import CopyButton from '../components/CopyButton'
import { api } from '../api'

const AGENTS = [
  { id: 'claude-code',    label: 'Claude Code',    icon: '⬡', color: '#d97706', desc: 'claude mcp add command' },
  { id: 'claude-desktop', label: 'Claude Desktop', icon: '⬡', color: '#d97706', desc: 'Edit claude_desktop_config.json' },
  { id: 'cursor',         label: 'Cursor',         icon: '↗', color: '#6366f1', desc: 'One-click install or mcp.json' },
  { id: 'vscode',         label: 'VS Code',        icon: '❯', color: '#0ea5e9', desc: 'One-click install or code CLI' },
  { id: 'gemini',         label: 'Gemini CLI',     icon: '✦', color: '#4285f4', desc: 'gemini mcp add command' },
  { id: 'windsurf',       label: 'Windsurf',       icon: '◈', color: '#06b6d4', desc: 'Edit mcp_config.json' },
  { id: 'prompt',         label: 'Any agent',      icon: '✦', color: '#6b7280', desc: 'Plain-English prompt' },
]

// ─── Block renderer ───────────────────────────────────────────────────────────

function blockIcon(kind) {
  if (kind === 'command') return <Terminal className="w-3.5 h-3.5" />
  if (kind === 'json') return <FileCode className="w-3.5 h-3.5" />
  if (kind === 'deeplink') return <Link className="w-3.5 h-3.5" />
  return <MessageSquare className="w-3.5 h-3.5" />
}

function Block({ block }) {
  if (block.kind === 'deeplink') {
    return (
      <div className="space-y-2">
        <div className="flex items-center gap-2 text-xs font-semibold text-gray-400 uppercase tracking-wider">
          {blockIcon(block.kind)}
          {block.title}
        </div>
        <p className="text-sm text-gray-400">{block.hint}</p>
        <a
          href={block.value}
          className="inline-flex items-center gap-2 px-4 py-2 bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-medium rounded-lg transition-colors"
        >
          <ExternalLink className="w-4 h-4" />
          {block.button || block.title}
        </a>
      </div>
    )
  }

  return (
    <div className="space-y-2">
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-2 text-xs font-semibold text-gray-400 uppercase tracking-wider">
          {blockIcon(block.kind)}
          {block.title}
        </div>
        <CopyButton text={block.value} />
      </div>
      <p className="text-sm text-gray-400">{block.hint}</p>
      <div className="relative rounded-lg bg-gray-950 border border-gray-800 overflow-hidden">
        <pre className="p-4 text-sm text-gray-200 overflow-x-auto leading-relaxed whitespace-pre-wrap break-all">
          <code>{block.value}</code>
        </pre>
      </div>
    </div>
  )
}

// ─── Step 1: Welcome ──────────────────────────────────────────────────────────

function WelcomeStep({ status, onStart }) {
  if (!status.active) {
    return (
      <div className="max-w-xl mx-auto">
        <div className="rounded-xl border border-amber-200 bg-amber-50 p-10 text-center space-y-4">
          <AlertTriangle className="w-10 h-10 text-amber-500 mx-auto" />
          <h2 className="text-xl font-semibold text-amber-900">Agent Connector isn't active</h2>
          <p className="text-sm text-amber-700 max-w-xs mx-auto">
            Enable the MCP server in{' '}
            <button
              className="underline font-medium"
              onClick={() => { window.location.hash = '/settings' }}
            >
              Settings
            </button>
            {' '}before connecting an agent.
          </p>
        </div>
      </div>
    )
  }

  return (
    <div className="max-w-xl mx-auto">
      <div className="bg-white rounded-2xl border border-gray-200 shadow-sm p-12 text-center space-y-6">
        <div className="flex justify-center">
          <div className="w-20 h-20 rounded-full bg-indigo-50 flex items-center justify-center">
            <Plug className="w-10 h-10 text-indigo-500" />
          </div>
        </div>

        <div className="space-y-3">
          <h2 className="text-2xl font-bold text-gray-900">Connect an agent to this site</h2>
          <p className="text-gray-500 max-w-xs mx-auto leading-relaxed">
            Give any AI agent access to this WordPress site over MCP. It takes about 30 seconds.
          </p>
        </div>

        <div className="space-y-3">
          <button
            onClick={onStart}
            className="inline-flex items-center justify-center gap-2 px-8 py-3 bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-semibold rounded-lg transition-colors w-full max-w-xs"
          >
            Get started
            <ArrowRight className="w-4 h-4" />
          </button>

        </div>
      </div>
    </div>
  )
}

// ─── Step 2: Pick agent ───────────────────────────────────────────────────────

function PickStep({ selectedAgent, onSelect, onBack, onContinue }) {
  return (
    <div className="space-y-6">
      <div className="flex items-center gap-3">
        <button
          onClick={onBack}
          className="p-1.5 rounded-lg text-gray-400 hover:text-gray-600 hover:bg-gray-100 transition-colors"
          aria-label="Back"
        >
          <ArrowLeft className="w-4 h-4" />
        </button>
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Which agent are you connecting?</h1>
          <p className="text-gray-500 mt-0.5 text-sm">We'll give you the exact setup instructions.</p>
        </div>
      </div>

      <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3">
        {AGENTS.map((agent) => {
          const isSelected = selectedAgent === agent.id
          return (
            <button
              key={agent.id}
              onClick={() => onSelect(agent.id)}
              className={[
                'flex flex-col items-start gap-2 p-4 rounded-xl border-2 text-left transition-all',
                isSelected
                  ? 'border-indigo-500 bg-indigo-50 shadow-sm'
                  : 'border-gray-200 bg-white hover:border-gray-300 hover:shadow-sm',
              ].join(' ')}
            >
              <span className="text-xl font-bold leading-none" style={{ color: agent.color }}>
                {agent.icon}
              </span>
              <div>
                <div className={`text-sm font-semibold ${isSelected ? 'text-indigo-700' : 'text-gray-800'}`}>
                  {agent.label}
                </div>
                <div className="text-xs text-gray-400 mt-0.5 leading-tight">{agent.desc}</div>
              </div>
            </button>
          )
        })}
      </div>

      <div>
        <button
          onClick={onContinue}
          className="inline-flex items-center gap-2 px-6 py-2.5 bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-semibold rounded-lg transition-colors"
        >
          Continue
          <ArrowRight className="w-4 h-4" />
        </button>
      </div>
    </div>
  )
}

// ─── Step 3: Instructions ─────────────────────────────────────────────────────

function ConnectStep({ selectedAgent, status, connection, generating, error, onGenerate, onBack }) {
  const [activeTab, setActiveTab] = useState(selectedAgent)
  const agentMeta = AGENTS.find((a) => a.id === selectedAgent) || AGENTS[0]
  const agentList = connection?.agents || []
  const agentData = agentList.find((a) => a.id === activeTab)

  return (
    <div className="space-y-6">
      {/* Back + heading */}
      <div className="flex items-center gap-3">
        <button
          onClick={onBack}
          className="p-1.5 rounded-lg text-gray-400 hover:text-gray-600 hover:bg-gray-100 transition-colors"
          aria-label="Back"
        >
          <ArrowLeft className="w-4 h-4" />
        </button>
        <div>
          <h1 className="text-2xl font-bold text-gray-900">
            Connect{' '}
            <span style={{ color: agentMeta.color }}>{agentMeta.label}</span>
          </h1>
          <p className="text-gray-500 mt-0.5 text-sm">
            Generate a one-time application password — it'll be wired right in.
          </p>
        </div>
      </div>

      {!connection ? (
        /* ── Pre-generate panel ── */
        <div className="bg-white rounded-xl border border-gray-200 p-6 space-y-5">
          <div className="grid grid-cols-2 gap-4 text-sm">
            <div>
              <span className="block text-xs text-gray-400 mb-1">MCP server URL</span>
              <code className="text-gray-700 break-all">{status.serverUrl}</code>
            </div>
            <div>
              <span className="block text-xs text-gray-400 mb-1">Authenticating as</span>
              <code className="text-gray-700">{status.username}</code>
            </div>
          </div>

          {!status.pwAvailable && (
            <div className="flex items-start gap-2 p-3 bg-red-50 border border-red-200 rounded-lg text-sm text-red-700">
              <AlertTriangle className="w-4 h-4 mt-0.5 flex-shrink-0" />
              Application passwords require HTTPS (or a local environment) and are not available on this site.
            </div>
          )}

          {error && (
            <div className="p-3 bg-red-50 border border-red-200 rounded-lg text-sm text-red-700">{error}</div>
          )}

          <button
            onClick={onGenerate}
            disabled={generating || !status.pwAvailable}
            className="flex items-center gap-2 px-5 py-2.5 bg-indigo-600 hover:bg-indigo-500 disabled:opacity-50 disabled:cursor-not-allowed text-white text-sm font-semibold rounded-lg transition-colors"
          >
            {generating ? (
              <>
                <RefreshCw className="w-4 h-4 animate-spin" />
                Generating…
              </>
            ) : (
              'Generate connection'
            )}
          </button>
        </div>
      ) : (
        /* ── Artifacts panel ── */
        <div className="space-y-4">
          <div className="flex items-start gap-3 p-4 bg-amber-50 border border-amber-200 rounded-xl">
            <AlertTriangle className="w-5 h-5 text-amber-500 flex-shrink-0 mt-0.5" />
            <div className="text-sm">
              <strong className="text-amber-800">Copy these credentials now</strong>
              <span className="text-amber-700"> — the application password won't be shown again. Treat it like an SSH key.</span>
            </div>
          </div>

          <div className="bg-white rounded-xl border border-gray-200 overflow-hidden">
            {/* Agent tab switcher */}
            <div className="flex overflow-x-auto border-b border-gray-100 px-4 pt-3 gap-1">
              {agentList.map((agent) => (
                <button
                  key={agent.id}
                  onClick={() => setActiveTab(agent.id)}
                  className={[
                    'px-3 py-1.5 text-sm font-medium rounded-t whitespace-nowrap transition-colors -mb-px border-b-2',
                    activeTab === agent.id
                      ? 'text-indigo-600 border-indigo-500'
                      : 'text-gray-500 border-transparent hover:text-gray-700',
                  ].join(' ')}
                >
                  {agent.label}
                </button>
              ))}
            </div>

            {/* Blocks */}
            <div className="p-6 space-y-6">
              {agentData?.blocks?.length ? (
                agentData.blocks.map((block, i) => <Block key={i} block={block} />)
              ) : (
                <p className="text-sm text-gray-400">No configuration available for this agent.</p>
              )}
            </div>
          </div>

          <div className="flex items-center justify-between text-sm">
            <span className="text-gray-400">Need a fresh password?</span>
            <button
              onClick={onGenerate}
              disabled={generating}
              className="flex items-center gap-1.5 text-gray-500 hover:text-gray-700 font-medium"
            >
              <RefreshCw className={`w-3.5 h-3.5 ${generating ? 'animate-spin' : ''}`} />
              Regenerate
            </button>
          </div>
        </div>
      )}
    </div>
  )
}

// ─── Root ─────────────────────────────────────────────────────────────────────

export default function Connect({ status, onStatusChange }) {
  const [step, setStep] = useState('welcome')
  const [selectedAgent, setSelectedAgent] = useState('claude-code')
  const [connection, setConnection] = useState(null)
  const [generating, setGenerating] = useState(false)
  const [error, setError] = useState(null)

  async function generate() {
    setGenerating(true)
    setError(null)
    try {
      const data = await api.generate()
      setConnection(data)
    } catch (e) {
      setError(e.message)
    } finally {
      setGenerating(false)
    }
  }

  if (step === 'welcome') {
    return <WelcomeStep status={status} onStart={() => setStep('pick')} />
  }

  if (step === 'pick') {
    return (
      <PickStep
        selectedAgent={selectedAgent}
        onSelect={setSelectedAgent}
        onBack={() => setStep('welcome')}
        onContinue={() => setStep('connect')}
      />
    )
  }

  return (
    <ConnectStep
      selectedAgent={selectedAgent}
      status={status}
      connection={connection}
      generating={generating}
      error={error}
      onGenerate={generate}
      onBack={() => setStep('pick')}
    />
  )
}
