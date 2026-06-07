import { NextRequest, NextResponse } from "next/server";
import { verifyTurnstile } from "@/lib/turnstile";
import { checkPluginUrl } from "@/lib/url-check";
import { moderateSubmission } from "@/lib/moderation";
import { pluginExists, getPluginByUrlSlug, upsertPlugin, folderSlug } from "@/lib/db";

export const dynamic = "force-dynamic";
export const maxDuration = 60; // AI moderation can take a few seconds

const MAX_LEN = 100;

function bad(error: string, status = 400) {
  return NextResponse.json({ ok: false, error }, { status });
}

// Public endpoint (see middleware PUBLIC_PATHS). Anyone can submit a plugin; it is
// auto-moderated by AI and added to the directory only if approved. Checks run
// cheapest-first: bot check → length sanity → duplicate → URL reachable → AI.
export async function POST(req: NextRequest) {
  let body: { name?: string; slug?: string; link?: string; turnstileToken?: string };
  try {
    body = await req.json();
  } catch {
    return bad("Invalid request.");
  }

  const name = String(body?.name ?? "").trim();
  const slug = String(body?.slug ?? "").trim();
  const link = String(body?.link ?? "").trim();

  // 1. Bot check (Cloudflare Turnstile).
  const ip = req.headers.get("x-forwarded-for")?.split(",")[0]?.trim();
  if (!(await verifyTurnstile(body?.turnstileToken, ip))) {
    return bad("Bot check failed — please retry.");
  }

  // 2. Length sanity.
  if (!name || !slug || !link) return bad("Name, slug, and link are all required.");
  if (name.length >= MAX_LEN) return bad("Name must be under 100 characters.");
  if (slug.length >= MAX_LEN) return bad("Slug must be under 100 characters.");

  // 3. Duplicate check (exact slug, or same URL slug already listed).
  if ((await pluginExists(slug)) || (await getPluginByUrlSlug(folderSlug(slug)))) {
    return bad("That plugin is already in the directory.", 409);
  }

  // 4. URL is a reachable, non-404 site.
  const urlCheck = await checkPluginUrl(link);
  if (!urlCheck.ok) {
    return bad(urlCheck.error ?? "The plugin link doesn't resolve to a working site.");
  }

  // 5. AI moderation — is this a real, listable WordPress plugin?
  const verdict = await moderateSubmission({ name, slug, link });
  if (!verdict.approved) {
    return NextResponse.json({ ok: false, approved: false, error: `Not approved: ${verdict.reason}` });
  }

  // Approved — add it. The daily AI check fills in abilities later.
  try {
    await upsertPlugin({ slug, name, link, includesAbilities: false, thirdPartyAbilities: [] });
  } catch {
    return bad("Approved, but saving failed — please try again.", 500);
  }

  return NextResponse.json({ ok: true, approved: true, reason: verdict.reason });
}
