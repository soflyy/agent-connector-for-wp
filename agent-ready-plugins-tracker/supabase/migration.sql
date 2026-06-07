-- Run this once in your Supabase SQL editor (Dashboard → SQL Editor → New query)

create table if not exists plugins (
  slug             text primary key,
  name             text not null,
  tagline          text not null,
  wp_org_url       text,
  repo_url         text,
  is_premium       boolean not null default false,
  categories       text[] not null default '{}',
  active_installs  text not null default '',
  author           text not null default '',
  author_url       text
);

create table if not exists ai_statuses (
  slug                text primary key references plugins(slug) on delete cascade,
  level               text not null check (level in ('official', 'unofficial', 'none')),
  official_since      text,
  official_docs_url   text,
  abilities_count     integer,
  abilities           text[],
  unofficial_plugins  jsonb not null default '[]'::jsonb,
  notes               text,
  last_verified       text,
  updated_at          timestamptz not null default now(),
  -- AI research check (see migration-ai-check.sql for existing databases):
  sources             text[],                       -- source URLs backing the status
  confidence          text,                         -- 'high' | 'medium' | 'low'
  source              text not null default 'ai',   -- 'manual' (curated) | 'ai'
  auto_checked_at     timestamptz,                  -- last AI check time
  suggestion          jsonb                         -- latest AI result (kept even when not applied)
);

-- Auto-reload PostgREST's schema cache whenever the schema changes (DDL), so new
-- tables/columns/relationships are picked up immediately — no manual "Reload schema
-- cache" needed after a migration. (PostgREST listens on the `pgrst` channel.)
create or replace function public.pgrst_reload_on_ddl()
  returns event_trigger language plpgsql as $$
begin
  notify pgrst, 'reload schema';
end;
$$;

drop event trigger if exists pgrst_reload_on_ddl;
create event trigger pgrst_reload_on_ddl on ddl_command_end
  execute function public.pgrst_reload_on_ddl();

-- Reload once now so the changes above are picked up immediately.
notify pgrst, 'reload schema';
