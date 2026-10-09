import React from 'react'
import { createRoot } from 'react-dom/client'
import App from './App'
import { startEmbedBridge } from './embed'
import './index.css'

const el = document.getElementById('agent-connector-for-wp-app')
if (el) {
  createRoot(el).render(<App />)
  startEmbedBridge()
}
