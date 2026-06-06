---
name: wp-mcp-generator
description: >-
  Generate full-coverage MCP for any WordPress plugin. Given a plugin .zip or .org
  slug, install it, understand it deeply (its code is the source of truth; exercising
  it confirms and surfaces gaps), design an agent-ergonomic ability surface, and ship
  a companion "ability pack" plugin that registers those abilities via the WP Abilities
  API — which Agent Connector for WP (mcp-adapter) auto-exposes as MCP. Done is measured
  by you actually being able to drive the plugin through the abilities to get the results
  you intend, verified. Use when asked to build/generate MCP tools or abilities for a WP plugin.
---

# WP Plugin → Full-Coverage MCP Generator

You are turning an arbitrary WordPress plugin into an MCP surface that lets an agent
control **everything a human admin can do** with that plugin — and you must *prove* it
works, not assume it. The output is a companion **ability pack** plugin.

This skill gives you the **standard, the principles, and the tools**. It deliberately
does **not** give you a plugin-specific recipe. Every plugin is different; you derive the
right tactics yourself by reading its code and using it. Trust your exploration.

---

## The standard of done (read this first)

> **You can accomplish any task a human admin could accomplish with this plugin, by
> calling the abilities you registered — and you have verified each one end to end.**

Not "I wrapped the endpoints." Not "the abilities return 200." The bar is: *you, acting
as the agent, can get the intended real-world result*, confirmed from more than one
independent vantage point (the UI shows it, the database holds it, the plugin renders it).
If an ability doesn't let you do that, it isn't done — keep working.

---

## Two non-negotiable principles

**1. The code is the source of truth.** A plugin's true data model — where it stores
things, in what shape, how it loads and saves them, what validation runs — lives in its
PHP, not in its UI. **Read all of it.** Exercising the UI is how you confirm your reading
and discover *where to look next*; it is not how you achieve understanding. When the UI
and your reading of the code disagree, the code (and the database) win — go find why.

**2. Design for the agent, not for code-reuse.** The abilities are a *synthesized API
built for an agent's convenience*. They are not a mirror of the plugin's storage model or
its existing endpoints. Optimize relentlessly for **minimal input** and **task-level
verbs**: the agent should pass only the delta it cares about, never re-send a whole
structure it isn't changing. If giving the agent that ergonomics means implementing logic
the plugin doesn't have, implement it. Ease of use for the agent outranks reusing existing
code every time.

> You will frequently discover that the cleanest way to honor both principles is to read
> the current state through the plugin's own loader, make your small change in your
> companion plugin's PHP, and persist through the plugin's own save path so its validation
> and hooks still run. You don't need to be told to do this for any specific plugin — you
> will arrive at it (or something better) by understanding how the plugin loads and saves.

---

## What you produce

1. A companion **ability pack** plugin under `wp/wp-content/plugins/` (start from
   `scaffold/` — see "Using the scaffold"). It registers categories + abilities and, where
   needed, carries the adapter logic that makes those abilities ergonomic.
2. A **coverage report** (`coverage/coverage-report.md` in the pack) mapping every surface
   and every admin task to an ability — or to an explicit *excluded, with reason*.
3. A populated **inventory** (`coverage/inventory.json`) — the structured understanding
   that backs the coverage claim.

---

## The loop (how "full coverage" becomes objective, not a vibe)

Run this loop. Do **not** declare done until all three termination conditions hold.

**A. Understand — from code, then from use.**
- Read the entire plugin. Build the **inventory** as you go (`coverage/inventory.schema.json`
  documents the shape). Capture *surfaces* (every way the admin interacts — admin pages,
  settings, REST routes, AJAX actions, CPTs/taxonomies, shortcodes/blocks, cron, caps, CLI),
  *data stores* (every option key, table + schema, meta key, CPT — **with its load and save
  paths**), and *tasks* (the verbs an admin performs).
- Then exercise it for real in Playwright (`http://wordpress/`, admin `admin`/`password`).
  Diff the database around each action (`tools/db-diff.sh`) to see exactly what each task
  mutates and to catch stores/actions your code read missed. Watch the save requests and
  any client-side state — that is often where a builder's true document format reveals itself.

**B. Design + implement abilities** for everything understood so far, following the
principles above. Prefer many small ergonomic verbs over few structural mega-operations.
Where the agent would benefit from knowing valid shapes (field types, node kinds, option
enums), register **introspection abilities** so it can discover them at runtime instead of
guessing.

