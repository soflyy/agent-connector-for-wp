# Agent-Ready Plugins Tracker

The public directory of agent-ready WordPress plugins. A [Next.js](https://nextjs.org) app
(App Router, React 19, Tailwind) backed by [Supabase](https://supabase.com), deployed to
**Vercel**.

This is the `agent-ready-plugins-tracker/` subproject of the
[Agent Connector for WP](https://github.com/soflyy/agent-connector-for-wp) monorepo.

## Local development

```bash
cd agent-ready-plugins-tracker
npm install
cp .env.example .env.local   # then fill in the values (see below)
npm run dev                  # http://localhost:3000
```

Other scripts: `npm run build` (production build), `npm run start` (serve the build),
`npm run lint`.

## Environment

Copy [`.env.example`](.env.example) to `.env.local` and set:

| Var | What it is |
| --- | --- |
| `SITE_PASSWORD` | The password users enter at the gate. |
| `AUTH_TOKEN_HASH` | Any random secret used as the auth cookie value (`openssl rand -hex 32`). |
| `SUPABASE_URL` | Supabase project URL (Dashboard → Settings → API). |
| `SUPABASE_SERVICE_ROLE_KEY` | Supabase service-role key. |
| `SEED_SECRET` | Guards the seed API route. |
| `ADMIN_SECRET` | Guards the admin API routes. |
| `PACK_INDEX_URL` | Optional. Source manifest for `/api/ability-packs/match`; defaults to the agent-connector-for-wp `pack-index` release asset. |

## Ability-pack API

`POST /api/ability-packs/match` is a public, server-to-server endpoint used by the
Agent Connector for WP plugin. A site POSTs the plugins it has installed and gets back the
ability packs that target any of them:

```jsonc
// request
{ "plugins": [ { "slug": "contact-form-7", "file": "contact-form-7/wp-contact-form-7.php", "active": true } ] }
// response
{ "entries": [ { "ability_pack_slug": "unofficial-abilities-for-contact-form-7",
                 "target_plugin": "contact-form-7/wp-contact-form-7.php",
                 "version": "0.1.0", "download_url": "https://github.com/.../<slug>.zip", ... } ] }
```

It reads the single `pack-index` `index.json` manifest (cached ~10 min) — nothing enumerates
GitHub's releases — and is allowlisted as public in [`middleware.ts`](middleware.ts). The
response also carries a `statuses[]` array with each plugin's AI-support status (see below).

## Admin dashboard

`/admin` is a UI for managing the per-plugin AI status that powers the directory and the
`statuses` field of the match API. It's gated by the **`ADMIN_SECRET`** (enter it at
`/admin/login`; the admin area self-gates independently of the public site password). From it
you can:

- edit each plugin's status — level (`official` / `unofficial` / `coming_soon` / `none`),
  official version + docs URL, official abilities, and **third-party unofficial extensions**;
- add a plugin (a plugin row must exist before its status can be set);
- triage pending **submissions** (mark reviewed).

Writes go through server actions that re-check the admin session; the older
`PUT /api/admin/status` (bearer-token) endpoint still works for scripted updates.

## AI status check (Vercel AI Gateway)

The directory can keep itself current by researching each plugin's AI-ability status with
live web search and writing typed results to the DB.

- **How:** [`lib/ai-research.ts`](lib/ai-research.ts) calls a search-capable model through the
  **Vercel AI Gateway** (default `perplexity/sonar` — live web search + citations) with the AI
  SDK's `generateObject` + a Zod schema, returning `{ level, officialSince, officialDocsUrl,
  abilities, unofficialPlugins, notes, sources, confidence }`.
- **Apply policy:** results auto-apply to a plugin's status **except** statuses an admin edited
  by hand (`source = manual`) — those keep the human edit, and the AI result is stored as a
  `suggestion` you can review and "Load into form" on the edit page.
- **Schedule:** a daily Vercel **cron** ([`vercel.json`](vercel.json)) hits
  `GET /api/admin/ai-check` (authorized by `CRON_SECRET`) and re-checks the
  least-recently-checked `AI_CHECK_BATCH` plugins (default 25), cycling through the whole
  directory over days.
- **Manual:** the admin dashboard has **Run AI check now** (batch) and per-plugin
  **Re-check (AI)** buttons, which call `POST /api/admin/ai-check`.

Required env: `AI_GATEWAY_API_KEY`, `CRON_SECRET`. Optional: `AI_RESEARCH_MODEL`,
`AI_CHECK_BATCH`. Perplexity web search via the gateway bills ~\$5 / 1,000 searches plus tokens.

## Database

Run [`supabase/migration.sql`](supabase/migration.sql) against your Supabase project to
create the schema (existing databases: also run
[`supabase/migration-ai-check.sql`](supabase/migration-ai-check.sql) for the AI-check columns).
Seed data lives in [`data/`](data/); the seed and admin endpoints under [`app/api/`](app/) are
guarded by `SEED_SECRET` / `ADMIN_SECRET`.

## Deployment (Vercel)

Vercel builds this app straight from the monorepo. In the Vercel project:

1. Set **Root Directory** to `agent-ready-plugins-tracker`.
2. Add the environment variables above.
3. Push to the default branch — Vercel auto-detects Next.js and deploys.
