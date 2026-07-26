import React, { useState, useEffect, useCallback } from 'react'
import { AlertTriangle, RefreshCw, Trash2, Plug } from 'lucide-react'
import { api } from '../api'

// The REST layer returns UTC datetimes in MySQL format ('Y-m-d H:i:s').
function parseUtc(s) {
  if (!s) return null
  const d = new Date(s.replace(' ', 'T') + 'Z')
  return isNaN(d.getTime()) ? null : d
}

// Used for tooltips only, so an empty string simply means no tooltip.
function formatTime(s) {
  const d = parseUtc(s)
  return d ? d.toLocaleString() : ''
}

function formatRelative(s) {
  const d = parseUtc(s)
  if (!d) return 'Never'

  const seconds = Math.floor((Date.now() - d.getTime()) / 1000)
  if (seconds < 60) return 'Just now'
  if (seconds < 3600) {
    const m = Math.floor(seconds / 60)
    return `${m} minute${m === 1 ? '' : 's'} ago`
  }
  if (seconds < 86400) {
    const h = Math.floor(seconds / 3600)
    return `${h} hour${h === 1 ? '' : 's'} ago`
  }
  const days = Math.floor(seconds / 86400)
  if (days < 30) return `${days} day${days === 1 ? '' : 's'} ago`
  return d.toLocaleDateString()
}

function ScopeBadges({ scope }) {
  const scopes = (scope || '').split(' ').map(s => s.trim()).filter(Boolean)
  if (scopes.length === 0) return <span className="text-gray-400">None</span>
  return (
    <div className="flex flex-wrap gap-1">
      {scopes.map(s => (
        <code key={s} className="text-sm bg-gray-100 text-gray-600 px-1.5 py-0.5 rounded">{s}</code>
      ))}
    </div>
  )
}

