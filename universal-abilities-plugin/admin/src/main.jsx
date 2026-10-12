// Adds the "Ability Packs" tab to Agent Connector's Abilities page through its
// agentConnector.abilities.tabs filter. Enqueued by src/Admin/AbilityPacksTab.php
// before Agent Connector's app, so the filter is in place for its first render.
import React from 'react'
import PacksTab from './PacksTab'
import './index.css'

window.wp?.hooks?.addFilter(
  'agentConnector.abilities.tabs',
  'universal-abilities/ability-packs',
  (tabs) => [
    ...tabs,
    { id: 'packs', label: 'Ability Packs', order: 10, render: () => <PacksTab /> },
  ],
)
