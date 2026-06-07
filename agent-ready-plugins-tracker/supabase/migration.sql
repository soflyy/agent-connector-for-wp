-- Run this once in your Supabase SQL editor (Dashboard → SQL Editor → New query).
--
-- Simplified model: a single `plugins` table. A plugin is identified by its WP
-- plugin file ("folder/main-file.php"), and we track only: whether it ships its
-- own abilities, and which third-party plugins provide abilities for it (our own
-- Agent Connector ability packs are just entries in that list).

-- Destructive: this redesign changes the `plugins` shape (and the slug now stores
-- the full plugin file). Old rows can't be migrated cleanly, so we recreate both
-- tables from scratch. Re-add plugins via the admin UI or the AI check afterwards.
drop table if exists ai_statuses;
drop table if exists plugins;

create table if not exists plugins (
  slug                    text primary key,                       -- "woocommerce/woocommerce.php"
  name                    text not null,
  link                    text,                                   -- .org page or commercial plugin page
  includes_abilities      boolean not null default false,         -- plugin ships its own (official) abilities
  third_party_abilities   jsonb   not null default '[]'::jsonb,   -- [{ pluginName, pluginSlug, pluginLink }] (incl. our packs)
  ai_checked_at           timestamptz,                            -- last AI research time (least-recent checked first)
  updated_at              timestamptz not null default now(),
  -- URL-safe slug derived from the plugin file, used for /plugins/<url_slug>:
  --   "woocommerce/woocommerce.php" -> "woocommerce"; "hello.php" -> "hello".
  url_slug text generated always as (
    case
      when position('/' in slug) > 0 then split_part(slug, '/', 1)
      else regexp_replace(slug, '\.php$', '')
    end
  ) stored
);

create index if not exists plugins_url_slug_idx on plugins (url_slug);

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
