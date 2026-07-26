import React, { useState } from 'react'
import { Play, X } from 'lucide-react'
import Header from './components/Header'
import Connect from './pages/Connect'
import Connections from './pages/Connections'
import Settings from './pages/Settings'
import Abilities from './pages/Abilities'
import Log from './pages/Log'
import { api, initial, DEMO_URL } from './api'

function getPage() {
  const hash = window.location.hash.replace('#/', '')
  if (hash === 'connections') return 'connections'
  if (hash === 'settings') return 'settings'
  if (hash === 'abilities') return 'abilities'
  if (hash === 'log') return 'log'
  return 'connect'
}

function GettingStartedBanner({ onDismiss }) {
  if (!DEMO_URL) return null
  return (
    <div className="bg-indigo-600 text-white px-8 py-3 flex items-center gap-3 text-sm">
      <Play className="w-4 h-4 flex-shrink-0 text-indigo-200" />
      <span className="font-medium">New to Agent Connector?</span>
      <span className="text-indigo-200 hidden sm:inline">Watch the 2-minute walkthrough to get started.</span>
      <a
        href={DEMO_URL}
        target="_blank"
        rel="noreferrer"
        className="flex items-center gap-1.5 px-3 py-1 bg-white text-indigo-700 font-semibold rounded-lg hover:bg-indigo-50 transition-colors flex-shrink-0 text-sm"
      >
        Watch now →
      </a>
      <button
        onClick={onDismiss}
        aria-label="Dismiss"
        className="ml-auto p-1 text-indigo-300 hover:text-white transition-colors flex-shrink-0"
      >
        <X className="w-4 h-4" />
      </button>
    </div>
  )
}

export default function App() {
  const [page, setPage] = useState(getPage)
  const [status, setStatus] = useState(initial)
  const [showBanner, setShowBanner] = useState(initial.showGsBanner)

  function navigate(p) {
    window.location.hash = `/${p}`
    setPage(p)
  }

  function dismissBanner() {
    setShowBanner(false)
    api.dismissGsBanner().catch(() => {})
  }

  return (
    <div className="bg-gray-50 font-sans" style={{ minHeight: 'calc(100vh - 32px)' }}>
      {showBanner && <GettingStartedBanner onDismiss={dismissBanner} />}
      <Header page={page} onNavigate={navigate} status={status} />
      <div className="px-8 py-10">
        {page === 'connect' && (
          <Connect status={status} onStatusChange={setStatus} />
        )}
        {page === 'connections' && (
          <Connections />
        )}
        {page === 'settings' && (
          <Settings status={status} onStatusChange={setStatus} />
        )}
        {page === 'abilities' && (
          <Abilities />
        )}
        {page === 'log' && (
          <Log />
        )}
      </div>
    </div>
  )
}
