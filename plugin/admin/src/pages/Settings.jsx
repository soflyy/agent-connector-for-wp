import React, { useState, useEffect } from 'react'
import { RefreshCw, AlertTriangle, ShieldAlert, Shield, Bug, Package, CheckCircle2, Download } from 'lucide-react'
import { api, normalizeStatus } from '../api'
import Connections from './Connections'

const TABS = [
  { id: 'general', label: 'General' },
  { id: 'connections', label: 'Connections' },
]

// The active tab lives in the hash so it survives a reload and can be linked
// to directly: #/settings/connections.
function getTab() {
  return window.location.hash.replace('#/', '') === 'settings/connections'
    ? 'connections'
    : 'general'
}

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

function UapInstallDialog({ onConfirm, onCancel, installing }) {
  useEffect(() => {
    document.body.style.overflow = 'hidden'
    return () => { document.body.style.overflow = '' }
  }, [])

  return (
    <div className="fixed inset-0 z-[200] flex items-center justify-center p-6">
      <div className="absolute inset-0 bg-black/50" onClick={onCancel} />
      <div className="relative bg-white rounded-2xl shadow-2xl max-w-lg w-full p-8 space-y-6">
        <div className="flex items-start gap-4">
          <div className="flex-shrink-0 w-12 h-12 rounded-full bg-indigo-50 flex items-center justify-center">
            <Package className="w-6 h-6 text-indigo-500" />
          </div>
          <div>
            <h2 className="text-xl font-bold text-gray-900">Install Universal Abilities Plugin?</h2>
            <p className="mt-1 text-sm text-gray-500">Universal Abilities for Agent Connector</p>
          </div>
        </div>

        <p className="text-base text-gray-700">
          This companion plugin gives your AI agent complete access to this WordPress install. Once installed and enabled, the agent can:
        </p>

        <ul className="space-y-2 text-base text-gray-700">
          {[
            ['Run arbitrary shell commands', 'Full command-line access to the server'],
            ['Evaluate PHP code', 'Execute any PHP in the WordPress context'],
            ['Read, write, and delete files', 'Full filesystem access'],
            ['Run WP-CLI commands', 'Any wp-cli command on your install'],
            ['Create one-time admin login links', 'Let your agent log into your wp-admin'],
          ].map(([title, desc]) => (
            <li key={title} className="flex items-start gap-3">
              <span className="mt-1 w-2 h-2 rounded-full bg-amber-400 flex-shrink-0" />
              <span><span className="font-semibold">{title}</span> — {desc}</span>
            </li>
          ))}
        </ul>

        <div className="flex gap-3 justify-end">
          <button
            onClick={onCancel}
            disabled={installing}
            className="px-5 py-2.5 rounded-lg text-base font-medium text-gray-700 bg-gray-100 hover:bg-gray-200 transition-colors disabled:opacity-50"
          >
            Cancel
          </button>
          <button
            onClick={onConfirm}
            disabled={installing}
            className="flex items-center gap-2 px-5 py-2.5 rounded-lg text-base font-medium text-white bg-indigo-600 hover:bg-indigo-500 transition-colors disabled:opacity-50"
          >
            {installing ? (
              <><RefreshCw className="w-4 h-4 animate-spin" /> Installing…</>
            ) : (
              <><Download className="w-4 h-4" /> Install & Activate</>
            )}
          </button>
        </div>
      </div>
    </div>
  )
}

export default function Settings({ status, onStatusChange }) {
  const [tab, setTab] = useState(getTab)

  // The hash is the single source of truth, so tab clicks and the Settings nav
  // button (which resets the hash to #/settings) both land in the same place.
  useEffect(() => {
    const onHashChange = () => setTab(getTab())
    window.addEventListener('hashchange', onHashChange)
    return () => window.removeEventListener('hashchange', onHashChange)
  }, [])

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
  const [uapActive, setUapActive] = useState(status.uapActive ?? false)
  const [showUapDialog, setShowUapDialog] = useState(false)
  const [installingUap, setInstallingUap] = useState(false)

  // The site-wide "install Universal Abilities" admin notice deep-links here
  // with ?acfw_uap=install so the operator sees the same confirmation dialog
  // as the in-app Install button. Open it once on arrival, then strip the
  // param so a refresh doesn't reopen it.
  useEffect(() => {
    const params = new URLSearchParams(window.location.search)
    if (params.get('acfw_uap') === 'install' && !uapActive) {
      setShowUapDialog(true)
      params.delete('acfw_uap')
      const qs = params.toString()
      window.history.replaceState(
        null,
        '',
        window.location.pathname + (qs ? `?${qs}` : '') + window.location.hash,
      )
    }
  }, [])

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

  async function installUap() {
    setInstallingUap(true)
    try {
      await api.installUap()
      setUapActive(true)
      setShowUapDialog(false)
    } catch (e) {
      setShowUapDialog(false)
      setSaveStatus('error')
      setSaveError(e.message)
    } finally {
      setInstallingUap(false)
    }
  }

  const lockMismatch =
    form.domainLockEnabled && status.lockedHost !== '' && status.lockedHost !== status.declaredHost

  return (
    <>
      {showUapDialog && (
        <UapInstallDialog
          onConfirm={installUap}
          onCancel={() => setShowUapDialog(false)}
          installing={installingUap}
        />
      )}
      {/* The connections table needs more room than the settings form. */}
      <div className={`${tab === 'connections' ? 'max-w-5xl' : 'max-w-3xl'} mx-auto space-y-6`}>
        <div className="flex items-start justify-between gap-4">
          <div>
            <h1 className="text-2xl font-bold text-gray-900">Settings</h1>
            {tab === 'general' && (
              <p className="mt-1 text-base text-gray-500">Configure the MCP server and plugin behaviour.</p>
            )}
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

        <div className="flex items-center gap-1 border-b border-gray-200">
          {TABS.map(({ id, label }) => (
            <button
              key={id}
              onClick={() => { window.location.hash = id === 'connections' ? '/settings/connections' : '/settings' }}
              className={[
                'px-4 py-2 -mb-px text-base font-medium border-b-2 transition-colors',
                tab === id
                  ? 'border-indigo-500 text-indigo-600'
                  : 'border-transparent text-gray-500 hover:text-gray-700',
              ].join(' ')}
            >
              {label}
            </button>
          ))}
        </div>

        {tab === 'connections' && <Connections />}

        {tab === 'general' && (
          <>
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

          {!status.oauthTransportAllowed && (
            <Row
              label="OAuth sign-in"
              description="Requires HTTPS (or a local environment), the same rule WordPress applies to application passwords. Over plain HTTP the sign-in tokens would be sent unencrypted, so the OAuth endpoints stay offline until the site uses HTTPS."
              control={
                <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-sm font-medium bg-amber-100 text-amber-700">
                  <AlertTriangle className="w-4 h-4" /> Unavailable
                </span>
              }
            />
          )}

          <Row
            label="Universal Abilities Plugin"
            description="Shell, PHP eval, filesystem, WP-CLI, and admin login link — exposed over MCP."
            control={
              uapActive ? (
                <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-sm font-medium bg-green-500/15 text-green-700">
                  <CheckCircle2 className="w-4 h-4" /> Active
                </span>
              ) : (
                <button
                  onClick={() => setShowUapDialog(true)}
                  className="flex items-center gap-2 px-4 py-2 text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-500 rounded-lg transition-colors"
                >
                  <Package className="w-4 h-4" /> Install
                </button>
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
          </>
        )}
      </div>
    </>
  )
}
