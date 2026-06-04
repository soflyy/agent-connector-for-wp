import { NextRequest, NextResponse } from "next/server";
import { setAIStatus } from "@/lib/db";
import { AI_STATUS_SEED } from "@/data/ai-status-seed";

export async function GET(req: NextRequest) {
  const secret = req.nextUrl.searchParams.get("secret");
  if (!process.env.SEED_SECRET || secret !== process.env.SEED_SECRET) {
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

  const errorCount = Object.values(results).filter((v) => v === "error").length;
  return NextResponse.json({ seeded: Object.keys(results).length, errors: errorCount, results });
}
