import React, { useState, useEffect, useCallback } from 'react'
import { AlertTriangle, RefreshCw, Trash2, ChevronLeft } from 'lucide-react'
import { api } from '../api'

// ─── Utilities ────────────────────────────────────────────────────────────────

function formatDuration(ms) {
  if (ms === null || ms === undefined) return '—'
  if (ms < 1) return ms.toFixed(2) + ' ms'
  if (ms < 1000) return ms.toFixed(1) + ' ms'
  return (ms / 1000).toFixed(2) + ' s'
}

function formatTime(utcStr) {
  if (!utcStr) return '—'
  try {
    return new Date(utcStr.replace(' ', 'T') + 'Z').toLocaleString()
  } catch {
    return utcStr
  }
}

function StatusBadge({ status }) {
  if (!status) return <span className="text-gray-400">—</span>
  const cls = status === 'success'
    ? 'bg-green-500/20 text-green-600'
    : status === 'error'
    ? 'bg-red-500/20 text-red-600'
    : 'bg-gray-200 text-gray-600'
  return (
    <span className={`inline-flex px-2.5 py-1 rounded-full text-sm font-medium ${cls}`}>
      {status}
    </span>
  )
}

// ─── Detail view ──────────────────────────────────────────────────────────────

function JsonBlock({ title, body }) {
  const pretty = (() => {
    if (!body) return null
    try { return JSON.stringify(JSON.parse(body), null, 2) } catch { return body }
  })()
  return (
    <div className="space-y-2">
      <h3 className="text-base font-semibold text-gray-700">{title}</h3>
      {pretty
        ? <pre className="bg-gray-950 text-gray-200 text-sm p-4 rounded-xl overflow-auto max-h-64 leading-relaxed whitespace-pre-wrap break-all">{pretty}</pre>
        : <p className="text-base text-gray-400 italic">Not captured.</p>
      }
    </div>
  )
}

function CodeOrDash({ value }) {
  if (!value) return <span className="text-gray-400">—</span>
  return <code className="text-sm bg-gray-100 px-1.5 py-0.5 rounded break-all">{value}</code>
}

