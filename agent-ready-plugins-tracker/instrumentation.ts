export async function register() {
  if (process.env.NEXT_RUNTIME !== "nodejs") return;
  if (!process.env.SUPABASE_URL || !process.env.SUPABASE_SERVICE_ROLE_KEY) return;

  const { createClient } = await import("@supabase/supabase-js");
  const supabase = createClient(
    process.env.SUPABASE_URL,
    process.env.SUPABASE_SERVICE_ROLE_KEY,
    { auth: { persistSession: false } }
  );

  // Sanity check only — the directory is populated through the admin UI and the
  // AI research job, not seeded. Warn loudly if the table is missing.
  const { error } = await supabase
    .from("plugins")
    .select("*", { count: "exact", head: true });

  if (error) {
    console.warn("[wp-ai-ready] plugins table not found — run supabase/migration.sql first");
  }
}
