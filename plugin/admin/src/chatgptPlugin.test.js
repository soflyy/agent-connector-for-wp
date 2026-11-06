import assert from 'node:assert/strict'
import test from 'node:test'
import { chatgptPluginFiles } from './chatgptPlugin.js'

const site = {
  serverName: 'lume-studio-wp',
  serverUrl: 'http://localhost:10040/wp-json/mcp/mcp-adapter-default-server',
  siteName: 'Lume Studio',
  username: 'test-user',
  prompts: ['Build a landing page and save it as a draft.'],
}

function readPackage(params) {
  const files = chatgptPluginFiles(params)
  assert.deepEqual(Object.keys(files).sort(), ['.codex-plugin/plugin.json', '.mcp.json'])
  const manifest = JSON.parse(files['.codex-plugin/plugin.json'])
  assert.equal(manifest.mcpServers, './.mcp.json')
  assert.equal(manifest.name, site.serverName)
  assert.equal(manifest.interface.displayName, 'Lume Studio (WordPress)')
  assert.equal(manifest.interface.logo, './assets/icon.png')
  return { manifest, server: JSON.parse(files['.mcp.json']).mcpServers[site.serverName] }
}

test('application-password export uses native packaging and preserves proxy credentials', () => {
  const password = 'test-only "quoted" \\ credential'
  const { manifest, server } = readPackage({ ...site, password })
  assert.equal(manifest.interface.defaultPrompt, site.prompts[0])
  assert.deepEqual(server, {
    type: 'stdio',
    command: 'npx',
    args: ['-y', '@automattic/mcp-wordpress-remote@latest'],
    env: {
      WP_API_URL: site.serverUrl,
      WP_API_USERNAME: site.username,
      WP_API_PASSWORD: password,
      OAUTH_ENABLED: 'false',
    },
  })
})

test('OAuth export remains native HTTP with no embedded credentials', () => {
  for (const password of [undefined, '']) {
    const serverUrl = 'https://example.com/wp-json/mcp/mcp-adapter-default-server'
    const { server } = readPackage({ ...site, serverUrl, password })
    assert.deepEqual(server, { type: 'http', url: serverUrl })
  }
})

test('native default prompt stays within the host limit for both sign-in methods', () => {
  for (const password of [undefined, 'test-only']) {
    const { manifest } = readPackage({ ...site, password, prompts: ['x'.repeat(200), 'Second prompt'] })
    assert.equal(manifest.interface.defaultPrompt, 'x'.repeat(128))
  }
})

test('missing prompts do not create an invalid native default prompt', () => {
  const { manifest } = readPackage({ ...site, prompts: [] })
  assert.equal(Object.hasOwn(manifest.interface, 'defaultPrompt'), false)
})
