# Contributing

## Releases are automatic — your PR title sets the version

Every merge to `master` that touches `plugin/**` automatically cuts a new
versioned GitHub Release (see [`.github/workflows/auto-release.yml`](.github/workflows/auto-release.yml)).
The workflow reads the **merged PR's title** as a [Conventional Commit](https://www.conventionalcommits.org/)
and uses it to decide the version bump, then syncs the version into the plugin
header, tags it, builds the vendored zip, and publishes the release.

**So: title every PR like a conventional commit.** That title is the one that
matters — branch commit messages are not what drives the bump.

| PR title | Bump | Example |
| --- | --- | --- |
| breaking change (`type!:` prefix, or `BREAKING CHANGE` in the body) | **major** | `feat!: drop the enable constant` → `1.4.2 → 2.0.0` |
| `fix: …` | **patch** | `fix: stop double-escaping the login URL` → `1.4.2 → 1.4.3` |
| anything else — `feat:`, no prefix, `chore:`, `docs:`, `refactor:`, … | **minor** (treated as a feature) | `feat: add domain lock` / `Settings screen redesign` → `1.4.2 → 1.5.0` |

The default is **minor**: if a title isn't clearly a `fix:` or a breaking
change, it's treated as a feature. Reserve `fix:` for actual bug fixes so they
land as patch releases.

### Notes

- **Don't hand-edit the version** in `plugin/agent-connector-for-wp.php`
  (`Version:` header and `AGENT_CONNECTOR_FOR_WP_VERSION`). The release bot owns
  it and commits the bump with `[skip ci]`. Editing it yourself just creates
  conflicts.
- **Scope:** releases fire only when `plugin/**` changes. A merge touching only
  the tracker, abilities generator, ability packs, or root docs won't cut a
  release.
- **Need a specific bump or a re-run?** Run the **Auto release on merge**
  workflow manually (Actions → Run workflow) and pick the `bump` input, or push
  a `vX.Y.Z` tag yourself — [`release.yml`](.github/workflows/release.yml) builds
  and publishes any manually pushed tag.

## Local development

See [`README.md`](README.md) for the full setup. In short: `composer install`
in `plugin/`, symlink `plugin/` into a WordPress install with `bin/install.sh`,
then enable it from **Agent Connector for WP → Settings** in wp-admin.

> ⚠️ The plugin is usually symlinked into `wp-content/plugins`. Never run
> `wp plugin install <zip> --force` over that symlink — WP deletes the link
> target first, which wipes your working-tree source. To test a built zip,
> inspect it or extract it under a different plugin slug.
