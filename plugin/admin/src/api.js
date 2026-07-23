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

// Where the optional Universal Abilities companion plugin lives.
export const UNIVERSAL_ABILITIES_URL = 'https://wpagentconnector.com/universal-abilities'

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

// The REST /status payload uses snake_case; app state uses camelCase. Merge
// responses through this so saved changes actually reach the state the
// components read.
export const normalizeStatus = (s = {}) => ({
  enabled: bool(s.enabled),
  active: bool(s.active),
  prodBlocked: bool(s.prod_blocked),
  mcpDebug: bool(s.mcp_debug),
  blockProduction: bool(s.block_production),
  domainLockEnabled: bool(s.domain_lock_enabled),
  hideProdWarning: bool(s.hide_production_warning),
  isNonProd: bool(s.is_non_prod, true),
  lockedHost: s.locked_host ?? '',
  declaredHost: s.declared_host ?? '',
  envType: s.env_type ?? 'unknown',
  serverUrl: s.server_url ?? '',
  username: s.username ?? '',
  pwAvailable: bool(s.pw_available),
  uapActive: bool(s.uap_active),
})

export const initial = {
  enabled: bool(cfg.enabled),
  active: bool(cfg.active),
  prodBlocked: bool(cfg.prodBlocked),
  mcpDebug: bool(cfg.mcpDebug),
  blockProduction: bool(cfg.blockProduction),
  domainLockEnabled: bool(cfg.domainLockEnabled),
  hideProdWarning: bool(cfg.hideProdWarning),
  isNonProd: bool(cfg.isNonProd, true),
  lockedHost: cfg.lockedHost ?? '',
  declaredHost: cfg.declaredHost ?? '',
  envType: cfg.envType ?? 'unknown',
  serverUrl: cfg.serverUrl ?? '',
  serverName: cfg.serverName ?? '',
  siteName: cfg.siteName ?? '',
  username: cfg.username ?? '',
  pwAvailable: bool(cfg.pwAvailable),
  uapActive: bool(cfg.uapActive),
  showGsBanner: bool(cfg.showGsBanner),
}
