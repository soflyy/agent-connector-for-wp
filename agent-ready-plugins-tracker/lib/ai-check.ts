import { researchPlugin } from "./ai-research";
import { applyAiResult, getPluginsToCheck, getPlugin } from "./db";

export interface CheckOutcome {
  slug: string;
  ok: boolean;
  includesAbilities?: boolean;
  thirdPartyCount?: number;
  error?: string;
}

/** Research one plugin and apply the result. */
export async function checkOne(slug: string, name: string): Promise<CheckOutcome> {
  try {
    const result = await researchPlugin(slug, name);
    await applyAiResult(slug, result);
    return {
      slug,
      ok: true,
      includesAbilities: result.pluginIncludesOfficialAbilities,
      thirdPartyCount: result.thirdPartyAbilitiesProvidedBy.length,
    };
  } catch (e) {
    return { slug, ok: false, error: e instanceof Error ? e.message : String(e) };
  }
}

/** Look up a plugin's name and check it. */
export async function checkBySlug(slug: string): Promise<CheckOutcome> {
  const plugin = await getPlugin(slug);
  if (!plugin) return { slug, ok: false, error: "Plugin not found" };
  return checkOne(slug, plugin.name);
}

/** Check up to `limit` least-recently-checked plugins, bounded concurrency. */
export async function runBatch(limit: number): Promise<CheckOutcome[]> {
  const targets = await getPluginsToCheck(limit);
  const out: CheckOutcome[] = [];
  const CONCURRENCY = 3;
  let next = 0;
  async function worker() {
    while (next < targets.length) {
      const t = targets[next++];
      out.push(await checkOne(t.slug, t.name));
    }
  }
  await Promise.all(Array.from({ length: Math.min(CONCURRENCY, targets.length) }, worker));
  return out;
}