function EventDetail({ eventId, onBack }) {
  const [data, setData] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)

  useEffect(() => {
    setLoading(true)
    setError(null)
    api.getLogEvent(eventId)
      .then(setData)
      .catch(e => setError(e.message))
      .finally(() => setLoading(false))
  }, [eventId])

  return (
    <div className="space-y-6">
      <button
        onClick={onBack}
        className="flex items-center gap-2 text-base text-gray-500 hover:text-gray-700 transition-colors"
      >
        <ChevronLeft className="w-5 h-5" />
        Back to events
      </button>

      {loading && (
        <div className="flex items-center gap-2 text-base text-gray-400">
          <RefreshCw className="w-5 h-5 animate-spin" /> Loading…
        </div>
      )}
      {error && (
        <div className="p-4 bg-red-50 border border-red-200 rounded-xl text-base text-red-700">{error}</div>
      )}

      {data && (
        <>
          <h2 className="text-xl font-semibold text-gray-900">Event #{data.id}</h2>

          <div className="bg-white rounded-xl border border-gray-200 overflow-hidden">
            <table className="w-full text-base">
              <tbody>
                {[
                  ['Time', formatTime(data.created_at), false],
                  ['Event', data.event, true],
                  ['Method', data.method, true],
                  ['Tool', data.tool_name, true],
                  ['Prompt', data.prompt_name, true],
                  ['Status', null, false, <StatusBadge key="s" status={data.status} />],
                  ['Duration', formatDuration(data.duration_ms), false],
                  ['User', data.user_login, false],
                  ['Server ID', data.server_id, true],
                  ['Transport', data.transport, true],
                  ['Resource URI', data.resource_uri, true],
                  ['Session ID', data.session_id, true],
                  ['Request ID', data.request_id, true],
                  ['Error code', data.error_code, true],
                  ['Error category', data.error_category, true],
                  ['Failure reason', data.failure_reason, false],
                ].map(([label, value, mono, custom], i) => (
                  <tr key={label} className={i % 2 === 0 ? 'bg-gray-50' : 'bg-white'}>
                    <td className="px-4 py-3 font-medium text-gray-500 w-48 text-base">{label}</td>
                    <td className="px-4 py-3 text-gray-900">
                      {custom ?? (mono
                        ? <CodeOrDash value={value} />
                        : (value || <span className="text-gray-400">—</span>)
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          <JsonBlock title="Input (JSON-RPC request)" body={data.input_body} />
          <JsonBlock title="Output (JSON-RPC response)" body={data.output_body} />

          {data.tags && Object.keys(data.tags).length > 0 && (
            <div className="space-y-2">
              <h3 className="text-base font-semibold text-gray-700">Tags</h3>
              <div className="bg-white rounded-xl border border-gray-200 overflow-hidden">
                <table className="w-full text-sm">
                  <tbody className="divide-y divide-gray-100">
                    {Object.entries(data.tags).map(([k, v]) => (
                      <tr key={k}>
                        <td className="px-4 py-2.5 font-mono text-sm text-gray-500 w-48 bg-gray-50">{k}</td>
                        <td className="px-4 py-2.5 font-mono text-sm text-gray-900 break-all">
                          {typeof v === 'string' ? v : JSON.stringify(v)}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>
          )}
        </>
      )}
    </div>
  )
}

// ─── List view ────────────────────────────────────────────────────────────────

export default function Log() {
  const [data, setData] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)
  const [page, setPage] = useState(1)
  const [eventFilter, setEventFilter] = useState('')
  const [statusFilter, setStatusFilter] = useState('')
  const [detail, setDetail] = useState(null)
  const [clearing, setClearing] = useState(false)

  const load = useCallback(() => {
    setLoading(true)
    setError(null)
    api.getLogs({ page, event_filter: eventFilter, status_filter: statusFilter })
      .then(setData)
      .catch(e => setError(e.message))
      .finally(() => setLoading(false))
  }, [page, eventFilter, statusFilter])

  useEffect(() => { load() }, [load])

  async function handleClear() {
    if (!confirm('Clear all MCP events? This cannot be undone.')) return
    setClearing(true)
    try {
      await api.clearLogs()
      setPage(1)
      load()
    } catch (e) {
      setError(e.message)
    } finally {
      setClearing(false)
    }
  }

  if (detail !== null) {
    return <EventDetail eventId={detail} onBack={() => setDetail(null)} />
  }

  const totalPages = data ? Math.ceil(data.total / data.per_page) : 1

  return (
    <div className="space-y-4">
      <h1 className="text-2xl font-bold text-gray-900">MCP Events</h1>

      {data && !data.debug_enabled && (
        <div className="flex items-start gap-2.5 p-4 bg-amber-50 border border-amber-200 rounded-xl text-base text-amber-700">
          <AlertTriangle className="w-5 h-5 mt-0.5 flex-shrink-0" />
          <span>
            Event logging is off — enable <strong>Debug</strong> in{' '}
            <button
              className="underline font-medium"
              onClick={() => { window.location.hash = '/settings' }}
            >
              Settings
            </button>
            {' '}to start recording.
          </span>
        </div>
      )}

      {/* Toolbar */}
      <div className="flex items-center gap-2 flex-wrap">
        <select
          value={eventFilter}
          onChange={e => { setEventFilter(e.target.value); setPage(1) }}
          className="text-base border border-gray-300 rounded-lg px-4 py-2 bg-white text-gray-700 focus:outline-none focus:ring-1 focus:ring-indigo-400"
        >
          <option value="">All events (excl. lifecycle)</option>
          {data?.distinct_events?.map(ev => <option key={ev} value={ev}>{ev}</option>)}
        </select>
        <select
          value={statusFilter}
          onChange={e => { setStatusFilter(e.target.value); setPage(1) }}
          className="text-base border border-gray-300 rounded-lg px-4 py-2 bg-white text-gray-700 focus:outline-none focus:ring-1 focus:ring-indigo-400"
        >
          <option value="">All statuses</option>
          {data?.distinct_statuses?.map(s => <option key={s} value={s}>{s}</option>)}
        </select>
        <button
          onClick={load}
          disabled={loading}
          className="flex items-center gap-2 px-4 py-2 text-base text-gray-600 hover:text-gray-800 border border-gray-300 rounded-lg bg-white hover:bg-gray-50 transition-colors disabled:opacity-50"
        >
          <RefreshCw className={`w-4 h-4 ${loading ? 'animate-spin' : ''}`} />
          Refresh
        </button>
        <div className="ml-auto flex items-center gap-3">
          {data && (
            <span className="text-base text-gray-500">
              {data.total} event{data.total !== 1 ? 's' : ''}
            </span>
          )}
          <button
            onClick={handleClear}
            disabled={clearing || !data?.total}
            className="flex items-center gap-2 px-4 py-2 text-base text-red-600 hover:text-red-800 border border-red-200 rounded-lg bg-white hover:bg-red-50 transition-colors disabled:opacity-40"
          >
            <Trash2 className="w-4 h-4" />
            Clear log
          </button>
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
              {['Time', 'Event', 'Method', 'Tool / Prompt', 'Status', 'Duration', 'User', ''].map(h => (
                <th key={h} className="px-4 py-3 text-left text-sm font-semibold text-gray-500 uppercase tracking-wider last:text-right">
                  {h}
                </th>
              ))}
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-100">
            {loading && !data && (
              <tr>
                <td colSpan={8} className="px-4 py-8 text-center">
                  <RefreshCw className="w-5 h-5 animate-spin text-gray-400 mx-auto" />
                </td>
              </tr>
            )}
            {!loading && data?.rows?.length === 0 && (
              <tr>
                <td colSpan={8} className="px-4 py-8 text-center text-base text-gray-400">
                  No MCP events recorded yet.
                </td>
              </tr>
            )}
            {data?.rows?.map(row => (
              <tr key={row.id} className="hover:bg-gray-50 transition-colors">
                <td className="px-4 py-3 text-gray-500 text-sm whitespace-nowrap">{formatTime(row.created_at)}</td>
                <td className="px-4 py-3 max-w-[160px]">
                  <code className="text-sm text-gray-700 break-all">{row.event || '—'}</code>
                </td>
                <td className="px-4 py-3">
                  <code className="text-sm text-gray-500">{row.method || '—'}</code>
                </td>
                <td className="px-4 py-3">
                  {row.tool_name || row.prompt_name
                    ? <code className="text-sm text-gray-700">{row.tool_name || row.prompt_name}</code>
                    : <span className="text-gray-400">—</span>}
                </td>
                <td className="px-4 py-3"><StatusBadge status={row.status} /></td>
                <td className="px-4 py-3 text-gray-500 text-sm whitespace-nowrap">{formatDuration(row.duration_ms)}</td>
                <td className="px-4 py-3 text-gray-500 text-sm">{row.user_login || '—'}</td>
                <td className="px-4 py-3 text-right">
                  <button
                    onClick={() => setDetail(row.id)}
                    className="text-sm text-indigo-600 hover:text-indigo-800 font-medium"
                  >
                    View
                  </button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {/* Pagination */}
      {totalPages > 1 && (
        <div className="flex items-center justify-between text-base">
          <button
            onClick={() => setPage(p => Math.max(1, p - 1))}
            disabled={page === 1}
            className="px-4 py-2 border border-gray-300 rounded-lg bg-white text-gray-600 hover:bg-gray-50 disabled:opacity-40 transition-colors"
          >
            Previous
          </button>
          <span className="text-gray-500">Page {page} of {totalPages}</span>
          <button
            onClick={() => setPage(p => p + 1)}
            disabled={page >= totalPages}
            className="px-4 py-2 border border-gray-300 rounded-lg bg-white text-gray-600 hover:bg-gray-50 disabled:opacity-40 transition-colors"
          >
            Next
          </button>
        </div>
      )}
    </div>
  )
}
