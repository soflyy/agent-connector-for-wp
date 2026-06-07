import { createClient } from "@supabase/supabase-js";
import type { AIStatus, Plugin, PluginWithStatus } from "./types";

function getClient() {
  const url = process.env.SUPABASE_URL;
  const key = process.env.SUPABASE_SERVICE_ROLE_KEY;
  if (!url || !key) return null;
  return createClient(url, key, { auth: { persistSession: false } });
}

// ---- Plugins ----

export async function getAllPlugins(): Promise<Plugin[]> {
  const supabase = getClient();
  if (!supabase) return [];
  const { data } = await supabase.from("plugins").select("*").order("name");
  return data?.map(rowToPlugin) ?? [];
}

export async function getPlugin(slug: string): Promise<Plugin | null> {
  const supabase = getClient();
  if (!supabase) return null;
  const { data } = await supabase.from("plugins").select("*").eq("slug", slug).single();
  return data ? rowToPlugin(data) : null;
}

export async function upsertPlugin(plugin: Plugin): Promise<void> {
  const supabase = getClient();
  if (!supabase) throw new Error("Supabase not configured");
  const { error } = await supabase.from("plugins").upsert(pluginToRow(plugin));
  if (error) throw error;
}

// ---- Plugins + AI status joined ----

export async function getAllPluginsWithStatus(): Promise<PluginWithStatus[]> {
  const supabase = getClient();
  if (!supabase) return [];
  const { data } = await supabase
    .from("plugins")
    .select("*, ai_statuses(*)")
    .order("name");
  if (!data) return [];
  return data.map((row) => ({
    ...rowToPlugin(row),
    aiStatus: row.ai_statuses
      ? rowToStatus(row.ai_statuses)
      : { level: "none" as const, unofficialPlugins: [], lastVerified: "unknown" },
  }));
}

export async function getPluginWithStatus(slug: string): Promise<PluginWithStatus | null> {
  const supabase = getClient();
  if (!supabase) return null;
  const { data } = await supabase
    .from("plugins")
    .select("*, ai_statuses(*)")
    .eq("slug", slug)
    .single();
  if (!data) return null;
  return {
    ...rowToPlugin(data),
    aiStatus: data.ai_statuses
      ? rowToStatus(data.ai_statuses)
      : { level: "none" as const, unofficialPlugins: [], lastVerified: "unknown" },
  };
}

/**
 * AI status for a set of plugin slugs, keyed by slug. Used by the ability-pack
 * match endpoint to tell a site which of its plugins have official / third-party
 * AI abilities. Missing slugs are simply absent from the map.
 */
export async function getStatusesForSlugs(slugs: string[]): Promise<Record<string, AIStatus>> {
  const supabase = getClient();
  if (!supabase || slugs.length === 0) return {};
  const { data } = await supabase.from("ai_statuses").select("*").in("slug", slugs);
  if (!data) return {};
  const out: Record<string, AIStatus> = {};
  for (const row of data) out[row.slug as string] = rowToStatus(row);
  return out;
}

// ---- AI Status ----

export async function setAIStatus(slug: string, status: AIStatus): Promise<void> {
  const supabase = getClient();
  if (!supabase) throw new Error("Supabase not configured");
  const { error } = await supabase.from("ai_statuses").upsert(statusToRow(slug, status));
  if (error) throw error;
}

// ---- Row converters ----

// eslint-disable-next-line @typescript-eslint/no-explicit-any
function rowToPlugin(row: any): Plugin {
  return {
    slug: row.slug,
    name: row.name,
    tagline: row.tagline,
    wpOrgUrl: row.wp_org_url ?? undefined,
    repoUrl: row.repo_url ?? undefined,
    isPremium: row.is_premium,
    categories: row.categories ?? [],
    activeInstalls: row.active_installs,
    author: row.author,
    authorUrl: row.author_url ?? undefined,
  };
}

function pluginToRow(p: Plugin) {
  return {
    slug: p.slug,
    name: p.name,
    tagline: p.tagline,
    wp_org_url: p.wpOrgUrl ?? null,
    repo_url: p.repoUrl ?? null,
    is_premium: p.isPremium,
    categories: p.categories,
    active_installs: p.activeInstalls,
    author: p.author,
    author_url: p.authorUrl ?? null,
  };
}

// eslint-disable-next-line @typescript-eslint/no-explicit-any
function rowToStatus(row: any): AIStatus {
  return {
    level: row.level,
    officialSince: row.official_since ?? undefined,
    officialDocsUrl: row.official_docs_url ?? undefined,
    abilitiesCount: row.abilities_count ?? undefined,
    abilities: row.abilities ?? undefined,
    unofficialPlugins: row.unofficial_plugins ?? [],
    notes: row.notes ?? undefined,
    lastVerified: row.last_verified ?? "unknown",
  };
}

function statusToRow(slug: string, status: AIStatus) {
  return {
    slug,
    level: status.level,
    official_since: status.officialSince ?? null,
    official_docs_url: status.officialDocsUrl ?? null,
    abilities_count: status.abilitiesCount ?? null,
    abilities: status.abilities ?? null,
    unofficial_plugins: status.unofficialPlugins,
    notes: status.notes ?? null,
    last_verified: status.lastVerified,
    updated_at: new Date().toISOString(),
  };
}
