import { generateObject } from "ai";
import { z } from "zod";

const MODEL = process.env.AI_RESEARCH_MODEL || "perplexity/sonar";

const schema = z.object({
  approved: z
    .boolean()
    .describe("true only if this is a real, identifiable WordPress plugin that belongs in the directory"),
  reason: z.string().describe("one short sentence explaining the decision"),
});

export interface ModerationResult {
  approved: boolean;
  reason: string;
}

/**
 * AI moderator for a public plugin submission. Uses a web-search-capable model to
 * confirm the plugin is real before it gets listed. Fails closed (not approved)
 * if the gateway isn't configured or the call errors.
 */
export async function moderateSubmission(input: {
  name: string;
  slug: string;
  link: string;
}): Promise<ModerationResult> {
  if (!process.env.AI_GATEWAY_API_KEY) {
    return { approved: false, reason: "Moderation is unavailable right now — please try again later." };
  }

  const prompt = `You are the moderator for a public directory of real WordPress plugins. A user submitted a plugin to be listed. Decide whether to approve it.

Submission:
- Name: "${input.name}"
- Slug: "${input.slug}"
- Link: ${input.link}

Approve ONLY if ALL of these hold, verified against current web sources:
- The name is correct for a real WordPress plugin.
- The slug is believable (it looks like a real wordpress.org slug, or the plugin folder/main file, for this plugin).
- The link goes to a valid website that actually describes this plugin (its wordpress.org page, its commercial site, or its source repo).
- This is a real WordPress plugin that exists and that people have heard of — not spam, not invented, not a placeholder.

Reject anything spammy, fake, unverifiable, or that you cannot confirm is a real WordPress plugin. Return approved (boolean) and a one-sentence reason.`;

  try {
    const { object } = await generateObject({ model: MODEL, schema, prompt, temperature: 0 });
    return { approved: object.approved, reason: object.reason };
  } catch {
    return { approved: false, reason: "Couldn't verify the submission automatically — please try again later." };
  }
}
