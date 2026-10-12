# Notes for agents working in this repo

Read [`CONTRIBUTING.md`](CONTRIBUTING.md) before opening a PR.

## Monorepo layout

| Path | What it is | Docs |
| --- | --- | --- |
| `plugin/` | The main WordPress plugin (PHP, Composer). Runs the MCP server; ships no abilities of its own. | [`plugin/README.md`](plugin/README.md) |
| `universal-abilities-plugin/` | The Default Abilities companion plugin: the powerful built-in abilities (shell, PHP eval, filesystem, WP-CLI, admin login), plus the sandbox loader, the ability-pack directory (a tab on the main plugin's Abilities page) and GitHub updates for itself and packs. GitHub-only. | [`universal-abilities-plugin/README.md`](universal-abilities-plugin/README.md) |
| `abilities-generator/` | The `wp-mcp-generator` skill + scripts. | [`abilities-generator/README.md`](abilities-generator/README.md) |
| `ability-packs/` | Generated ability-pack plugins (the generator's output). | — |
| `bin/` | Dev scripts (`install.sh` symlinks `plugin/` + `universal-abilities-plugin/` into a WP install). | — |

Each subproject has its own README/CONTRIBUTING. The PR-title release automation
below applies to both `plugin/` and `universal-abilities-plugin/` — the latter via
its own workflow (`.github/workflows/default-abilities-release.yml`), which uses
the same commit-back versioning (PR title → bump → tag `universal-abilities-plugin-vX.Y.Z`
→ versioned release, never clobbered) and refreshes a stable
`universal-abilities-index` manifest that Universal Abilities' updater (and
Breakdance's one-click installer) reads. Ability packs (`ability-packs/`) use
stamp-only versioning instead (see `ability-packs-release.yml`); Universal
Abilities updates them.

## The main plugin is on wordpress.org

`plugin/` must stay within the wordpress.org plugin guidelines: no installing or
updating plugins from anywhere but wordpress.org, no bundled copies of
libraries WordPress ships (React comes from core), no raw inline
`<script>`/`<style>`, no outgoing requests without disclosure in `readme.txt`.
Anything that needs to break those rules belongs in Universal Abilities, in
another plugin through the extension points (`src/Admin/Extensions.php`), or —
only for compatibility with older installs — in `plugin/legacy/`, which the
wordpress.org build leaves out (`plugin/.distignore`, `bin/build-plugin-zip.sh`).

## PR / commit titles drive releases

Merging a PR to `master` (when it touches `plugin/**`) automatically publishes a
new GitHub Release, and the **PR title** decides the version bump (Conventional
Commits):

- breaking change (`type!:`, or `BREAKING CHANGE` in the body) → **major**
- `fix: …` → **patch**
- anything else (`feat:`, unprefixed, `chore:`, `docs:`, …) → **minor** (the default)

Use `fix:` only for real bug fixes; everything else becomes a minor/feature
release. Title PRs accordingly.

## Don't touch the version by hand

The release bot owns the version in `plugin/agent-connector-for-wp.php`
(`Version:` + `AGENT_CONNECTOR_FOR_WP_VERSION`). Leave it alone — it bumps on
release with `[skip ci]`.

## Don't `wp plugin install --force` over the symlink

The live plugin is a symlink to `plugin/`; a forced install deletes the link
target (your working-tree source). To test a built zip, inspect it or extract it
under a different slug.
