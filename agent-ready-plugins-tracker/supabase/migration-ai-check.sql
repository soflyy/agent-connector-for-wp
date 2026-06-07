-- AI research check columns for existing databases.
-- Run once in your Supabase SQL editor (new installs already have these via migration.sql).

alter table ai_statuses
  add column if not exists sources         text[],
  add column if not exists confidence      text,
  add column if not exists source          text not null default 'ai',
  add column if not exists auto_checked_at  timestamptz,
  add column if not exists suggestion       jsonb;

-- Order daily checks by least-recently-checked first.
create index if not exists ai_statuses_auto_checked_at_idx on ai_statuses (auto_checked_at nulls first);
