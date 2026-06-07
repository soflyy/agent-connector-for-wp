import { z } from "zod";
import type { AIResearchResult } from "./types";
import { webSearchObject, type WebSearchDebug } from "./ai";

const schema = z.object({
  pluginIncludesOfficialAbilities: z
    .boolean()
    .describe(
      "true ONLY if the plugin itself (first-party) registers capabilities through the WordPress Abilities API that are exposed to AI agents over MCP. " +
        "Generic AI features (chatbots, content/copy generation, recommendations) do NOT count. " +
        "false if that MCP-accessible WP-Abilities support is not actually shipped (announced-but-unreleased = false)."
    ),
  thirdPartyAbilitiesProvidedBy: z
    .array(
      z.object({
        pluginName: z.string().describe("Name of the third-party plugin that registers WP Abilities (over MCP) to control the source plugin."),
        pluginSlug: z.string().describe("Its wordpress.org slug or plugin folder, if known (else empty)."),
        pluginLink: z.string().describe("URL to that third-party plugin."),
      })
    )
    .describe(
      "Third-party plugins that register WordPress Abilities (via the WP Abilities API) exposed over MCP specifically to control the SOURCE plugin. " +
        "Exclude any plugin that only adds generic AI functionality and does not expose MCP abilities for the source plugin. Empty array if none qualify."
    ),
});

// Re-exported so callers (ai-check) keep a stable type name.
export type ResearchDebug = WebSearchDebug;

export type ResearchOutcome =
  | { ok: true; result: AIResearchResult; debug: ResearchDebug }
  | { ok: false; error: string; debug: ResearchDebug };

function buildPrompt(slug: string, name: string): string {
  return `You are assessing the WordPress plugin "${name}" (slug "${slug}") as of today, using current web sources.

The ONLY thing that matters is the WordPress Abilities API + MCP. Definitions — be strict:
- An "ability" = a capability registered through the WordPress Abilities API (e.g. wp_register_ability(), introduced in WP 6.9) that is exposed to AI agents over MCP (the Model Context Protocol) — via the WordPress MCP Adapter or an MCP server — so an agent can programmatically read/control the plugin.
- Generic "AI features" DO NOT COUNT: AI chatbots, AI copy/content generation, product recommendations, AI search, etc., are NOT abilities unless they register MCP-accessible abilities via the WP Abilities API to control the plugin.

Determine exactly two things:
1. pluginIncludesOfficialAbilities — Does "${name}" ITSELF (first-party) register WordPress Abilities exposed over MCP to control it? Only true with a credible first-party source showing this is actually shipped (not merely announced).
2. thirdPartyAbilitiesProvidedBy — Which THIRD-PARTY plugins register WordPress Abilities (over MCP), via the WP Abilities API, specifically to control "${name}"? Include a plugin ONLY if it adds MCP-accessible abilities to control "${name}". Exclude anything that only adds generic AI functionality. If none qualify, return an empty array — empty is the expected answer for most plugins.`;
}

/**
 * Research a plugin's WP-Abilities/MCP status with GPT-5 + web search. Never
 * throws — returns a typed outcome carrying the full debug payload for the admin UI.
 */
export async function researchPlugin(slug: string, name: string): Promise<ResearchOutcome> {
  const res = await webSearchObject({ prompt: buildPrompt(slug, name), schema });
  if (!res.ok) {
    return { ok: false, error: res.error, debug: res.debug };
  }
  const o = res.object;
  return {
    ok: true,
    result: {
      pluginIncludesOfficialAbilities: o.pluginIncludesOfficialAbilities,
      thirdPartyAbilitiesProvidedBy: (o.thirdPartyAbilitiesProvidedBy ?? []).map((u) => ({
        pluginName: u.pluginName,
        pluginSlug: u.pluginSlug,
        pluginLink: u.pluginLink,
      })),
    },
    debug: res.debug,
  };
}
