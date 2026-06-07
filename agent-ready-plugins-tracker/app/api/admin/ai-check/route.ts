import { NextRequest, NextResponse } from "next/server";
import { isAdmin } from "@/lib/admin-auth";
import { runBatch, checkBySlug } from "@/lib/ai-check";

export const dynamic = "force-dynamic";
export const maxDuration = 300; // allow long batches (Vercel Pro)

const DEFAULT_BATCH = Number(process.env.AI_CHECK_BATCH || "25");

function cronAuthorized(req: NextRequest): boolean {
  const secret = process.env.CRON_SECRET;
  return !!secret && req.headers.get("authorization") === `Bearer ${secret}`;
}

// Daily cron entry (Vercel sends Authorization: Bearer CRON_SECRET). Also usable
// by a signed-in admin. Re-checks the least-recently-checked plugins, capped.
export async function GET(req: NextRequest) {
  if (!cronAuthorized(req) && !(await isAdmin())) {
    return NextResponse.json({ error: "Unauthorized" }, { status: 401 });
  }
  const results = await runBatch(DEFAULT_BATCH);
  return NextResponse.json({ checked: results.length, results });
}

// Manual entry from the admin dashboard: { slug } for one, { all: true } for a batch.
export async function POST(req: NextRequest) {
  if (!(await isAdmin())) {
    return NextResponse.json({ error: "Unauthorized" }, { status: 401 });
  }
  let body: { slug?: string; all?: boolean } = {};
  try {
    body = await req.json();
  } catch {
    // empty body is fine
  }
  if (body.all) {
    const results = await runBatch(DEFAULT_BATCH);
    return NextResponse.json({ checked: results.length, results });
  }
  if (!body.slug) {
    return NextResponse.json({ error: "slug or all required" }, { status: 400 });
  }
  const result = await checkBySlug(body.slug);
  return NextResponse.json(result);
}
