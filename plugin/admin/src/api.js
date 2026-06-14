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

export const api = {
  getStatus: () => request('/status'),
  saveSettings: (body) => request('/settings', { method: 'POST', body: JSON.stringify(body) }),
  reconnect: () => request('/reconnect', { method: 'POST' }),
  generate: () => request('/generate', { method: 'POST' }),
  getDirectory: () => request('/directory'),
  refreshDirectory: () => request('/directory/refresh', { method: 'POST' }),
  installPack: (pack_slug) => request('/directory/install', { method: 'POST', body: JSON.stringify({ pack_slug }) }),
  activatePack: (pack_slug) => request('/directory/activate', { method: 'POST', body: JSON.stringify({ pack_slug }) }),
  deactivatePack: (pack_slug) => request('/directory/deactivate', { method: 'POST', body: JSON.stringify({ pack_slug }) }),
  getRegisteredAbilities: () => request('/registered-abilities'),
}

export const initial = {
  enabled: cfg.enabled ?? false,
  active: cfg.active ?? false,
  prodBlocked: cfg.prodBlocked ?? false,
  mcpDebug: cfg.mcpDebug ?? false,
  productionOverride: cfg.productionOverride ?? false,
  isNonProd: cfg.isNonProd ?? true,
  lockedHost: cfg.lockedHost ?? '',
  declaredHost: cfg.declaredHost ?? '',
  envType: cfg.envType ?? 'unknown',
  serverUrl: cfg.serverUrl ?? '',
  username: cfg.username ?? '',
  pwAvailable: cfg.pwAvailable ?? false,
}
