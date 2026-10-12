// JS extension points, on WordPress's wp.hooks. Other plugins register filters
// from a script enqueued on the `agent_connector_for_wp_enqueue_admin_assets`
// action (see src/Admin/Extensions.php), which prints before this app.
//
//   agentConnector.abilities.tabs — tabs on the Abilities page. Each tab is
//     { id, label, order, render }, where render() returns a React element
//     (React is WordPress core's, shared with this app). Lower order first;
//     the first tab is selected by default.
import { useEffect, useState } from 'react'

export const ABILITIES_TABS = 'agentConnector.abilities.tabs'

const hooks = () => window.wp?.hooks

// The filtered value, kept current if a filter is added or removed after the
// first render (e.g. an extension script that loads late).
export function useFilteredValue(hookName, base) {
  const compute = () => {
    const value = hooks()?.applyFilters(hookName, base)
    return Array.isArray(value) ? value : base
  }
  const [value, setValue] = useState(compute)

  useEffect(() => {
    const h = hooks()
    if (!h) return undefined
    const ns = `agent-connector-for-wp/${hookName}`
    const onChange = (name) => {
      if (name === hookName) setValue(compute())
    }
    h.addAction('hookAdded', ns, onChange)
    h.addAction('hookRemoved', ns, onChange)
    return () => {
      h.removeAction('hookAdded', ns)
      h.removeAction('hookRemoved', ns)
    }
  }, [hookName])

  return value
}
