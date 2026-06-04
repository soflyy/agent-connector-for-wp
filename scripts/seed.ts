/**
 * Seed Vercel KV with initial AI status data.
 * Usage: SEED_SECRET=your-secret KV_REST_API_URL=... KV_REST_API_TOKEN=... npm run seed
 */
import { kv } from "@vercel/kv";
import { AI_STATUS_SEED } from "../data/ai-status-seed";

async function main() {
  let ok = 0;
  let fail = 0;
  for (const [slug, status] of Object.entries(AI_STATUS_SEED)) {
    try {
      await kv.set(`aiStatus:${slug}`, status);
      console.log(`✓ ${slug}`);
      ok++;
    } catch (err) {
      console.error(`✗ ${slug}:`, err);
      fail++;
    }
  }
  console.log(`\nDone: ${ok} seeded, ${fail} failed`);
}

main().catch(console.error);
