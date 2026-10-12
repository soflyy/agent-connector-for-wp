import React, { useState, useEffect } from 'react'
import { RefreshCw, ExternalLink } from 'lucide-react'
import { api } from './api'

function PackStatus({ row }) {
  if (row.pack_active) {
    return <span className="inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold bg-green-100 text-green-700">Active</span>
  }
  if (row.pack_installed) {
    return <span className="inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold bg-yellow-100 text-yellow-700">Installed, inactive</span>
  }
  return <span className="inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold bg-blue-100 text-blue-700">Available</span>
}

function PackAction({ row, canManage, busy, onAction }) {
  if (!row.has_pack) return null
  const entry = row.entry || {}
  const slug = entry.ability_pack_slug || ''
  if (!canManage || !slug) return null

  const isBusy = busy === slug

  if (row.pack_active) {
    return (
      <button
        onClick={() => onAction('deactivate', slug)}
        disabled={isBusy}
        className="px-3 py-1 rounded text-xs font-medium bg-gray-100 hover:bg-gray-200 text-gray-600 disabled:opacity-50 transition-colors"
      >
        {isBusy ? <RefreshCw className="w-3 h-3 animate-spin inline" /> : 'Disable'}
      </button>
    )
  }
  if (row.pack_installed) {
    return (
      <button
        onClick={() => onAction('activate', slug)}
        disabled={isBusy}
        className="px-3 py-1 rounded text-xs font-medium bg-indigo-600 hover:bg-indigo-500 text-white disabled:opacity-50 transition-colors"
      >
        {isBusy ? <RefreshCw className="w-3 h-3 animate-spin inline" /> : 'Enable'}
      </button>
    )
  }
  if (entry.download_url) {
    return (
      <button
        onClick={() => onAction('install', slug)}
        disabled={isBusy}
        className="px-3 py-1 rounded text-xs font-medium bg-indigo-600 hover:bg-indigo-500 text-white disabled:opacity-50 transition-colors"
      >
        {isBusy ? <RefreshCw className="w-3 h-3 animate-spin inline" /> : 'Install & Activate'}
      </button>
    )
  }
  return null
}

export default function PacksTab() {
  const [directory, setDirectory] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)
  const [busy, setBusy] = useState(null)
  const [actionError, setActionError] = useState(null)

  useEffect(() => { fetchDirectory() }, [])

  async function fetchDirectory(force = false) {
    setLoading(true)
    setError(null)
    try {
      const data = force ? await api.refreshDirectory() : await api.getDirectory()
      setDirectory(data)
    } catch (e) {
      setError(e.message)
    } finally {
      setLoading(false)
    }
  }

  async function handleAction(type, slug) {
    setBusy(slug)
    setActionError(null)
    try {
      let result
      if (type === 'install') result = await api.installPack(slug)
      else if (type === 'activate') result = await api.activatePack(slug)
      else if (type === 'deactivate') result = await api.deactivatePack(slug)
      if (result?.directory) setDirectory(result.directory)
    } catch (e) {
      setActionError(e.message)
    } finally {
      setBusy(null)
    }
  }

  if (loading) {
    return (
      <div className="flex items-center gap-2 text-sm text-gray-400 py-8">
        <RefreshCw className="w-4 h-4 animate-spin" />
        Loading directory…
      </div>
    )
  }

  if (error) {
    return (
      <div className="p-4 rounded-xl bg-red-50 border border-red-200 text-sm text-red-700">
        {error}
        <button onClick={() => fetchDirectory()} className="ml-3 underline">Retry</button>
      </div>
    )
  }

  const rows = directory?.rows || []
  const canManage = directory?.can_manage ?? false

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <p className="text-sm text-gray-500 max-w-prose">
          Your active plugins and, for each, the optional ability pack we generate. Installing a pack is always optional.
        </p>
        <button
          onClick={() => fetchDirectory(true)}
          disabled={loading}
          className="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-sm font-medium bg-gray-100 hover:bg-gray-200 text-gray-600 disabled:opacity-50 transition-colors"
        >
          <RefreshCw className={`w-3.5 h-3.5 ${loading ? 'animate-spin' : ''}`} />
          Refresh
        </button>
      </div>

      {actionError && (
        <div className="p-3 rounded-lg bg-red-50 border border-red-200 text-sm text-red-700">{actionError}</div>
      )}

      {directory?.error && (
        <div className="p-3 rounded-lg bg-amber-50 border border-amber-200 text-sm text-amber-700">{directory.error}</div>
      )}

      {rows.length === 0 ? (
        <div className="py-10 text-center text-sm text-gray-400">No active plugins found.</div>
      ) : (
        <div className="bg-white rounded-xl border border-gray-200 overflow-hidden">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-gray-100 bg-gray-50 text-xs font-semibold text-gray-500 uppercase tracking-wider">
                <th className="px-5 py-3 text-left">Active plugin</th>
                <th className="px-5 py-3 text-left">Ability pack</th>
                <th className="px-5 py-3 text-left">Status</th>
                <th className="px-5 py-3 text-left"></th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-50">
              {rows.map((row) => {
                const entry = row.entry || {}
                return (
                  <tr key={row.plugin_file} className="hover:bg-gray-50 transition-colors">
                    <td className="px-5 py-3">
                      <div className="font-medium text-gray-800">{row.plugin_name}</div>
                      <div className="text-xs text-gray-400 font-mono mt-0.5">{row.plugin_file}</div>
                    </td>
                    <td className="px-5 py-3">
                      {row.has_pack ? (
                        <div>
                          <div className="font-medium text-gray-800 flex items-center gap-1.5">
                            {entry.source_url ? (
                              <a href={entry.source_url} target="_blank" rel="noopener noreferrer" className="hover:text-indigo-600 flex items-center gap-1">
                                {entry.ability_pack_name}
                                <ExternalLink className="w-3 h-3" />
                              </a>
                            ) : (
                              entry.ability_pack_name
                            )}
                            {entry.version && (
                              <span className="text-xs text-gray-400">v{entry.version}</span>
                            )}
                          </div>
                          {entry.description && (
                            <p className="text-xs text-gray-400 mt-0.5 max-w-xs">{entry.description}</p>
                          )}
                        </div>
                      ) : (
                        <span className="text-gray-400">None available</span>
                      )}
                    </td>
                    <td className="px-5 py-3">
                      {row.has_pack && <PackStatus row={row} />}
                    </td>
                    <td className="px-5 py-3">
                      <PackAction
                        row={row}
                        canManage={canManage}
                        busy={busy}
                        onAction={handleAction}
                      />
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}

