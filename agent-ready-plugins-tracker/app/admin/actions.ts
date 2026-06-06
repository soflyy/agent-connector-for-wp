"use server";

import { revalidatePath } from "next/cache";
import { isAdmin } from "@/lib/admin-auth";
import { setAIStatus, upsertPlugin, markSubmissionReviewed } from "@/lib/db";
import type { AIStatus, Plugin } from "@/lib/types";

async function assertAdmin() {
  if (!(await isAdmin())) throw new Error("Unauthorized");
}

export async function saveStatusAction(slug: string, status: AIStatus): Promise<void> {
  await assertAdmin();
  await setAIStatus(slug, { ...status, lastVerified: new Date().toISOString().slice(0, 10) });
  revalidatePath("/admin");
  revalidatePath(`/admin/plugins/${slug}`);
}

export async function addPluginAction(plugin: Plugin): Promise<void> {
  await assertAdmin();
  await upsertPlugin(plugin);
  revalidatePath("/admin");
}

export async function reviewSubmissionAction(id: string): Promise<void> {
  await assertAdmin();
  await markSubmissionReviewed(id);
  revalidatePath("/admin");
}
