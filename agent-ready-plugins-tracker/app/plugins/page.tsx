import type { Metadata } from "next";
import { getAllPluginsWithStatus } from "@/lib/db";
import { PluginDirectory } from "@/components/PluginDirectory";

export const metadata: Metadata = {
  title: "Plugin Directory — WP AI Ready",
  description: "Browse all tracked WordPress plugins and their AI Abilities / MCP integration status.",
};

export const revalidate = 3600;

export default async function PluginsPage() {
  const plugins = await getAllPluginsWithStatus();

  const ORDER: Record<string, number> = { official: 0, unofficial: 1, none: 2 };
  plugins.sort((a, b) => {
    const diff = (ORDER[a.aiStatus.level] ?? 99) - (ORDER[b.aiStatus.level] ?? 99);
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
