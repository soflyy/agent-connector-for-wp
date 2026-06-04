import { NextRequest, NextResponse } from "next/server";
import { setAIStatus } from "@/lib/db";
import { AI_STATUS_SEED } from "@/data/ai-status-seed";

// Protected by SEED_SECRET env var — call once after deploying to populate KV
export async function POST(req: NextRequest) {
  const auth = req.headers.get("authorization");
  if (!process.env.SEED_SECRET || auth !== `Bearer ${process.env.SEED_SECRET}`) {
    return NextResponse.json({ error: "Unauthorized" }, { status: 401 });
  }

  const results: Record<string, "ok" | "error"> = {};
  for (const [slug, status] of Object.entries(AI_STATUS_SEED)) {
    try {
      await setAIStatus(slug, status);
      results[slug] = "ok";
    } catch {
      results[slug] = "error";
    }
  }

  const errors = Object.entries(results).filter(([, v]) => v === "error");
  return NextResponse.json({
    seeded: Object.keys(results).length,
    errors: errors.length,
    results,
  });
}
