import type { Metadata } from "next";
import { PLUGINS } from "@/data/plugins";
import { AI_STATUS_SEED } from "@/data/ai-status-seed";
import { getAllAIStatuses } from "@/lib/db";
import { PluginDirectory } from "@/components/PluginDirectory";
import type { PluginWithStatus } from "@/lib/types";

export const metadata: Metadata = {
  title: "Plugin Directory — WP AI Ready",
  description:
    "Browse all tracked WordPress plugins and their AI Abilities / MCP integration status.",
};

export const revalidate = 3600;

export default async function PluginsPage() {
  const kvStatuses = await getAllAIStatuses(PLUGINS.map((p) => p.slug));

  const plugins: PluginWithStatus[] = PLUGINS.map((p) => ({
    ...p,
    aiStatus: kvStatuses[p.slug] ?? AI_STATUS_SEED[p.slug] ?? {
      level: "none" as const,
      unofficialPlugins: [],
      lastVerified: "unknown",
    },
  }));

  // Sort: official → unofficial → coming_soon → none, then alphabetically
  const ORDER: Record<string, number> = {
    official: 0,
    unofficial: 1,
    coming_soon: 2,
    none: 3,
  };
  plugins.sort((a, b) => {
    const diff = ORDER[a.aiStatus.level] - ORDER[b.aiStatus.level];
    return diff !== 0 ? diff : a.name.localeCompare(b.name);
  });

  return (
    <main className="mx-auto max-w-6xl px-4 py-10">
      <div className="mb-8">
        <h1 className="text-3xl font-bold text-slate-900">Plugin Directory</h1>
        <p className="mt-2 text-slate-600">
          {plugins.length} plugins tracked · AI status verified by the community
        </p>
      </div>
      <PluginDirectory plugins={plugins} />
    </main>
  );
}
