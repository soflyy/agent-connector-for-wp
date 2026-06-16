import React, { useState } from 'react'
import { Save, RefreshCw, AlertTriangle, ShieldAlert, Lock, Bug } from 'lucide-react'
import { api } from '../api'

function Toggle({ checked, onChange, disabled }) {
  return (
    <button
      role="switch"
      aria-checked={checked}
      disabled={disabled}
      onClick={() => onChange(!checked)}
      className={[
        'relative inline-flex h-7 w-12 items-center rounded-full transition-colors focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 disabled:opacity-40 disabled:cursor-not-allowed',
        checked ? 'bg-indigo-600' : 'bg-gray-200',
      ].join(' ')}
    >
      <span
        className={[
          'inline-block h-5 w-5 transform rounded-full bg-white shadow transition-transform',
          checked ? 'translate-x-6' : 'translate-x-1',
        ].join(' ')}
      />
    </button>
  )
}

function Section({ title, icon: Icon, children }) {
  return (
    <div className="bg-white rounded-xl border border-gray-200 overflow-hidden">
      <div className="px-6 py-5 border-b border-gray-100 flex items-center gap-2.5">
        <Icon className="w-5 h-5 text-gray-400" />
        <h2 className="text-base font-semibold text-gray-700">{title}</h2>
      </div>
      <div className="divide-y divide-gray-50">{children}</div>
    </div>
  )
}

function Row({ label, description, control, danger }) {
  return (
    <div className={`px-6 py-5 flex items-start justify-between gap-6 ${danger ? 'bg-red-50' : ''}`}>
      <div className="flex-1 min-w-0">
        <div className={`text-base font-medium ${danger ? 'text-red-800' : 'text-gray-800'}`}>{label}</div>
        {description && (
          <p className={`text-sm mt-1 ${danger ? 'text-red-600' : 'text-gray-400'}`}>{description}</p>
        )}
      </div>
      <div className="flex-shrink-0 pt-0.5">{control}</div>
    </div>
  )
}

export default function Settings({ status, onStatusChange }) {
  const [form, setForm] = useState({
    enabled: status.enabled,
    productionOverride: status.productionOverride,
    mcpDebug: status.mcpDebug,
  })
  const [saving, setSaving] = useState(false)
  const [reconnecting, setReconnecting] = useState(false)
  const [notice, setNotice] = useState(null)

  function set(key, val) {
    setForm((f) => ({ ...f, [key]: val }))
  }

  async function save() {
    setSaving(true)
    setNotice(null)
    try {
      const result = await api.saveSettings({
        enabled: form.enabled,
        production_override: form.productionOverride,
        mcp_debug: form.mcpDebug,
      })
      onStatusChange((s) => ({ ...s, ...result.status }))
      setNotice({ type: 'success', message: 'Settings saved.' })
    } catch (e) {
      setNotice({ type: 'error', message: e.message })
    } finally {
      setSaving(false)
    }
  }

  async function reconnect() {
    setReconnecting(true)
    setNotice(null)
    try {
      const result = await api.reconnect()
      onStatusChange((s) => ({ ...s, ...result.status }))
      setNotice({ type: 'success', message: 'Reconnected to this domain.' })
    } catch (e) {
      setNotice({ type: 'error', message: e.message })
    } finally {
      setReconnecting(false)
    }
  }

  const lockMismatch =
    status.active && status.lockedHost !== '' && status.lockedHost !== status.declaredHost

  return (
    <div className="max-w-3xl mx-auto space-y-6">
      <div>
        <h1 className="text-2xl font-bold text-gray-900">Settings</h1>
        <p className="mt-1 text-base text-gray-500">Configure the MCP server and plugin behaviour.</p>
      </div>

      {notice && (
        <div
          className={[
            'p-4 rounded-xl text-base font-medium',
            notice.type === 'success'
              ? 'bg-green-50 text-green-700 border border-green-200'
              : 'bg-red-50 text-red-700 border border-red-200',
          ].join(' ')}
        >
          {notice.message}
        </div>
      )}

      {/* Abilities */}
      <Section title="Abilities" icon={ShieldAlert}>
        <Row
          label="Enable Agent Connector"
          description="Runs the MCP server and exposes abilities registered by other plugins."
          control={
            <Toggle checked={form.enabled} onChange={(v) => set('enabled', v)} />
          }
        />

        {!status.isNonProd && (
          <Row
            label="Production override"
            description="This site reports a production environment type. Enabling without this override is blocked."
            danger
            control={
              <Toggle
                checked={form.productionOverride}
                onChange={(v) => set('productionOverride', v)}
              />
            }
          />
        )}
      </Section>

      {/* Domain lock */}
      <Section title="Domain lock" icon={Lock}>
        {lockMismatch && (
          <div className="px-6 py-4 bg-red-50 border-b border-red-100 flex items-start gap-2.5 text-base text-red-700">
            <AlertTriangle className="w-5 h-5 flex-shrink-0 mt-0.5" />
            <span>
              Domain mismatch — abilities are blocked. Locked to{' '}
              <code className="font-mono">{status.lockedHost}</code>, but this site reports{' '}
              <code className="font-mono">{status.declaredHost || '(unknown)'}</code>.
            </span>
          </div>
        )}
        <Row
          label="Locked to"
          description="Abilities are blocked on any other domain to protect copied databases."
          control={
            <code className="text-sm text-gray-500 font-mono">
              {status.lockedHost || '(not locked)'}
            </code>
          }
        />
        <Row
          label="This site reports"
          control={
            <code className="text-sm text-gray-500 font-mono">
              {status.declaredHost || '(unknown)'}
            </code>
          }
        />
        <div className="px-6 py-5">
          <button
            onClick={reconnect}
            disabled={reconnecting}
            className={[
              'flex items-center gap-2 px-5 py-2.5 rounded-lg text-base font-medium transition-colors',
              lockMismatch
                ? 'bg-red-600 hover:bg-red-500 text-white'
                : 'bg-gray-100 hover:bg-gray-200 text-gray-700',
            ].join(' ')}
          >
            <RefreshCw className={`w-4 h-4 ${reconnecting ? 'animate-spin' : ''}`} />
            Reconnect to this domain
          </button>
        </div>
      </Section>

      {/* Debug */}
      <Section title="Debug" icon={Bug}>
        <Row
          label="Log MCP events"
          description="Records every MCP request — including raw JSON-RPC bodies — in the database. Bodies can contain sensitive data. Leave off unless debugging."
          control={
            <Toggle checked={form.mcpDebug} onChange={(v) => set('mcpDebug', v)} />
          }
        />
      </Section>

      {/* Save */}
      <div className="flex items-center gap-3">
        <button
          onClick={save}
          disabled={saving}
          className="flex items-center gap-2.5 px-6 py-3 bg-indigo-600 hover:bg-indigo-500 disabled:opacity-50 disabled:cursor-not-allowed text-white text-base font-semibold rounded-lg transition-colors"
        >
          {saving ? (
            <>
              <RefreshCw className="w-5 h-5 animate-spin" />
              Saving…
            </>
          ) : (
            <>
              <Save className="w-5 h-5" />
              Save changes
            </>
          )}
        </button>
      </div>
    </div>
  )
}
