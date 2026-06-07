import { NextRequest, NextResponse } from "next/server";
import { setAIStatus } from "@/lib/db";
import type { AIStatus } from "@/lib/types";

function isAuthorized(req: NextRequest): boolean {
  const auth = req.headers.get("authorization");
  return !!process.env.ADMIN_SECRET && auth === `Bearer ${process.env.ADMIN_SECRET}`;
}

// PUT /api/admin/status — update a single plugin's AI status
export async function PUT(req: NextRequest) {
  if (!isAuthorized(req)) {
    return NextResponse.json({ error: "Unauthorized" }, { status: 401 });
  }
  const body = await req.json();
  const { slug, status }: { slug: string; status: AIStatus } = body;
  if (!slug || !status) {
    return NextResponse.json({ error: "slug and status required" }, { status: 400 });
  }
  await setAIStatus(slug, status);
  return NextResponse.json({ ok: true, slug });
}
