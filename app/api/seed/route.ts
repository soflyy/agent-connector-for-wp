import { NextResponse } from "next/server";
import { upsertPlugin, setAIStatus } from "@/lib/db";
import { PLUGINS } from "@/data/plugins";
import { AI_STATUS_SEED } from "@/data/ai-status-seed";

export async function GET() {
  const pluginResults: Record<string, "ok" | "error"> = {};
  for (const plugin of PLUGINS) {
    try {
      await upsertPlugin(plugin);
      pluginResults[plugin.slug] = "ok";
    } catch {
      pluginResults[plugin.slug] = "error";
    }
  }

  const statusResults: Record<string, "ok" | "error"> = {};
  for (const [slug, status] of Object.entries(AI_STATUS_SEED)) {
    try {
      await setAIStatus(slug, status);
      statusResults[slug] = "ok";
    } catch {
      statusResults[slug] = "error";
    }
  }

  return NextResponse.json({
    plugins: { seeded: Object.keys(pluginResults).length, results: pluginResults },
    statuses: { seeded: Object.keys(statusResults).length, results: statusResults },
  });
}
