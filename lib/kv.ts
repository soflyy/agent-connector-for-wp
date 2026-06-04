import type { AIStatus, Submission } from "./types";

// Gracefully handle missing KV env vars (local dev without KV set up)
async function getKv() {
  if (!process.env.KV_REST_API_URL || !process.env.KV_REST_API_TOKEN) {
    return null;
  }
  const { kv } = await import("@vercel/kv");
  return kv;
}

export async function getAIStatus(slug: string): Promise<AIStatus | null> {
  const kv = await getKv();
  if (!kv) return null;
  try {
    return await kv.get<AIStatus>(`aiStatus:${slug}`);
  } catch {
    return null;
  }
}

export async function getAllAIStatuses(
  slugs: string[]
): Promise<Record<string, AIStatus>> {
  const kv = await getKv();
  if (!kv || slugs.length === 0) return {};
  try {
    const keys = slugs.map((s) => `aiStatus:${s}`);
    const values = await kv.mget<AIStatus[]>(...keys);
    const result: Record<string, AIStatus> = {};
    slugs.forEach((slug, i) => {
      if (values[i]) result[slug] = values[i]!;
    });
    return result;
  } catch {
    return {};
  }
}

export async function setAIStatus(
  slug: string,
  status: AIStatus
): Promise<void> {
  const kv = await getKv();
  if (!kv) throw new Error("KV not configured");
  await kv.set(`aiStatus:${slug}`, status);
}

export async function addSubmission(submission: Submission): Promise<void> {
  const kv = await getKv();
  if (!kv) throw new Error("KV not configured");
  await kv.set(`submission:${submission.id}`, submission);
  // Track the submission ID in a list
  const existing = (await kv.get<string[]>("submissionIds")) ?? [];
  await kv.set("submissionIds", [...existing, submission.id]);
}

export async function getSubmissions(): Promise<Submission[]> {
  const kv = await getKv();
  if (!kv) return [];
  const ids = (await kv.get<string[]>("submissionIds")) ?? [];
  if (ids.length === 0) return [];
  const keys = ids.map((id) => `submission:${id}`);
  const values = await kv.mget<Submission[]>(...keys);
  return values.filter(Boolean) as Submission[];
}
