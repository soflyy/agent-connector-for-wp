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

## Database

Run [`supabase/migration.sql`](supabase/migration.sql) against your Supabase project to
create the schema. Seed data lives in [`data/`](data/); the seed and admin endpoints under
[`app/api/`](app/) are guarded by `SEED_SECRET` / `ADMIN_SECRET`.

## Deployment (Vercel)

Vercel builds this app straight from the monorepo. In the Vercel project:

1. Set **Root Directory** to `agent-ready-plugins-tracker`.
2. Add the environment variables above.
3. Push to the default branch — Vercel auto-detects Next.js and deploys.
