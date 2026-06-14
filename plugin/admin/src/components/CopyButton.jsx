import React, { useState } from 'react'
import { Copy, Check } from 'lucide-react'

export default function CopyButton({ text, className = '' }) {
  const [copied, setCopied] = useState(false)

  async function handleCopy() {
    try {
      await navigator.clipboard.writeText(text)
      setCopied(true)
      setTimeout(() => setCopied(false), 2000)
    } catch {
      // Fallback for older browsers
      const el = document.createElement('textarea')
      el.value = text
      document.body.appendChild(el)
      el.select()
      document.execCommand('copy')
      document.body.removeChild(el)
      setCopied(true)
      setTimeout(() => setCopied(false), 2000)
    }
  }

  return (
    <button
      onClick={handleCopy}
      className={[
        'inline-flex items-center gap-1.5 px-3 py-1.5 rounded text-xs font-medium transition-all',
        copied
          ? 'bg-green-500/20 text-green-400 border border-green-500/30'
          : 'bg-white/10 text-gray-300 border border-white/10 hover:bg-white/20 hover:text-white',
        className,
      ].join(' ')}
    >
      {copied ? <Check className="w-3.5 h-3.5" /> : <Copy className="w-3.5 h-3.5" />}
      {copied ? 'Copied!' : 'Copy'}
    </button>
  )
}