export default function Connections() {
  const [data, setData] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)
  const [busy, setBusy] = useState(null) // client_id being revoked, or 'cleanup'

  const load = useCallback(() => {
    setLoading(true)
    setError(null)
    api.getOauthClients()
      .then(setData)
      .catch(e => setError(e.message))
      .finally(() => setLoading(false))
  }, [])

  useEffect(() => { load() }, [load])

  async function handleRevoke(client) {
    const label = client.client_name || client.client_id
    if (!confirm(
      `Disconnect “${label}”?\n\n` +
      'Its access tokens stop working immediately and the registration is removed. ' +
      'The app must be authorized again to reconnect.'
    )) return

    setBusy(client.client_id)
    setError(null)
    try {
      await api.revokeOauthClient(client.client_id)
      load()
    } catch (e) {
      setError(e.message)
    } finally {
      setBusy(null)
    }
  }

  async function handleCleanup() {
    setBusy('cleanup')
    setError(null)
    try {
      await api.cleanupOauthClients()
      load()
    } catch (e) {
      setError(e.message)
    } finally {
      setBusy(null)
    }
  }

  const connections = data?.connections ?? []
  const unusedCount = data?.unused_count ?? 0

  return (
    <div className="space-y-4">
      <h1 className="text-2xl font-bold text-gray-900">Connections</h1>

      <p className="text-base text-gray-500 max-w-3xl">
        Agents that an administrator authorized over OAuth. Each one holds a token that acts as the
        user who approved it, until you disconnect it here. Connections made with an application
        password are not listed here. Those are managed on the user's WordPress profile screen.
      </p>

      {/* Toolbar */}
      <div className="flex items-center gap-2 flex-wrap">
        <button
          onClick={load}
          disabled={loading}
          className="flex items-center gap-2 px-4 py-2 text-base text-gray-600 hover:text-gray-800 border border-gray-300 rounded-lg bg-white hover:bg-gray-50 transition-colors disabled:opacity-50"
        >
          <RefreshCw className={`w-4 h-4 ${loading ? 'animate-spin' : ''}`} />
          Refresh
        </button>
        <div className="ml-auto text-base text-gray-500">
          {connections.length} connected agent{connections.length !== 1 ? 's' : ''}
        </div>
      </div>

      {error && (
        <div className="p-4 bg-red-50 border border-red-200 rounded-xl text-base text-red-700">{error}</div>
      )}

      {/* Table */}
      <div className="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <table className="w-full text-sm">
          <thead>
            <tr className="bg-gray-50 border-b border-gray-200">
              {['Agent', 'Authorized by', 'Permissions', 'Connected since', 'Last active', ''].map(h => (
                <th key={h} className="px-4 py-3 text-left text-sm font-semibold text-gray-500 uppercase tracking-wider last:text-right">
                  {h}
                </th>
              ))}
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-100">
            {loading && !data && (
              <tr>
                <td colSpan={6} className="px-4 py-8 text-center">
                  <RefreshCw className="w-5 h-5 animate-spin text-gray-400 mx-auto" />
                </td>
              </tr>
            )}

            {!loading && connections.length === 0 && (
              <tr>
                <td colSpan={6} className="px-4 py-10 text-center">
                  <Plug className="w-6 h-6 text-gray-300 mx-auto mb-2" />
                  <p className="text-base text-gray-400">No agents are connected over OAuth.</p>
                </td>
              </tr>
            )}

            {connections.map(c => (
              <tr key={c.client_id} className="hover:bg-gray-50 transition-colors align-top">
                <td className="px-4 py-3">
                  <div className="font-medium text-gray-900 text-base">{c.client_name || 'Unnamed app'}</div>
                  {c.redirect_uris?.[0] && (
                    <div className="text-sm text-gray-400 break-all mt-0.5">{c.redirect_uris[0]}</div>
                  )}
                </td>
                <td className="px-4 py-3 text-gray-700">
                  {c.user_missing
                    ? <span className="text-amber-600">User deleted</span>
                    : (c.user_name || c.user_login || 'Unknown')}
                </td>
                <td className="px-4 py-3"><ScopeBadges scope={c.scope} /></td>
                <td className="px-4 py-3 text-gray-500 whitespace-nowrap" title={formatTime(c.granted_at)}>
                  {formatRelative(c.granted_at)}
                </td>
                <td className="px-4 py-3 text-gray-500 whitespace-nowrap" title={c.last_used_at ? formatTime(c.last_used_at) : ''}>
                  {formatRelative(c.last_used_at)}
                  {c.last_ip && <div className="text-sm text-gray-400">{c.last_ip}</div>}
                </td>
                <td className="px-4 py-3 text-right whitespace-nowrap">
                  <button
                    onClick={() => handleRevoke(c)}
                    disabled={busy === c.client_id}
                    className="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm text-red-600 hover:text-red-800 border border-red-200 rounded-lg bg-white hover:bg-red-50 transition-colors disabled:opacity-40"
                  >
                    <Trash2 className="w-4 h-4" />
                    Disconnect
                  </button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {/* Never-authorized registrations. Registration is public per RFC 7591,
          so anyone can create these; they grant nothing on their own. */}
      {unusedCount > 0 && (
        <div className="flex items-start gap-2.5 p-4 bg-gray-50 border border-gray-200 rounded-xl text-base text-gray-600">
          <AlertTriangle className="w-5 h-5 mt-0.5 flex-shrink-0 text-gray-400" />
          <div className="flex-1">
            <p>
              {unusedCount} app registration{unusedCount !== 1 ? 's' : ''} never completed sign-in.
            </p>
            <p className="text-sm text-gray-500 mt-1">
              Any client can register itself, which is how agents discover this site. A registration
              grants no access until an administrator approves it on the consent screen, so these are
              inert, but you can clear them.
            </p>
          </div>
          <button
            onClick={handleCleanup}
            disabled={busy === 'cleanup'}
            className="flex-shrink-0 px-4 py-2 text-base text-gray-600 hover:text-gray-800 border border-gray-300 rounded-lg bg-white hover:bg-gray-50 transition-colors disabled:opacity-40"
          >
            Clear
          </button>
        </div>
      )}
    </div>
  )
}
