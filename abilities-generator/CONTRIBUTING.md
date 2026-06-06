# Contributing

Two kinds of work live here: improving the **generator** (`skills/wp-mcp-generator/`, in this
`abilities-generator/` subproject) and adding **generated packs** (committed to the monorepo's
top-level [`../ability-packs/`](../ability-packs/)). Both happen inside a sandbox from
[create-wp-local-dev-agent-sandbox](https://github.com/soflyy/create-wp-local-dev-agent-sandbox).

## Setup

```bash
git clone git@github.com:soflyy/agent-connector-for-wp.git
cd agent-connector-for-wp/abilities-generator
./scripts/install-skill.sh        # symlinks the skill into ~/.claude/skills/
```

The skill is **symlinked**, so editing it in this repo updates it live for the agent — make
changes, try them, commit.

## Improving the generator

The skill is deliberately **principle-driven, not a per-plugin recipe** — it states the
standard and the principles and trusts the agent to derive tactics by reading the target
plugin's code and exercising it. Keep it that way:

- **Add principles and judgment, not plugin-specific steps.** If you find yourself writing
  "for Elementor, do X," stop — generalize it so any agent would *arrive* at X from its own
  exploration.
- **Two invariants** the skill must keep enforcing: the **code is the source of truth**
  (read it all; the UI only confirms and surfaces gaps), and **agent-ergonomics outranks
  code-reuse** (minimal-delta verbs; reimplement logic when ergonomics demand it).
- **The standard of done stays behavioral:** the agent can do anything a human admin could,
  through the abilities, verified from independent vantage points (UI + DB + REST) — not
  "the endpoints are wrapped."
- Edit the `scaffold/` when the *shape* of a pack should change for everyone; keep
  `bootstrap.php` generic (no per-plugin logic).

## Adding a generated pack

1. In the sandbox, have the agent run the `wp-mcp-generator` skill against a plugin. It
   builds the pack under `wp/wp-content/plugins/unofficial-abilities-for-<slug>/`.
2. A pack is **not done** until its `coverage/coverage-report.md` accounts for every surface
   and task (covered or explicitly excluded-with-reason) and every ability passes acceptance.
3. Save it into the repo and commit:
   ```bash
   ./scripts/save-pack.sh <slug>
   git add ../ability-packs/unofficial-abilities-for-<slug> && git commit
   ```

## Pack conventions (enforced by Agent Connector)

- Folder + main file: `unofficial-abilities-for-<slug>`.
- Headers: `Requires Plugins: agent-connector-for-wp`, `Agent Connector: Ability Pack`,
  `Agent Connector Target: <target-plugin-file>`.
- Namespace abilities under your own vendor prefix (`<slug>/...`) — never
  `agent-connector-for-wp/...`.
- Write **no** auth/permission/domain-lock/audit code — Agent Connector injects it.
- Don't vendor `mcp-adapter` or the Abilities API — the host plugin provides them.

Full registration contract:
`wp/wp-content/plugins/agent-connector-for-wp/docs/registering-abilities.md`.

## Before committing

- `php -l` every PHP file in a pack; `bash -n` any shell tooling.
- Don't commit `node_modules/` or `vendor/` (already gitignored).
- Descriptions and input schemas are the product — make them precise.
