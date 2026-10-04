// ─── Builder kit skills ──────────────────────────────────────────────────────
//
// The builder-kit plugin from the soflyy/skills marketplace
// (https://github.com/soflyy/skills) teaches the agent to build sites and
// WooCommerce stores with Oxygen 6 or Breakdance. Plugin formats can't point
// at a marketplace, so on sites running either builder the generated plugins
// carry a copy of its skills instead, fetched from GitHub when the file is
// built so it's always the current version.

const REPO = 'soflyy/skills'
const BRANCH = 'main'
const SKILLS_DIR = 'plugins/builder-kit/skills/'

// The skill files by path under skills/ (e.g. 'building-sites/SKILL.md').
// Rejects if GitHub can't be reached; callers build the plugin without them.
export async function fetchBuilderKitSkills() {
  const res = await fetch(`https://api.github.com/repos/${REPO}/git/trees/${BRANCH}?recursive=1`)
  if (!res.ok) throw new Error(`Could not list ${REPO} (${res.status})`)
  const { tree = [] } = await res.json()
  const paths = tree
    .filter((entry) => entry.type === 'blob' && entry.path.startsWith(SKILLS_DIR))
    .map((entry) => entry.path)
  if (!paths.length) throw new Error(`No skills found in ${REPO}`)

  const files = await Promise.all(paths.map(async (path) => {
    const file = await fetch(`https://raw.githubusercontent.com/${REPO}/${BRANCH}/${path}`)
    if (!file.ok) throw new Error(`Could not fetch ${path} (${file.status})`)
    return [path.slice(SKILLS_DIR.length), await file.text()]
  }))
  return Object.fromEntries(files)
}
