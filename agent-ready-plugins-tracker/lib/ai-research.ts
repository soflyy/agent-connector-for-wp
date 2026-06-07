import { generateObject } from "ai";
import { z } from "zod";
import type { AIResearchResult } from "./types";

// Model routed through the Vercel AI Gateway (uses AI_GATEWAY_API_KEY). Perplexity
// Sonar performs live web search with citations, so a single call both researches
// and returns structured output. Override with AI_RESEARCH_MODEL.
const MODEL = process.env.AI_RESEARCH_MODEL || "perplexity/sonar";

const schema = z.object({
  pluginIncludesOfficialAbilities: z
    .boolean()
    .describe(
      "true if the plugin itself ships first-party AI abilities (e.g. via the WordPress Abilities API / MCP Adapter or its own MCP server). " +
        "false if there is no shipped first-party support (including when official support is only announced, not yet released)."
    ),
  thirdPartyAbilitiesProvidedBy: z
    .array(
      z.object({
        pluginName: z.string().describe("Name of the third-party plugin that adds AI abilities for this plugin."),
        pluginSlug: z.string().describe("Its wordpress.org slug or plugin folder, if known (else empty)."),
        pluginLink: z.string().describe("URL to that third-party plugin."),
      })
    )
    .describe("Third-party / unofficial plugins that provide AI abilities or an MCP server for this plugin (empty if none)."),
});

/**
 * Research a WordPress plugin via live web search and determine two things:
 * whether it now ships official abilities, and which third-party plugins provide
 * abilities for it. Throws if the gateway isn't configured or the call fails.
 */
export async function researchPlugin(slug: string, name: string): Promise<AIResearchResult> {
  if (!process.env.AI_GATEWAY_API_KEY) {
    throw new Error("AI_GATEWAY_API_KEY is not set");
  }

  const prompt = `You are researching the WordPress plugin "${name}" (slug "${slug}") as of today.

Determine, using current web sources, exactly two things:
1. Does the plugin ship its OWN first-party AI abilities (e.g. via the WordPress Abilities API introduced in WP 6.9, the WordPress MCP Adapter, or its own MCP server)? Be conservative — only say true with a credible first-party source. Announced-but-not-shipped support counts as false.
2. Are there THIRD-PARTY / unofficial plugins or MCP servers that add AI abilities for this plugin? List each with its name, slug, and URL.`;

  const { object } = await generateObject({
    model: MODEL,
    schema,
    prompt,
    temperature: 0,
  });

  return {
    pluginIncludesOfficialAbilities: object.pluginIncludesOfficialAbilities,
    thirdPartyAbilitiesProvidedBy: (object.thirdPartyAbilitiesProvidedBy ?? []).map((u) => ({
      pluginName: u.pluginName,
      pluginSlug: u.pluginSlug,
      pluginLink: u.pluginLink,
    })),
  };
}
