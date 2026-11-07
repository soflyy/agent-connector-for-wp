# Notes for agents working in this repo

Read [`CONTRIBUTING.md`](CONTRIBUTING.md) before opening a PR.

## Monorepo layout

| Path | What it is | Docs |
| --- | --- | --- |
| `plugin/` | The WordPress plugin (PHP, Composer). Runs the MCP server, plus the built-in Universal Abilities (off by default). | [`plugin/README.md`](plugin/README.md) |
| `bin/` | Dev scripts (`install.sh` symlinks `plugin/` into a WP install; `build-plugin-zip.sh` builds the GitHub and wordpress.org zips). | — |

## GitHub-only code

The wordpress.org build (`bin/build-plugin-zip.sh org`) strips what wordpress.org
doesn't allow. Anything that shells out, evaluates PHP, runs agent-written PHP,
or updates from GitHub must be GitHub-only:

- put whole classes under `plugin/src/GitHub/` (namespace `AgentConnectorForWp\GitHub\`);
- wrap the code that wires them in (or any other GitHub-only lines) in
  `// @github-build-start` … `// @github-build-end` markers.

The org build fails if anything GitHub-only is still referenced.

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
