export async function register() {
  if (process.env.NEXT_RUNTIME !== "nodejs") return;
  if (!process.env.SUPABASE_URL || !process.env.SUPABASE_SERVICE_ROLE_KEY) return;

  const { createClient } = await import("@supabase/supabase-js");
  const supabase = createClient(
    process.env.SUPABASE_URL,
    process.env.SUPABASE_SERVICE_ROLE_KEY,
    { auth: { persistSession: false } }
  );

  // Check if the table exists and has data yet
  const { count, error } = await supabase
    .from("ai_statuses")
    .select("*", { count: "exact", head: true });

  // Table doesn't exist yet — user still needs to run migration.sql
  if (error) {
    console.warn("[wp-ai-ready] ai_statuses table not found — run supabase/migration.sql first");
    return;
  }

  // Already seeded
  if ((count ?? 0) > 0) return;

  // Seed initial data
  const { AI_STATUS_SEED } = await import("./data/ai-status-seed");
  const rows = Object.entries(AI_STATUS_SEED).map(([slug, s]) => ({
    slug,
    level: s.level,
    official_since: s.officialSince ?? null,
    official_docs_url: s.officialDocsUrl ?? null,
    abilities_count: s.abilitiesCount ?? null,
    abilities: s.abilities ?? null,
    unofficial_plugins: s.unofficialPlugins,
    notes: s.notes ?? null,
    last_verified: s.lastVerified,
    updated_at: new Date().toISOString(),
  }));

  const { error: seedError } = await supabase.from("ai_statuses").insert(rows);
  if (seedError) {
    console.error("[wp-ai-ready] Seed error:", seedError.message);
  } else {
    console.log(`[wp-ai-ready] Seeded ${rows.length} plugins`);
  }
}
