// The ability-pack directory REST routes (src/Rest/DirectoryController.php),
// called through core's wp.apiFetch, which adds the REST nonce.
const NS = '/universal-abilities/v1'

const post = (path, data) => window.wp.apiFetch({ path: NS + path, method: 'POST', data })

export const api = {
  getDirectory: () => window.wp.apiFetch({ path: `${NS}/directory` }),
  refreshDirectory: () => post('/directory/refresh'),
  installPack: (pack_slug) => post('/directory/install', { pack_slug }),
  activatePack: (pack_slug) => post('/directory/activate', { pack_slug }),
  deactivatePack: (pack_slug) => post('/directory/deactivate', { pack_slug }),
}
