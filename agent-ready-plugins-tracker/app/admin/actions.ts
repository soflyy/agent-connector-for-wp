"use server";

import { revalidatePath } from "next/cache";
import { isAdmin } from "@/lib/admin-auth";
import { setAIStatus, upsertPlugin, markSubmissionReviewed } from "@/lib/db";
import type { AIStatus, Plugin } from "@/lib/types";

export type ActionResult = { ok: true } | { ok: false; error: string };

function toMessage(e: unknown): string {
  if (e && typeof e === "object" && "message" in e) {
    return String((e as { message: unknown }).message);
  }
  return typeof e === "string" ? e : "Unknown error";
}

export async function saveStatusAction(slug: string, status: AIStatus): Promise<ActionResult> {
  if (!(await isAdmin())) return { ok: false, error: "Not signed in as admin (try /admin/login again)." };
  try {
    await setAIStatus(slug, { ...status, lastVerified: new Date().toISOString().slice(0, 10) });
    revalidatePath("/admin");
    revalidatePath(`/admin/plugins/${slug}`);
    return { ok: true };
  } catch (e) {
    return { ok: false, error: toMessage(e) };
  }
}

export async function addPluginAction(plugin: Plugin): Promise<ActionResult> {
  if (!(await isAdmin())) return { ok: false, error: "Not signed in as admin (try /admin/login again)." };
  try {
    await upsertPlugin(plugin);
    revalidatePath("/admin");
    return { ok: true };
  } catch (e) {
    return { ok: false, error: toMessage(e) };
  }
}

export async function reviewSubmissionAction(id: string): Promise<ActionResult> {
  if (!(await isAdmin())) return { ok: false, error: "Not signed in as admin (try /admin/login again)." };
  try {
    await markSubmissionReviewed(id);
    revalidatePath("/admin");
    return { ok: true };
  } catch (e) {
    return { ok: false, error: toMessage(e) };
  }
}
