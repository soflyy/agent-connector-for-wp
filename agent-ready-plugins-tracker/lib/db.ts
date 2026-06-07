import { createClient } from "@supabase/supabase-js";
import type { AIResearchResult, Plugin, ThirdPartyAbility } from "./types";

function getClient() {
  const url = process.env.SUPABASE_URL;
  const key = process.env.SUPABASE_SERVICE_ROLE_KEY;
  if (!url || !key) return null;
  return createClient(url, key, { auth: { persistSession: false } });
}

/** Reduce a plugin file or slug to its URL-safe folder slug.
 *  "woocommerce/woocommerce.php" -> "woocommerce"; "hello.php" -> "hello". */
export function folderSlug(value: string): string {
  const v = (value || "").trim();
  if (!v) return "";
  if (v.includes("/")) return v.slice(0, v.indexOf("/"));
  return v.replace(/\.php$/, "");
}

// ---- Reads ----

export async function getAllPlugins(): Promise<Plugin[]> {
  const supabase = getClient();
  if (!supabase) return [];
  const { data } = await supabase.from("plugins").select("*").order("name");
  return data?.map(rowToPlugin) ?? [];
}

/** Look up a plugin by its URL slug ("woocommerce"). */
export async function getPluginByUrlSlug(urlSlug: string): Promise<Plugin | null> {
  const supabase = getClient();
  if (!supabase) return null;
  const { data } = await supabase.from("plugins").select("*").eq("url_slug", urlSlug).maybeSingle();
  return data ? rowToPlugin(data) : null;
}

/** Whether a plugin with this exact slug (plugin file) already exists. */
export async function pluginExists(slug: string): Promise<boolean> {
  const supabase = getClient();
  if (!supabase) return false;
  const { data } = await supabase.from("plugins").select("slug").eq("slug", slug).maybeSingle();
  return !!data;
}

/** Look up a plugin by its primary key (the full plugin file). */
export async function getPlugin(slug: string): Promise<Plugin | null> {
  const supabase = getClient();
  if (!supabase) return null;
  const { data } = await supabase.from("plugins").select("*").eq("slug", slug).maybeSingle();
  return data ? rowToPlugin(data) : null;
}

/**
 * Given a set of installed plugin slugs (folder or file form), return the URL
 * slugs of those that exist in the directory. Used by the ability-pack match
 * endpoint so the WordPress plugin knows which plugins have a directory page.
 */
export async function getTrackedUrlSlugs(slugs: string[]): Promise<string[]> {
  const supabase = getClient();
  if (!supabase || slugs.length === 0) return [];
  const wanted = [...new Set(slugs.map(folderSlug).filter(Boolean))];
  if (wanted.length === 0) return [];
  const { data } = await supabase.from("plugins").select("url_slug").in("url_slug", wanted);
  return (data ?? []).map((r) => r.url_slug as string);
}

// ---- Writes ----

export async function upsertPlugin(plugin: Plugin): Promise<void> {
  const supabase = getClient();
  if (!supabase) throw new Error("Supabase not configured");
  const { error } = await supabase.from("plugins").upsert(pluginToRow(plugin));
  if (error) throw error;
}

export async function deletePlugin(slug: string): Promise<void> {
  const supabase = getClient();
  if (!supabase) throw new Error("Supabase not configured");
  const { error } = await supabase.from("plugins").delete().eq("slug", slug);
  if (error) throw error;
}

// ---- AI research ----

/**
 * Plugins to AI-check, least-recently-checked first (never-checked first), capped.
 * Returns minimal {slug, name} for the research step.
 */
export async function getPluginsToCheck(limit: number): Promise<Array<{ slug: string; name: string }>> {
  const supabase = getClient();
  if (!supabase) return [];
  const { data } = await supabase
    .from("plugins")
    .select("slug, name, ai_checked_at")
    .order("ai_checked_at", { ascending: true, nullsFirst: true })
    .limit(limit);
  return (data ?? []).map((r) => ({ slug: r.slug as string, name: r.name as string }));
}

/**
 * Apply an AI research result: overwrite the two AI-determined fields
 * (includes_abilities, third_party_abilities) and stamp the check time.
 * The ability-pack link is curated separately and left untouched.
 */
export async function applyAiResult(slug: string, result: AIResearchResult): Promise<void> {
  const supabase = getClient();
  if (!supabase) throw new Error("Supabase not configured");
  const { error } = await supabase
    .from("plugins")
    .update({
      includes_abilities: result.pluginIncludesOfficialAbilities,
      third_party_abilities: result.thirdPartyAbilitiesProvidedBy,
      ai_checked_at: new Date().toISOString(),
      updated_at: new Date().toISOString(),
    })
    .eq("slug", slug);
  if (error) throw error;
}

// ---- Row converters ----

// eslint-disable-next-line @typescript-eslint/no-explicit-any
function rowToPlugin(row: any): Plugin {
  return {
    slug: row.slug,
    urlSlug: row.url_slug ?? folderSlug(row.slug),
    name: row.name,
    link: row.link ?? undefined,
    includesAbilities: !!row.includes_abilities,
    thirdPartyAbilities: normalizeThirdParty(row.third_party_abilities),
  };
}

function pluginToRow(p: Plugin) {
  return {
    slug: p.slug,
    name: p.name,
    link: p.link ?? null,
    includes_abilities: p.includesAbilities,
    third_party_abilities: p.thirdPartyAbilities ?? [],
    updated_at: new Date().toISOString(),
  };
}

// eslint-disable-next-line @typescript-eslint/no-explicit-any
function normalizeThirdParty(value: any): ThirdPartyAbility[] {
  if (!Array.isArray(value)) return [];
  return value
    .filter((u) => u && typeof u === "object")
    .map((u) => ({
      pluginName: String(u.pluginName ?? ""),
      pluginSlug: String(u.pluginSlug ?? ""),
      pluginLink: String(u.pluginLink ?? ""),
    }))
    .filter((u) => u.pluginName);
}
