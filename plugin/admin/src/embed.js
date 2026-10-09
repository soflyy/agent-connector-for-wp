// Embed mode: the Connect wizard running inside another plugin's iframe
// (admin.php?page=agent-connector-for-wp&embed=1). The host page listens for
// these events with window.addEventListener('message', ...); each message is
// { source: 'agent-connector-for-wp', type, ...data }. Events:
//   ready                   app mounted
//   resize { height }       content height changed, so the host can size the iframe
//   step-changed { step }   'welcome' | 'pick' | 'generate'
//   agent-selected { agent }
//   method-changed { method }  'oauth' | 'password'
//   credentials-generated { method }
const cfg = window.AgentConnectorForWpAdmin || {}

export const EMBED = !!cfg.embed && window.parent !== window

export function notifyParent(type, data = {}) {
  if (!EMBED) return
  // Embed mode is same-origin only (wp-admin sends X-Frame-Options: SAMEORIGIN),
  // so never post to any other origin.
  window.parent.postMessage({ source: 'agent-connector-for-wp', type, ...data }, window.location.origin)
}

export function startEmbedBridge() {
  if (!EMBED) return
  const el = document.getElementById('agent-connector-for-wp-app')
  let last = 0
  const report = () => {
    const height = Math.ceil(el?.getBoundingClientRect().height || 0)
    if (height && height !== last) {
      last = height
      notifyParent('resize', { height })
    }
  }
  if (el) new ResizeObserver(report).observe(el)
  report()
  notifyParent('ready')
}
