import React, { useState } from 'react'
import { RefreshCw, AlertTriangle, ShieldAlert, Shield, Bug, Package, CheckCircle2 } from 'lucide-react'
import { api, normalizeStatus, UNIVERSAL_ABILITIES_URL } from '../api'

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
    blockProduction: status.blockProduction,
    domainLockEnabled: status.domainLockEnabled,
    hideProdWarning: status.hideProdWarning,
    mcpDebug: status.mcpDebug,
  })
  const [saveStatus, setSaveStatus] = useState(null) // null | 'saving' | 'saved' | 'error'
  const [saveError, setSaveError] = useState(null)
  const [reconnecting, setReconnecting] = useState(false)
  const [reconnectNotice, setReconnectNotice] = useState(null)
  const uapActive = status.uapActive ?? false

  async function setAndSave(key, val) {
    const newForm = { ...form, [key]: val }
    setForm(newForm)
    setSaveStatus('saving')
    setSaveError(null)
    try {
      const result = await api.saveSettings({
        enabled: newForm.enabled,
        block_production: newForm.blockProduction,
        domain_lock_enabled: newForm.domainLockEnabled,
        hide_production_warning: newForm.hideProdWarning,
        mcp_debug: newForm.mcpDebug,
      })
      onStatusChange((s) => ({ ...s, ...normalizeStatus(result.status) }))
      setSaveStatus('saved')
      setTimeout(() => setSaveStatus(null), 2000)
    } catch (e) {
      setSaveStatus('error')
      setSaveError(e.message)
    }
  }

  async function reconnect() {
    setReconnecting(true)
    setReconnectNotice(null)
    try {
      const result = await api.reconnect()
      onStatusChange((s) => ({ ...s, ...normalizeStatus(result.status) }))
      setReconnectNotice({ type: 'success', message: 'Reconnected to this domain.' })
    } catch (e) {
      setReconnectNotice({ type: 'error', message: e.message })
    } finally {
      setReconnecting(false)
    }
  }

  const lockMismatch =
    form.domainLockEnabled && status.lockedHost !== '' && status.lockedHost !== status.declaredHost

  return (
    <>
      <div className="max-w-3xl mx-auto space-y-6">
        <div className="flex items-start justify-between gap-4">
          <div>
            <h1 className="text-2xl font-bold text-gray-900">Settings</h1>
            <p className="mt-1 text-base text-gray-500">Configure the MCP server and plugin behaviour.</p>
          </div>
          <div className="pt-1 min-w-[100px] text-right">
            {saveStatus === 'saving' && (
              <span className="inline-flex items-center gap-1.5 text-sm text-gray-400">
                <RefreshCw className="w-3.5 h-3.5 animate-spin" /> Saving…
              </span>
            )}
            {saveStatus === 'saved' && (
              <span className="inline-flex items-center gap-1.5 text-sm text-green-600">
                <CheckCircle2 className="w-3.5 h-3.5" /> Saved
              </span>
            )}
            {saveStatus === 'error' && (
              <span className="text-sm text-red-600">{saveError || 'Save failed'}</span>
            )}
          </div>
        </div>

        {reconnectNotice && (
          <div
            className={[
              'p-4 rounded-xl text-base font-medium',
              reconnectNotice.type === 'success'
                ? 'bg-green-50 text-green-700 border border-green-200'
                : 'bg-red-50 text-red-700 border border-red-200',
            ].join(' ')}
          >
            {reconnectNotice.message}
          </div>
        )}

        {/* Abilities */}
        <Section title="Abilities" icon={ShieldAlert}>
          <Row
            label="Enable Agent Connector"
            description="Runs the MCP server and exposes abilities registered by other plugins."
            control={
              <Toggle checked={form.enabled} onChange={(v) => setAndSave('enabled', v)} />
            }
          />

          <Row
            label="Universal Abilities Plugin"
            description="Shell, PHP eval, filesystem, WP-CLI, and admin login link — exposed over MCP. An optional companion plugin, installed separately."
            control={
              uapActive ? (
                <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-sm font-medium bg-green-500/15 text-green-700">
                  <CheckCircle2 className="w-4 h-4" /> Active
                </span>
              ) : (
                <a
                  href={UNIVERSAL_ABILITIES_URL}
                  target="_blank"
                  rel="noopener noreferrer"
                  className="flex items-center gap-2 px-4 py-2 text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-500 rounded-lg transition-colors"
                >
                  <Package className="w-4 h-4" /> Get Universal Abilities
                </a>
              )
            }
          />

        </Section>

        {/* Protection */}
        <Section title="Protection" icon={Shield}>
          <Row
            label="Disable production warning"
            description="Hides the site-wide wp-admin warning shown while Agent Connector runs on a production environment. Clicking “I understand” on the warning sets this too."
            control={
              <Toggle
                checked={form.hideProdWarning}
                onChange={(v) => setAndSave('hideProdWarning', v)}
              />
            }
          />

          <Row
            label="Block on production environments"
            description={`Stops the MCP server entirely while wp_get_environment_type() reports "production". This site currently reports "${status.envType}".`}
            control={
              <Toggle
                checked={form.blockProduction}
                onChange={(v) => setAndSave('blockProduction', v)}
              />
            }
          />

          {form.blockProduction && !status.isNonProd && (
            <div className="px-6 py-4 bg-amber-50 border-b border-amber-100 flex items-start gap-2.5 text-base text-amber-700">
              <AlertTriangle className="w-5 h-5 flex-shrink-0 mt-0.5" />
              <span>
                This site reports a production environment, so Agent Connector is now inactive.
                Turn this off to use it here.
              </span>
            </div>
          )}

          <Row
            label="Enable domain lock"
            description="Blocks all abilities if the site's domain changes — protects databases copied to another site (e.g. staging pushed to production)."
            control={
              <Toggle
                checked={form.domainLockEnabled}
                onChange={(v) => setAndSave('domainLockEnabled', v)}
              />
            }
          />

          {form.domainLockEnabled && (
            <>
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
                description="Abilities are blocked on any other domain."
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
              <div className="px-6 py-5 space-y-3">
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
            </>
          )}
        </Section>

        {/* Debug */}
        <Section title="Debug" icon={Bug}>
          <Row
            label="Log MCP events"
            description="Records every MCP request — including raw JSON-RPC bodies — in the database. Bodies can contain sensitive data. Leave off unless debugging."
            control={
              <Toggle checked={form.mcpDebug} onChange={(v) => setAndSave('mcpDebug', v)} />
            }
          />
        </Section>
      </div>
    </>
  )
}
