import { researchPlugin, type ResearchDebug } from "./ai-research";
import { applyAiResult, getPluginsToCheck, getPlugin } from "./db";

export interface CheckOutcome {
  slug: string;
  ok: boolean;
  includesAbilities?: boolean;
  thirdPartyCount?: number;
  error?: string;
  /** Full prompt / params / raw request + response for the admin UI. */
  debug?: ResearchDebug;
}

/** Research one plugin and apply the result. */
export async function checkOne(slug: string, name: string): Promise<CheckOutcome> {
  const outcome = await researchPlugin(slug, name);
  if (!outcome.ok) {
    return { slug, ok: false, error: outcome.error, debug: outcome.debug };
  }
  try {
    await applyAiResult(slug, outcome.result);
  } catch (e) {
    return { slug, ok: false, error: e instanceof Error ? e.message : String(e), debug: outcome.debug };
  }
  return {
    slug,
    ok: true,
    includesAbilities: outcome.result.pluginIncludesOfficialAbilities,
    thirdPartyCount: outcome.result.thirdPartyAbilitiesProvidedBy.length,
    debug: outcome.debug,
  };
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
      // Drop the verbose debug payload from batch results to keep them small.
      const { debug: _debug, ...rest } = await checkOne(t.slug, t.name);
      out.push(rest);
    }
  }
  await Promise.all(Array.from({ length: Math.min(CONCURRENCY, targets.length) }, worker));
  return out;
}
