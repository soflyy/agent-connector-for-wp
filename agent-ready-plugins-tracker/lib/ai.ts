import { openai } from "@ai-sdk/openai";
import { generateText, Output } from "ai";
import type { z } from "zod";

// GPT-5 with OpenAI's built-in web_search tool, routed through the Vercel AI
// Gateway (a bare model-id string resolves via the gateway; auth is
// AI_GATEWAY_API_KEY). Override the model with AI_RESEARCH_MODEL.
export const RESEARCH_MODEL = process.env.AI_RESEARCH_MODEL || "openai/gpt-5";

/** Everything sent to / returned from the model for one call — surfaced in admin. */
export interface WebSearchDebug {
  model: string;
  prompt: string;
  request?: unknown;
  rawResponse?: unknown;
  object?: unknown;
  sources?: unknown;
  usage?: unknown;
  finishReason?: string;
  warnings?: unknown;
}

export type WebSearchResult<T> =
  | { ok: true; object: T; debug: WebSearchDebug }
  | { ok: false; error: string; debug: WebSearchDebug };

/**
 * Run one web-search-grounded, structured-output call: the model may use OpenAI's
 * server-side web_search tool, then returns an object matching `schema`. Never
 * throws — returns a typed result carrying the full debug payload.
 */
export async function webSearchObject<T>(opts: {
  prompt: string;
  schema: z.ZodType<T>;
  model?: string;
}): Promise<WebSearchResult<T>> {
  const model = opts.model || RESEARCH_MODEL;
  const debug: WebSearchDebug = { model, prompt: opts.prompt };

  if (!process.env.AI_GATEWAY_API_KEY) {
    return { ok: false, error: "AI_GATEWAY_API_KEY is not set", debug };
  }

  try {
    const result = await generateText({
      model, // bare id ("openai/gpt-5") → resolved via the Vercel AI Gateway
      tools: { web_search: openai.tools.webSearch({}) },
      prompt: opts.prompt,
      experimental_output: Output.object({ schema: opts.schema }),
    });
    debug.request = result.request?.body;
    debug.rawResponse = result.response?.body;
    debug.sources = result.sources;
    debug.usage = result.usage;
    debug.finishReason = result.finishReason;
    debug.warnings = result.warnings;

    const object = result.experimental_output as T | undefined;
    debug.object = object;
    if (object === undefined || object === null) {
      return { ok: false, error: "The model returned no structured output.", debug };
    }
    return { ok: true, object, debug };
  } catch (e) {
    const err = e as { request?: { body?: unknown }; response?: { body?: unknown }; text?: unknown };
    debug.request = err?.request?.body ?? debug.request;
    debug.rawResponse = err?.response?.body ?? err?.text ?? null;
    return { ok: false, error: e instanceof Error ? e.message : String(e), debug };
  }
}
