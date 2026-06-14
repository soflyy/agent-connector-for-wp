import React, { useState, useEffect, useMemo } from 'react'
import { RefreshCw, ExternalLink, ChevronDown, ChevronRight, Search, Package } from 'lucide-react'
import { api } from '../api'

// ─── Packs tab ───────────────────────────────────────────────────────────────

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

function PacksTab() {
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

// ─── Registered abilities tab ─────────────────────────────────────────────────

const PER_PAGE = 50

function AbilityRow({ ability }) {
  const [open, setOpen] = useState(false)

  return (
    <div className="border-b border-gray-50 last:border-0">
      <div className="px-5 py-3 flex items-center gap-4">
        <div className="flex-1 min-w-0">
          <div className="flex items-center gap-2 flex-wrap">
            {ability.label && ability.label !== ability.name && (
              <span className="font-medium text-gray-800">{ability.label}</span>
            )}
            <code className="text-xs text-gray-500 font-mono">{ability.name}</code>
          </div>
          {ability.category && (
            <div className="text-xs text-gray-400 mt-0.5">{ability.category}</div>
          )}
        </div>
        <div className="flex-shrink-0">
          {ability.mcp_public ? (
            <span className="inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold bg-green-100 text-green-700">MCP</span>
          ) : (
            <span className="inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold bg-gray-100 text-gray-400">–</span>
          )}
        </div>
        <button
          onClick={() => setOpen((o) => !o)}
          className="flex-shrink-0 p-1 rounded text-gray-400 hover:text-gray-600 hover:bg-gray-100 transition-colors"
          aria-label={open ? 'Collapse' : 'Expand'}
        >
          {open ? <ChevronDown className="w-4 h-4" /> : <ChevronRight className="w-4 h-4" />}
        </button>
      </div>

      {open && (
        <div className="px-5 pb-4 space-y-3 bg-gray-50 border-t border-gray-100">
          {ability.description && (
            <p className="text-sm text-gray-600 pt-3">{ability.description}</p>
          )}
          {ability.annotations && Object.keys(ability.annotations).length > 0 && (
            <div>
              <div className="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1">Annotations</div>
              <pre className="text-xs bg-gray-100 rounded p-3 overflow-x-auto text-gray-700 whitespace-pre-wrap">
                {JSON.stringify(ability.annotations, null, 2)}
              </pre>
            </div>
          )}
          <div>
            <div className="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1">Input schema</div>
            <pre className="text-xs bg-gray-100 rounded p-3 overflow-x-auto text-gray-700 whitespace-pre-wrap">
              {JSON.stringify(ability.input_schema, null, 2)}
            </pre>
          </div>
          {ability.output_schema && (
            <div>
              <div className="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1">Output schema</div>
              <pre className="text-xs bg-gray-100 rounded p-3 overflow-x-auto text-gray-700 whitespace-pre-wrap">
                {JSON.stringify(ability.output_schema, null, 2)}
              </pre>
            </div>
          )}
        </div>
      )}
    </div>
  )
}

function AbilitiesTab() {
  const [data, setData] = useState(null)
  const [loading, setLoading] = useState(true)
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)

  useEffect(() => {
    api.getRegisteredAbilities()
      .then((d) => setData(d))
      .catch(() => {})
      .finally(() => setLoading(false))
  }, [])

  const filtered = useMemo(() => {
    if (!data?.abilities) return []
    const needle = search.toLowerCase()
    if (!needle) return data.abilities
    return data.abilities.filter((a) =>
      (a.name + ' ' + a.label + ' ' + a.description + ' ' + a.category).toLowerCase().includes(needle)
    )
  }, [data, search])

  const totalPages = Math.max(1, Math.ceil(filtered.length / PER_PAGE))
  const safePage = Math.min(page, totalPages)
  const pageItems = filtered.slice((safePage - 1) * PER_PAGE, safePage * PER_PAGE)

  function handleSearch(e) {
    setSearch(e.target.value)
    setPage(1)
  }

  if (loading) {
    return (
      <div className="flex items-center gap-2 text-sm text-gray-400 py-8">
        <RefreshCw className="w-4 h-4 animate-spin" />
        Loading abilities…
      </div>
    )
  }

  return (
    <div className="space-y-4">
      <p className="text-sm text-gray-500 max-w-prose">
        Every ability registered on this site through the WordPress Abilities API. Abilities exposed via MCP are surfaced to connected agents while the plugin is enabled.
      </p>

      <div className="relative">
        <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400 pointer-events-none" />
        <input
          type="search"
          value={search}
          onChange={handleSearch}
          placeholder="Search abilities…"
          className="w-full pl-9 pr-4 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 bg-white"
        />
      </div>

      {filtered.length === 0 ? (
        <div className="py-10 text-center text-sm text-gray-400">
          {search ? 'No abilities match your search.' : 'No abilities are registered yet.'}
        </div>
      ) : (
        <>
          <div className="text-xs text-gray-400">
            Showing {(safePage - 1) * PER_PAGE + 1}–{Math.min(safePage * PER_PAGE, filtered.length)} of {filtered.length} abilities
            {search && ` matching "${search}"`}
          </div>

          <div className="bg-white rounded-xl border border-gray-200 overflow-hidden divide-y divide-gray-50">
            {pageItems.map((ability) => (
              <AbilityRow key={ability.name} ability={ability} />
            ))}
          </div>

          {totalPages > 1 && (
            <div className="flex items-center gap-2">
              <button
                onClick={() => setPage((p) => Math.max(1, p - 1))}
                disabled={safePage === 1}
                className="px-3 py-1.5 rounded text-sm font-medium bg-gray-100 hover:bg-gray-200 disabled:opacity-40 disabled:cursor-not-allowed transition-colors"
              >
                Previous
              </button>
              <span className="text-sm text-gray-500">Page {safePage} of {totalPages}</span>
              <button
                onClick={() => setPage((p) => Math.min(totalPages, p + 1))}
                disabled={safePage === totalPages}
                className="px-3 py-1.5 rounded text-sm font-medium bg-gray-100 hover:bg-gray-200 disabled:opacity-40 disabled:cursor-not-allowed transition-colors"
              >
                Next
              </button>
            </div>
          )}
        </>
      )}
    </div>
  )
}

// ─── Main Abilities page ──────────────────────────────────────────────────────

const TABS = [
  { id: 'packs', label: 'Ability Packs' },
  { id: 'abilities', label: 'Registered Abilities' },
]

export default function Abilities() {
  const [tab, setTab] = useState('packs')

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold text-gray-900">Abilities</h1>
        <p className="mt-1 text-gray-500">Manage ability packs and see all registered abilities on this site.</p>
      </div>

      <div className="border-b border-gray-200">
        <nav className="flex -mb-px gap-0">
          {TABS.map(({ id, label }) => (
            <button
              key={id}
              onClick={() => setTab(id)}
              className={[
                'px-5 py-2.5 text-sm font-medium border-b-2 transition-colors',
                tab === id
                  ? 'border-indigo-500 text-indigo-600'
                  : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300',
              ].join(' ')}
            >
              {label}
            </button>
          ))}
        </nav>
      </div>

      {tab === 'packs' ? <PacksTab /> : <AbilitiesTab />}
    </div>
  )
}
