# Abilities Generator

Turn **any WordPress plugin** into a full-coverage MCP surface an agent can drive.

This is the `abilities-generator/` subproject of the
[Agent Connector for WP](https://github.com/soflyy/agent-connector-for-wp) monorepo. It
holds the generator itself; the packs it produces are committed to the monorepo's top-level
[`ability-packs/`](../ability-packs/) directory (a sibling of this folder), **not** inside
here.

- **`skills/wp-mcp-generator/`** — the generator. A Claude Code skill (a principle-driven
  playbook + a ready-to-fill ability-pack scaffold) that, given a plugin `.zip` or `.org`
  slug, installs it, understands it (its code is the source of truth; exercising it confirms
  and surfaces gaps), designs an **agent-ergonomic** ability surface, and ships a companion
  *ability pack* plugin — verified to actually work.
- **`../ability-packs/`** — the outputs (monorepo root). One generated ability-pack plugin
  per target, e.g. `agent-connector-for-wp-ability-pack-contact-form-7/`.

The generated packs register their abilities via the **WP Abilities API**, which
[Agent Connector for WP](../plugin/) (via `mcp-adapter`) automatically exposes as MCP
endpoints — with auth, domain-lock, and audit injected for you.

## How it's meant to be used

1. **Spin up a sandbox** with
   [create-wp-local-dev-agent-sandbox](https://github.com/soflyy/create-wp-local-dev-agent-sandbox)
   (gives you a WordPress install, WP-CLI, Playwright MCP, and Agent Connector preinstalled).
2. **Clone the monorepo and install the skill** into the agent's skills dir:
   ```bash
   git clone git@github.com:soflyy/agent-connector-for-wp.git
   ./agent-connector-for-wp/abilities-generator/scripts/install-skill.sh
   ```
   This symlinks `skills/wp-mcp-generator` into `~/.claude/skills/`, so editing the skill in
   the repo updates it live (improve it as you go, then commit).
3. **Generate a pack.** Ask the agent to generate MCP for a plugin; it invokes the
   `wp-mcp-generator` skill, which builds the pack under `wp/wp-content/plugins/`.
4. **Save the pack back into the repo:**
   ```bash
   ./abilities-generator/scripts/save-pack.sh contact-form-7
   ```
   then commit it under the monorepo's [`ability-packs/`](../ability-packs/).

## For agents

If you're an agent working in this repo's sandbox, here's the whole loop:

1. **Make the skill available.** If `wp-mcp-generator` isn't already in your available
   skills, run `./scripts/install-skill.sh` from this directory — it symlinks the skill into
   `~/.claude/skills/`. Once present, the skill auto-advertises via its description; you don't
   need to read it to know it exists. (Ideally the sandbox provisioning already ran this for
   you.)
2. **Generate a pack.** When asked to build MCP / abilities for a WordPress plugin, invoke the
   `wp-mcp-generator` skill and follow `skills/wp-mcp-generator/SKILL.md`. It installs the
   target, understands it (code first, then by exercising it), designs an agent-ergonomic
   ability surface, and builds the pack under
   `wp/wp-content/plugins/agent-connector-for-wp-ability-pack-<slug>/`.
3. **Don't stop early.** A pack is done only when its `coverage/coverage-report.md` accounts
   for every surface and task and every ability passes acceptance — verified through the real
   MCP surface (`mcp-adapter-execute-ability`) and confirmed from independent vantage points
   (Playwright UI, the database, REST). "The endpoints are wrapped" is not done.
4. **Harvest it into the repo.** `./scripts/save-pack.sh <slug>` copies the finished pack from
   the WP install into the monorepo's `ability-packs/`; then commit it.

If you're *improving the generator itself*, see `CONTRIBUTING.md` — keep the skill
principle-driven (no per-plugin recipes), keep the two invariants (code is the source of
truth; agent-ergonomics over code-reuse), and keep the standard of done behavioral.

## What "done" means

The generator's standard of acceptance is not "the endpoints are wrapped" — it's: *the agent
can accomplish anything a human admin could with the plugin, through the abilities, verified*
from independent vantage points (UI via Playwright, the database, REST). See
[`skills/wp-mcp-generator/SKILL.md`](skills/wp-mcp-generator/SKILL.md) for the full playbook
and the design principles (agent-ergonomics over code-reuse; the code is the source of truth;
a closed coverage loop with objective termination conditions).

## Layout

```
abilities-generator/
  skills/wp-mcp-generator/   the generator skill (SKILL.md) + scaffold/ template
  scripts/install-skill.sh   link the skill into ~/.claude/skills/
  scripts/save-pack.sh       copy a generated pack out of the WP install into ../ability-packs/
../ability-packs/            generated ability-pack plugins (one dir each), at the monorepo root
```

## License

GPL-2.0-or-later (the generated packs are WordPress plugins). See [`LICENSE`](LICENSE).
