export async function register() {
  if (process.env.NEXT_RUNTIME !== "nodejs") return;
  if (!process.env.SUPABASE_URL || !process.env.SUPABASE_SERVICE_ROLE_KEY) return;

  const { createClient } = await import("@supabase/supabase-js");
  const supabase = createClient(
    process.env.SUPABASE_URL,
    process.env.SUPABASE_SERVICE_ROLE_KEY,
    { auth: { persistSession: false } }
  );

  const { count, error } = await supabase
    .from("plugins")
    .select("*", { count: "exact", head: true });

  if (error) {
    console.warn("[wp-ai-ready] plugins table not found — run supabase/migration.sql first");
    return;
  }

  if ((count ?? 0) > 0) return;

  const { PLUGINS } = await import("./data/plugins");
  const { AI_STATUS_SEED } = await import("./data/ai-status-seed");

  const pluginRows = PLUGINS.map((p) => ({
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
  }));

  const { error: pluginError } = await supabase.from("plugins").insert(pluginRows);
  if (pluginError) {
    console.error("[wp-ai-ready] Plugin seed error:", pluginError.message);
    return;
  }

  const statusRows = Object.entries(AI_STATUS_SEED).map(([slug, s]) => ({
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

  const { error: statusError } = await supabase.from("ai_statuses").insert(statusRows);
  if (statusError) {
    console.error("[wp-ai-ready] Status seed error:", statusError.message);
  } else {
    console.log(`[wp-ai-ready] Seeded ${pluginRows.length} plugins + statuses`);
  }
}
