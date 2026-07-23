import React, { useState, useEffect, useMemo } from 'react'
import { RefreshCw, ChevronDown, ChevronRight, Search } from 'lucide-react'
import { api } from '../api'

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

export default function Abilities() {
  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold text-gray-900">Abilities</h1>
        <p className="mt-1 text-gray-500">Every ability registered on this site.</p>
      </div>

      <AbilitiesTab />
    </div>
  )
}
