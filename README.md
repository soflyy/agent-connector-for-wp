# mcp-for-everything-wp

Turn **any WordPress plugin** into a full-coverage MCP surface an agent can drive.

This repo holds two things:

- **`skills/wp-mcp-generator/`** — the generator. A Claude Code skill (a principle-driven
  playbook + a ready-to-fill ability-pack scaffold) that, given a plugin `.zip` or `.org`
  slug, installs it, understands it (its code is the source of truth; exercising it confirms
  and surfaces gaps), designs an **agent-ergonomic** ability surface, and ships a companion
  *ability pack* plugin — verified to actually work.
- **`packs/`** — the outputs. One generated ability-pack plugin per target, e.g.
  `agent-connector-for-wp-ability-pack-contact-form-7/`.

The generated packs register their abilities via the **WP Abilities API**, which
[Agent Connector for WP](https://github.com/soflyy/agent-connector-for-wp) (via `mcp-adapter`)
automatically exposes as MCP endpoints — with auth, domain-lock, and audit injected for you.

## How it's meant to be used

1. **Spin up a sandbox** with
   [create-wp-local-dev-agent-sandbox](https://github.com/soflyy/create-wp-local-dev-agent-sandbox)
   (gives you a WordPress install, WP-CLI, Playwright MCP, and Agent Connector preinstalled).
2. **Clone this repo and install the skill** into the agent's skills dir:
   ```bash
   git clone git@github.com:soflyy/mcp-for-everything-wp.git
   ./mcp-for-everything-wp/scripts/install-skill.sh
   ```
   This symlinks `skills/wp-mcp-generator` into `~/.claude/skills/`, so editing the skill in
   the repo updates it live (improve it as you go, then commit).
3. **Generate a pack.** Ask the agent to generate MCP for a plugin; it invokes the
   `wp-mcp-generator` skill, which builds the pack under `wp/wp-content/plugins/`.
4. **Save the pack back into the repo:**
   ```bash
   ./mcp-for-everything-wp/scripts/save-pack.sh contact-form-7
   ```
   then commit it under `packs/`.

## What "done" means

The generator's standard of acceptance is not "the endpoints are wrapped" — it's: *the agent
can accomplish anything a human admin could with the plugin, through the abilities, verified*
from independent vantage points (UI via Playwright, the database, REST). See
[`skills/wp-mcp-generator/SKILL.md`](skills/wp-mcp-generator/SKILL.md) for the full playbook
and the design principles (agent-ergonomics over code-reuse; the code is the source of truth;
a closed coverage loop with objective termination conditions).

## Layout

```
skills/wp-mcp-generator/   the generator skill (SKILL.md) + scaffold/ template
packs/                     generated ability-pack plugins (one dir each)
scripts/install-skill.sh   link the skill into ~/.claude/skills/
scripts/save-pack.sh       copy a generated pack out of the WP install into packs/
```

## License

GPL-2.0-or-later (the generated packs are WordPress plugins). See [`LICENSE`](LICENSE).
