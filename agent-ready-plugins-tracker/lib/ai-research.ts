import { generateObject } from "ai";
import { z } from "zod";
import type { AIResearchResult } from "./types";

// Model routed through the Vercel AI Gateway (uses AI_GATEWAY_API_KEY). Perplexity
// Sonar performs live web search with citations, so a single call both researches
// and returns structured output. Override with AI_RESEARCH_MODEL.
const MODEL = process.env.AI_RESEARCH_MODEL || "perplexity/sonar";
const TEMPERATURE = 0;

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
 * Everything sent to and returned from the LLM for one research call — surfaced
 * in the admin UI so a re-check is fully inspectable. `request`/`rawResponse`
 * come straight from the AI SDK (the actual provider HTTP bodies).
 */
export interface ResearchDebug {
  model: string;
  temperature: number;
  prompt: string;
  request?: unknown;
  rawResponse?: unknown;
  object?: AIResearchResult;
  usage?: unknown;
  finishReason?: string;
  warnings?: unknown;
}

export type ResearchOutcome =
  | { ok: true; result: AIResearchResult; debug: ResearchDebug }
  | { ok: false; error: string; debug: ResearchDebug };

function buildPrompt(slug: string, name: string): string {
  return `You are researching the WordPress plugin "${name}" (slug "${slug}") as of today.

Determine, using current web sources, exactly two things:
1. Does the plugin ship its OWN first-party AI abilities (e.g. via the WordPress Abilities API introduced in WP 6.9, the WordPress MCP Adapter, or its own MCP server)? Be conservative — only say true with a credible first-party source. Announced-but-not-shipped support counts as false.
2. Are there THIRD-PARTY / unofficial plugins or MCP servers that add AI abilities for this plugin? List each with its name, slug, and URL.`;
}

/**
 * Research a WordPress plugin via live web search and determine two things:
 * whether it now ships official abilities, and which third-party plugins provide
 * abilities for it. Never throws — returns a typed outcome carrying the full
 * debug payload (prompt, params, raw request + response) for the admin UI.
 */
export async function researchPlugin(slug: string, name: string): Promise<ResearchOutcome> {
  const prompt = buildPrompt(slug, name);
  const debug: ResearchDebug = { model: MODEL, temperature: TEMPERATURE, prompt };

  if (!process.env.AI_GATEWAY_API_KEY) {
    return { ok: false, error: "AI_GATEWAY_API_KEY is not set", debug };
  }

  try {
    const gen = await generateObject({ model: MODEL, schema, prompt, temperature: TEMPERATURE });
    debug.request = gen.request?.body;
    debug.rawResponse = gen.response?.body;
    debug.object = gen.object;
    debug.usage = gen.usage;
    debug.finishReason = gen.finishReason;
    debug.warnings = gen.warnings;

    const result: AIResearchResult = {
      pluginIncludesOfficialAbilities: gen.object.pluginIncludesOfficialAbilities,
      thirdPartyAbilitiesProvidedBy: (gen.object.thirdPartyAbilitiesProvidedBy ?? []).map((u) => ({
        pluginName: u.pluginName,
        pluginSlug: u.pluginSlug,
        pluginLink: u.pluginLink,
      })),
    };
    return { ok: true, result, debug };
  } catch (e) {
    // The AI SDK attaches the raw request/response (and the model's text, when it
    // returned something unparseable) to the thrown error — surface it.
    const err = e as { request?: { body?: unknown }; response?: { body?: unknown }; text?: unknown; cause?: { message?: string } };
    debug.request = err?.request?.body ?? debug.request;
    debug.rawResponse = err?.response?.body ?? err?.text ?? null;
    return {
      ok: false,
      error: e instanceof Error ? e.message : String(e),
      debug,
    };
  }
}
