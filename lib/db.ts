import { createClient } from "@supabase/supabase-js";
import type { AIStatus, Submission } from "./types";

function getClient() {
  const url = process.env.SUPABASE_URL;
  const key = process.env.SUPABASE_SERVICE_ROLE_KEY;
  if (!url || !key) return null;
  return createClient(url, key, { auth: { persistSession: false } });
}

// ---- AI Status ----

export async function getAIStatus(slug: string): Promise<AIStatus | null> {
  const supabase = getClient();
  if (!supabase) return null;
  const { data } = await supabase
    .from("ai_statuses")
    .select("*")
    .eq("slug", slug)
    .single();
  return data ? rowToStatus(data) : null;
}

export async function getAllAIStatuses(
  slugs: string[]
): Promise<Record<string, AIStatus>> {
  const supabase = getClient();
  if (!supabase || slugs.length === 0) return {};
  const { data } = await supabase
    .from("ai_statuses")
    .select("*")
    .in("slug", slugs);
  if (!data) return {};
  return Object.fromEntries(data.map((row) => [row.slug, rowToStatus(row)]));
}

export async function setAIStatus(
  slug: string,
  status: AIStatus
): Promise<void> {
  const supabase = getClient();
  if (!supabase) throw new Error("Supabase not configured");
  const { error } = await supabase
    .from("ai_statuses")
    .upsert(statusToRow(slug, status));
  if (error) throw error;
}

// ---- Submissions ----

export async function addSubmission(submission: Submission): Promise<void> {
  const supabase = getClient();
  if (!supabase) throw new Error("Supabase not configured");
  const { error } = await supabase.from("submissions").insert(submission);
  if (error) throw error;
}

export async function getSubmissions(): Promise<Submission[]> {
  const supabase = getClient();
  if (!supabase) return [];
  const { data } = await supabase
    .from("submissions")
    .select("*")
    .order("submitted_at", { ascending: false });
  return data ?? [];
}

// ---- Row <-> Type conversions (DB uses snake_case) ----

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
