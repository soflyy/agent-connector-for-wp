import { generateObject } from "ai";
import { z } from "zod";
import type { AISuggestion } from "./types";

// Model routed through the Vercel AI Gateway (uses AI_GATEWAY_API_KEY). Perplexity
// Sonar performs live web search with citations, so a single call both researches
// and returns structured output. Override with AI_RESEARCH_MODEL.
const MODEL = process.env.AI_RESEARCH_MODEL || "perplexity/sonar";

const schema = z.object({
  level: z
    .enum(["official", "unofficial", "coming_soon", "none"])
    .describe(
      "official = the plugin ships its own AI abilities (e.g. via the WordPress Abilities API / MCP). " +
        "unofficial = no first-party support, but a third-party plugin/extension adds AI abilities or an MCP server for it. " +
        "coming_soon = official support publicly announced but not yet shipped. none = no known AI ability support."
    ),
  officialSince: z.string().optional().describe("Plugin version that introduced official AI abilities, if official."),
  officialDocsUrl: z.string().optional().describe("URL to official docs/announcement/roadmap."),
  abilitiesCount: z.number().int().optional().describe("Approximate number of official abilities, if known."),
  abilities: z.array(z.string()).describe("Names of official abilities, if any (empty if none)."),
  unofficialPlugins: z
    .array(
      z.object({
        name: z.string(),
        pluginUrl: z.string().describe("URL to the third-party extension / MCP server."),
        description: z.string(),
        author: z.string(),
        authorUrl: z.string().optional(),
      })
    )
    .describe("Third-party (unofficial) extensions that add AI abilities or an MCP server for this plugin."),
  notes: z.string().optional().describe("One or two sentences of context."),
  sources: z.array(z.string()).describe("URLs of the sources used to determine this status."),
  confidence: z.enum(["high", "medium", "low"]),
});

/**
 * Research a WordPress plugin's current AI-ability status via live web search,
 * returning typed, citation-backed data. Throws if the gateway isn't configured
 * or the model call fails.
 */
export async function researchPluginStatus(slug: string, name: string): Promise<AISuggestion> {
  if (!process.env.AI_GATEWAY_API_KEY) {
    throw new Error("AI_GATEWAY_API_KEY is not set");
  }

  const prompt = `You are researching whether the WordPress plugin "${name}" (wordpress.org slug "${slug}") supports AI "abilities" as of today.

Determine, using current web sources:
- Does the plugin ship FIRST-PARTY AI abilities (e.g. via the WordPress Abilities API introduced in WP 6.9, the WordPress MCP Adapter, or its own MCP server)? If so, which version added them, link the docs, and list the abilities.
- Is there official support only ANNOUNCED but not yet released (coming_soon)?
- Otherwise, are there THIRD-PARTY/unofficial plugins or MCP servers that add AI abilities for this plugin? List each with its URL and author.
- If you find nothing credible, use level "none".

Be conservative: only claim "official" with a credible first-party source. Always include the source URLs you relied on, and a confidence level.`;

  const { object } = await generateObject({
    model: MODEL,
    schema,
    prompt,
    temperature: 0,
  });

  return {
    level: object.level,
    officialSince: object.officialSince,
    officialDocsUrl: object.officialDocsUrl,
    abilitiesCount: object.abilitiesCount,
    abilities: object.abilities ?? [],
    unofficialPlugins: object.unofficialPlugins ?? [],
    notes: object.notes,
    sources: object.sources ?? [],
    confidence: object.confidence,
    checkedAt: new Date().toISOString(),
  };
}
