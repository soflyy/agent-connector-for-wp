import { NextRequest, NextResponse } from "next/server";
import { upsertPlugin } from "@/lib/db";
import type { Plugin } from "@/lib/types";

function isAuthorized(req: NextRequest): boolean {
  const auth = req.headers.get("authorization");
  return !!process.env.ADMIN_SECRET && auth === `Bearer ${process.env.ADMIN_SECRET}`;
}

// PUT /api/admin/status — create or update a single plugin record.
// Body: { plugin: Plugin } (or the legacy { slug, status } is no longer supported).
export async function PUT(req: NextRequest) {
  if (!isAuthorized(req)) {
    return NextResponse.json({ error: "Unauthorized" }, { status: 401 });
  }
  const body = await req.json().catch(() => null);
  const plugin: Plugin | undefined = body?.plugin;
  if (!plugin?.slug || !plugin?.name) {
    return NextResponse.json({ error: "plugin with slug and name required" }, { status: 400 });
  }
  await upsertPlugin({
    slug: plugin.slug,
    name: plugin.name,
    link: plugin.link,
    includesAbilities: !!plugin.includesAbilities,
    thirdPartyAbilities: plugin.thirdPartyAbilities ?? [],
    ac4wpAbilityPackUrl: plugin.ac4wpAbilityPackUrl,
  });
  return NextResponse.json({ ok: true, slug: plugin.slug });
}
