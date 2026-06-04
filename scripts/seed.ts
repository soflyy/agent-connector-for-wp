/**
 * Seed Supabase with initial AI status data.
 * Usage: SUPABASE_URL=... SUPABASE_SERVICE_ROLE_KEY=... npm run seed
 */
import { createClient } from "@supabase/supabase-js";
import { AI_STATUS_SEED } from "../data/ai-status-seed";
import type { AIStatus } from "../lib/types";

const url = process.env.SUPABASE_URL;
const key = process.env.SUPABASE_SERVICE_ROLE_KEY;

if (!url || !key) {
  console.error("Missing SUPABASE_URL or SUPABASE_SERVICE_ROLE_KEY");
  process.exit(1);
}

const supabase = createClient(url, key, { auth: { persistSession: false } });

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

async function main() {
  const rows = Object.entries(AI_STATUS_SEED).map(([slug, status]) =>
    statusToRow(slug, status)
  );

  const { error } = await supabase.from("ai_statuses").upsert(rows);
  if (error) {
    console.error("Seed failed:", error.message);
    process.exit(1);
  }
  console.log(`✓ Seeded ${rows.length} plugins`);
}

main().catch(console.error);
