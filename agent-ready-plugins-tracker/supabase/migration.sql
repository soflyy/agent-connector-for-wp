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
  level               text not null check (level in ('official', 'unofficial', 'coming_soon', 'none')),
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

create table if not exists submissions (
  id               text primary key,
  plugin_slug      text not null references plugins(slug),
  type             text not null check (type in ('new_unofficial', 'status_correction', 'new_official')),
  submitted_at     timestamptz not null default now(),
  submitter_email  text,
  notes            text,
  data             jsonb not null default '{}'::jsonb,
  reviewed         boolean not null default false
);

create index if not exists submissions_plugin_slug_idx on submissions (plugin_slug);
create index if not exists submissions_reviewed_idx on submissions (reviewed) where reviewed = false;
