import { NextResponse } from "next/server";
import { setAIStatus } from "@/lib/db";
import { AI_STATUS_SEED } from "@/data/ai-status-seed";

export async function GET() {
  const results: Record<string, "ok" | "error"> = {};
  for (const [slug, status] of Object.entries(AI_STATUS_SEED)) {
    try {
      await setAIStatus(slug, status);
      results[slug] = "ok";
    } catch {
      results[slug] = "error";
    }
  }
  const errorCount = Object.values(results).filter((v) => v === "error").length;
  return NextResponse.json({ seeded: Object.keys(results).length, errors: errorCount, results });
}
