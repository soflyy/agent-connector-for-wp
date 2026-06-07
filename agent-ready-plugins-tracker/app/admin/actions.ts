"use server";

import { revalidatePath } from "next/cache";
import { isAdmin } from "@/lib/admin-auth";
import { deletePlugin, upsertPlugin } from "@/lib/db";
import type { Plugin } from "@/lib/types";

export type ActionResult = { ok: true } | { ok: false; error: string };

function toMessage(e: unknown): string {
  if (e && typeof e === "object" && "message" in e) {
    return String((e as { message: unknown }).message);
  }
  return typeof e === "string" ? e : "Unknown error";
}

export async function savePluginAction(plugin: Plugin): Promise<ActionResult> {
  if (!(await isAdmin())) return { ok: false, error: "Not signed in as admin (try /admin/login again)." };
  try {
    await upsertPlugin(plugin);
    revalidatePath("/admin");
    revalidatePath(`/admin/plugins/${plugin.urlSlug ?? ""}`);
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

export async function deletePluginAction(slug: string): Promise<ActionResult> {
  if (!(await isAdmin())) return { ok: false, error: "Not signed in as admin (try /admin/login again)." };
  try {
    await deletePlugin(slug);
    revalidatePath("/admin");
    return { ok: true };
  } catch (e) {
    return { ok: false, error: toMessage(e) };
  }
}
