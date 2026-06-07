import { z } from "zod";
import { webSearchObject } from "./ai";

const schema = z.object({
  approved: z
    .boolean()
    .describe("true only if this is a real, identifiable WordPress plugin that belongs in the directory"),
  reason: z.string().describe("one short sentence explaining the decision"),
  canonicalName: z.string().describe("the plugin's correct official name (empty string if unknown)"),
  canonicalSlug: z
    .string()
    .describe(
      'the plugin\'s canonical slug as "folder/main-plugin-file.php" (e.g. "wordpress-seo/wp-seo.php"). ' +
        "The folder is the wordpress.org slug; the main file is the PHP file in the plugin folder's root that " +
        'carries the "Plugin Name:" header. Determine the exact file name from the plugin\'s public code — e.g. ' +
        "browse https://plugins.trac.wordpress.org/browser/<slug>/trunk — do NOT guess the file name. Empty string if it can't be determined."
    ),
});

export interface ModerationResult {
  approved: boolean;
  reason: string;
  canonicalName?: string;
  canonicalSlug?: string;
}

/**
 * AI moderator for a public plugin submission, using GPT-5 + web search. Confirms
 * the plugin is real and, when it is, returns its canonical name + "folder/main.php"
 * slug (read from the plugin's public code). Fails closed (not approved) if the
 * model is unavailable or errors.
 */
export async function moderateSubmission(input: {
  name: string;
  slug: string;
  link: string;
}): Promise<ModerationResult> {
  const prompt = `You are the moderator for a public directory of real WordPress plugins. A user submitted a plugin to be listed.

Submission:
- Name: "${input.name}"
- Slug: "${input.slug}"
- Link: ${input.link}

Step 1 — decide whether to APPROVE it. Approve ONLY if, verified against current web sources, this is a real WordPress plugin that exists and is known (not spam, fake, or unverifiable): the name is correct, the slug is believable, and the link goes to a site that actually describes this plugin.

Step 2 — if (and only if) it's a real plugin, determine its CANONICAL identity from public sources:
- canonicalName: the plugin's official name.
- canonicalSlug: "folder/main-plugin-file.php". The folder is the wordpress.org slug. The main file is the PHP file in the plugin's trunk root that has the "Plugin Name:" header — look at the plugin's public code (e.g. https://plugins.trac.wordpress.org/browser/<slug>/trunk, or its wordpress.org page) to find the EXACT file name. Do not guess it. For a commercial plugin not on wordpress.org, give your best-known folder/main file.

Return approved, a one-sentence reason, canonicalName, and canonicalSlug.`;

  const res = await webSearchObject({ prompt, schema });
  if (!res.ok) {
    return { approved: false, reason: "Couldn't verify the submission automatically — please try again later." };
  }
  return {
    approved: res.object.approved,
    reason: res.object.reason,
    canonicalName: res.object.canonicalName?.trim() || undefined,
    canonicalSlug: res.object.canonicalSlug?.trim() || undefined,
  };
}
