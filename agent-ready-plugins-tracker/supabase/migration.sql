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
  updated_at          timestamptz not null default now()
);
