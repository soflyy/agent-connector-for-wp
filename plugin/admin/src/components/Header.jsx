import React from 'react'
import { Plug } from 'lucide-react'

export default function Header({ page, onNavigate, status }) {
  const isActive = status.active
  const isEnabled = status.enabled

  return (
    <div className="bg-gray-900 text-white shadow-md sticky top-8 z-50">
      <div className="px-8">
        <div className="flex items-center justify-between h-16">
          {/* Brand */}
          <div className="flex items-center gap-3">
            <Plug className="w-6 h-6 text-indigo-400" />
            <span className="font-semibold text-base tracking-wide">Agent Connector</span>
          </div>

          {/* Nav */}
          <nav className="flex items-center gap-1">
            {[
              { id: 'connect', label: 'Connect' },
              { id: 'settings', label: 'Settings' },
              { id: 'abilities', label: 'Abilities' },
              { id: 'log', label: 'Log' },
            ].map(({ id, label }) => (
              <button
                key={id}
                onClick={() => onNavigate(id)}
                className={[
                  'px-5 py-2 rounded text-base font-medium transition-colors',
                  page === id
                    ? 'bg-white/10 text-white'
                    : 'text-gray-400 hover:text-white hover:bg-white/5',
                ].join(' ')}
              >
                {label}
              </button>
            ))}
          </nav>

          {/* Status badge */}
          <div className="flex items-center gap-2 text-sm">
            <span
              className={[
                'inline-flex items-center gap-2 px-3 py-1.5 rounded-full font-semibold',
                isActive
                  ? 'bg-green-500/20 text-green-400'
                  : isEnabled
                  ? 'bg-yellow-500/20 text-yellow-400'
                  : 'bg-gray-700 text-gray-400',
              ].join(' ')}
            >
              <span
                className={[
                  'w-2 h-2 rounded-full',
                  isActive ? 'bg-green-400' : isEnabled ? 'bg-yellow-400' : 'bg-gray-500',
                ].join(' ')}
              />
              {isActive ? 'Active' : isEnabled ? 'Enabled' : 'Disabled'}
            </span>
          </div>
        </div>
      </div>
    </div>
  )
}
