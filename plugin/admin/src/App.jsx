import React, { useState } from 'react'
import Header from './components/Header'
import Connect from './pages/Connect'
import Settings from './pages/Settings'
import Abilities from './pages/Abilities'
import Log from './pages/Log'
import { initial } from './api'

function getPage() {
  const hash = window.location.hash.replace('#/', '')
  if (hash === 'settings') return 'settings'
  if (hash === 'abilities') return 'abilities'
  if (hash === 'log') return 'log'
  return 'connect'
}

export default function App() {
  const [page, setPage] = useState(getPage)
  const [status, setStatus] = useState(initial)

  function navigate(p) {
    window.location.hash = `/${p}`
    setPage(p)
  }

  return (
    <div className="bg-gray-50 font-sans" style={{ minHeight: 'calc(100vh - 32px)' }}>
      <Header page={page} onNavigate={navigate} status={status} />
      <div className="px-8 py-10">
        {page === 'connect' && (
          <Connect status={status} onStatusChange={setStatus} />
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
