const cfg = window.AgentConnectorForWpAdmin || {}

const headers = () => ({
  'Content-Type': 'application/json',
  'X-WP-Nonce': cfg.restNonce || '',
})

const base = (cfg.restUrl || '/wp-json/agent-connector-for-wp/v1/').replace(/\/$/, '')

async function request(path, options = {}) {
  const res = await fetch(`${base}${path}`, {
    headers: headers(),
    ...options,
  })
  const data = await res.json()
  if (!res.ok) {
    throw new Error(data?.message || `Request failed (${res.status})`)
  }
  return data
}

// Replace with the actual getting started / demo video URL when ready.
export const DEMO_URL = ''

export const api = {
  getStatus: () => request('/status'),
  saveSettings: (body) => request('/settings', { method: 'POST', body: JSON.stringify(body) }),
  reconnect: () => request('/reconnect', { method: 'POST' }),
  generate: (params = {}) => request('/generate', { method: 'POST', body: JSON.stringify(params) }),
  getRegisteredAbilities: () => request('/registered-abilities'),
  getLogs: (params = {}) => {
    const qs = new URLSearchParams(
      Object.entries(params).filter(([, v]) => v !== undefined && v !== '' && v !== null)
    ).toString()
    return request(`/logs${qs ? '?' + qs : ''}`)
  },
  getLogEvent: (id) => request(`/logs/${id}`),
  clearLogs: () => request('/logs/clear', { method: 'POST' }),
  dismissGsBanner: () => request('/dismiss-gs-banner', { method: 'POST' }),
}

// wp_localize_script serializes PHP booleans as strings ("1" / ""), so coerce them.
const bool = (v, def = false) => v === undefined ? def : !!v

export const initial = {
  enabled: bool(cfg.enabled),
  active: bool(cfg.active),
  prodBlocked: bool(cfg.prodBlocked),
  mcpDebug: bool(cfg.mcpDebug),
  productionOverride: bool(cfg.productionOverride),
  isNonProd: bool(cfg.isNonProd, true),
  lockedHost: cfg.lockedHost ?? '',
  declaredHost: cfg.declaredHost ?? '',
  envType: cfg.envType ?? 'unknown',
  serverUrl: cfg.serverUrl ?? '',
  serverName: cfg.serverName ?? '',
  siteName: cfg.siteName ?? '',
  username: cfg.username ?? '',
  pwAvailable: bool(cfg.pwAvailable),
  showGsBanner: bool(cfg.showGsBanner),
}