**C. Exercise both paths and cross-check.** For each task, do it (a) via your ability and
(b) via the real UI, and confirm the resulting state converges. Disagreement = a bug or a
nuance you missed — fix it, and feed any newly-discovered surface back into A.

**D. Repeat until all three hold:**
- **Coverage** — every inventory surface and task maps to an ability *or* an explicit
  excluded-with-reason.
- **Discovery saturation** — further exploration rounds (UI walking + DB diffing + re-reading
  code) surface nothing new.
- **Functional acceptance** — every ability passes its acceptance check (below). No ability
  ships known-broken.

---

## Acceptance: triangulate, then keep fixing

For each ability, define the *intent* ("add a text field named X to group Y"), call the
ability, and confirm the outcome from **independent** vantage points — typically the UI
(Playwright shows/renders it), the database (`wp db query` / `wp option get`), and a REST
read where one exists. Triangulation is what catches "wrote the DB but the UI ignores it"
and "looks right in the UI but didn't persist." On failure: diagnose, fix the ability (or
your understanding), re-run. The loop does not exit with a failing ability.

Call your own abilities through MCP exactly as a real agent would:
`mcp-adapter-execute-ability` (discover with `mcp-adapter-discover-abilities`,
inspect with `mcp-adapter-get-ability-info`). Don't shortcut by calling your PHP directly —
test the surface the agent will actually use.

---

## Environment (how to actually do the work here)

- **WP-CLI runs in your workspace shell** — `wp plugin install <zip-or-slug> --activate`,
  `wp db query`, `wp option get`, `wp post list`, etc. Files are live under `wp/`; edit them
  directly. This is your fastest path — prefer it over MCP for setup and inspection.
- **Install the target** from a `.zip` path or a `.org` slug with `wp plugin install`.
  Snapshot a clean baseline first (`wp db export`) so you can roll back between exploration
  passes — some actions send mail, hit external services, or delete data.
- **Drive the UI** with the Playwright MCP browser pointed at `http://wordpress/`
  (`http://localhost:8080` is **not** reachable from the browser container).
- **Run abilities** through the `wordpress` MCP server (the `mcp-adapter-*` tools).
- **Run code inside the live WP runtime** when you need a plugin function in request context:
  `agent-connector-for-wp/php-eval` via `mcp-adapter-execute-ability`.
- **Scope:** free .org plugins and unlocked commercial `.zip`s (no license gating). Multisite
  is out of scope. Frontend/JS state *is* in scope — inspect it. Note per-plugin any feature
  that needs an external service the sandbox can't reach.

---

## Using the scaffold

`scaffold/` is a ready-to-fill ability-pack plugin that already obeys the Agent Connector
convention (you write **no** auth/permission/domain/audit code — Agent Connector injects all
of it). To start a pack:

1. Copy `scaffold/` to `wp/wp-content/plugins/unofficial-abilities-for-<target>/`.
2. Rename the main file to match the folder and fill its header placeholders — crucially
   `Agent Connector Target: <target-plugin-file>` (e.g. `woocommerce/woocommerce.php`), and
   pick your vendor namespace `<vendor>` for ability names (`<vendor>/add-field`). Never use
   the `agent-connector-for-wp/` namespace. Leave `Version: 0.0.0-dev` untouched — the
   release CI stamps the real version into the built zip on merge; never hand-set it.
3. Add abilities as files under `abilities/` using `ACFW_Pack::ability([...])` and categories
   with `ACFW_Pack::category(...)`. Put reimplemented/adapter logic under `src/`.
4. Keep `coverage/inventory.json` and `coverage/coverage-report.md` current as you go — they
   are deliverables, not notes.

The exact registration arg contract (label, description, category, input_schema, output_schema,
execute_callback, annotations, optional summarize) is documented in
`wp/wp-content/plugins/agent-connector-for-wp/docs/registering-abilities.md` — read it once.
Write **precise descriptions and tight input schemas**: they are the product, because they are
what makes an agent succeed or fail at choosing and calling the ability.

---

## Failure modes to avoid

- **Mirroring storage as CRUD.** Forcing the agent to re-send a whole field group / document
  / settings blob to change one thing. Give it a verb that takes the delta.
- **Understanding from the UI instead of the code.** The UI samples; the code is complete.
- **Calling your own PHP to "verify."** Verify through the MCP surface + independent vantage
  points, the way the real agent will hit it.
- **Silent coverage gaps.** If you exclude a surface, say so and why in the coverage report.
  An unexplained gap reads as "covered" when it isn't.
- **Shipping reimplemented logic you didn't round-trip through the plugin.** If you mutate a
  document/structure yourself, prove the plugin still accepts and renders it.
