import Link from "next/link";
import { PLUGINS } from "@/data/plugins";
import { AI_STATUS_SEED } from "@/data/ai-status-seed";
import { getAllAIStatuses } from "@/lib/kv";
import { StatusBadge } from "@/components/StatusBadge";
import type { PluginWithStatus } from "@/lib/types";

export const revalidate = 3600;

async function getPluginsWithStatus(): Promise<PluginWithStatus[]> {
  const kvStatuses = await getAllAIStatuses(PLUGINS.map((p) => p.slug));
  return PLUGINS.map((p) => ({
    ...p,
    aiStatus: kvStatuses[p.slug] ?? AI_STATUS_SEED[p.slug] ?? {
      level: "none" as const,
      unofficialPlugins: [],
      lastVerified: "unknown",
    },
  }));
}

export default async function HomePage() {
  const plugins = await getPluginsWithStatus();

  const counts = {
    total: plugins.length,
    official: plugins.filter((p) => p.aiStatus.level === "official").length,
    unofficial: plugins.filter((p) => p.aiStatus.level === "unofficial").length,
    coming_soon: plugins.filter((p) => p.aiStatus.level === "coming_soon").length,
    none: plugins.filter((p) => p.aiStatus.level === "none").length,
  };

  const featuredOfficial = plugins.filter((p) => p.aiStatus.level === "official").slice(0, 3);
  const featuredUnofficial = plugins.filter((p) => p.aiStatus.level === "unofficial").slice(0, 3);

  return (
    <main>
      {/* Hero */}
      <section className="bg-white border-b border-slate-200">
        <div className="mx-auto max-w-6xl px-4 py-16 text-center">
          <div className="mb-4 inline-flex items-center gap-2 rounded-full bg-wp-blue/10 px-4 py-1.5 text-sm font-medium text-wp-blue">
            <span className="h-2 w-2 rounded-full bg-wp-blue" />
            Community-maintained directory
          </div>
          <h1 className="mb-4 text-4xl font-bold tracking-tight text-slate-900 sm:text-5xl">
            Which WordPress plugins are{" "}
            <span className="text-wp-blue">AI ready?</span>
          </h1>
          <p className="mx-auto mb-8 max-w-2xl text-lg text-slate-600">
            Track which plugins have shipped official{" "}
            <strong>WordPress AI Abilities</strong>, which have community
            MCP adapters, and which are still waiting.
          </p>
          <div className="flex flex-col items-center gap-3 sm:flex-row sm:justify-center">
            <Link
              href="/plugins"
              className="rounded-lg bg-wp-blue px-6 py-3 font-semibold text-white transition-colors hover:bg-wp-blue-dark"
            >
              Browse the directory
            </Link>
            <Link
              href="/submit"
              className="rounded-lg border border-slate-300 bg-white px-6 py-3 font-semibold text-slate-700 transition-colors hover:bg-slate-50"
            >
              Submit an update
            </Link>
          </div>
        </div>
      </section>

      {/* Stats */}
      <section className="mx-auto max-w-6xl px-4 py-10">
        <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
          {[
            { label: "Plugins tracked", value: counts.total, color: "text-slate-900" },
            { label: "Official AI", value: counts.official, color: "text-emerald-600" },
            { label: "Unofficial available", value: counts.unofficial, color: "text-amber-600" },
            { label: "Not yet", value: counts.none, color: "text-slate-400" },
          ].map(({ label, value, color }) => (
            <div
              key={label}
              className="rounded-xl border border-slate-200 bg-white p-5 text-center shadow-sm"
            >
              <p className={`text-3xl font-bold ${color}`}>{value}</p>
              <p className="mt-1 text-sm text-slate-500">{label}</p>
            </div>
          ))}
        </div>
      </section>

      {/* What is this? */}
      <section className="mx-auto max-w-6xl px-4 pb-10">
        <div className="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
          <h2 className="mb-4 text-lg font-semibold text-slate-900">What does "AI ready" mean?</h2>
          <div className="grid gap-4 sm:grid-cols-3">
            {[
              {
                level: "official" as const,
                title: "Official AI Abilities",
                body: "The plugin's own developers have registered WordPress AI Abilities — structured actions that the WordPress AI block and third-party AI tools can invoke.",
              },
              {
                level: "unofficial" as const,
                title: "Community Plugin Available",
                body: "A third-party WordPress plugin registers Abilities or an MCP server on behalf of this plugin. Useful now; can be deprecated once the dev ships official support.",
              },
              {
                level: "coming_soon" as const,
                title: "Coming Soon",
                body: "The developer has publicly announced AI Abilities support but hasn't shipped it yet. Check back or follow the linked roadmap.",
              },
            ].map(({ level, title, body }) => (
              <div key={level} className="flex flex-col gap-2">
                <StatusBadge level={level} />
                <h3 className="font-medium text-slate-900">{title}</h3>
                <p className="text-sm text-slate-600">{body}</p>
              </div>
            ))}
          </div>
        </div>
      </section>

      {/* Featured: Official */}
      {featuredOfficial.length > 0 && (
        <section className="mx-auto max-w-6xl px-4 pb-10">
          <div className="mb-4 flex items-center justify-between">
            <h2 className="text-xl font-semibold text-slate-900">
              Official AI Abilities
            </h2>
            <Link
              href="/plugins?status=official"
              className="text-sm text-wp-blue hover:underline"
            >
              View all →
            </Link>
          </div>
          <div className="grid gap-4 sm:grid-cols-3">
            {featuredOfficial.map((plugin) => (
              <Link
                key={plugin.slug}
                href={`/plugins/${plugin.slug}`}
                className="group rounded-xl border border-emerald-200 bg-white p-4 shadow-sm transition-all hover:border-emerald-400 hover:shadow-md"
              >
                <div className="mb-1 flex items-center justify-between">
                  <h3 className="font-semibold text-slate-900 group-hover:text-wp-blue">
                    {plugin.name}
                  </h3>
                  <StatusBadge level="official" size="sm" />
                </div>
                <p className="text-sm text-slate-500">{plugin.tagline}</p>
                {plugin.aiStatus.abilitiesCount && (
                  <p className="mt-2 text-xs font-medium text-emerald-600">
                    {plugin.aiStatus.abilitiesCount} abilities registered
                  </p>
                )}
              </Link>
            ))}
          </div>
        </section>
      )}

      {/* Featured: Unofficial */}
      {featuredUnofficial.length > 0 && (
        <section className="mx-auto max-w-6xl px-4 pb-16">
          <div className="mb-4 flex items-center justify-between">
            <h2 className="text-xl font-semibold text-slate-900">
              Community Plugins Available
            </h2>
            <Link
              href="/plugins?status=unofficial"
              className="text-sm text-wp-blue hover:underline"
            >
              View all →
            </Link>
          </div>
          <div className="grid gap-4 sm:grid-cols-3">
            {featuredUnofficial.map((plugin) => (
              <Link
                key={plugin.slug}
                href={`/plugins/${plugin.slug}`}
                className="group rounded-xl border border-amber-200 bg-white p-4 shadow-sm transition-all hover:border-amber-400 hover:shadow-md"
              >
                <div className="mb-1 flex items-center justify-between">
                  <h3 className="font-semibold text-slate-900 group-hover:text-wp-blue">
                    {plugin.name}
                  </h3>
                  <StatusBadge level="unofficial" size="sm" />
                </div>
                <p className="text-sm text-slate-500">{plugin.tagline}</p>
                <p className="mt-2 text-xs font-medium text-amber-600">
                  {plugin.aiStatus.unofficialPlugins.length} community plugin
                  {plugin.aiStatus.unofficialPlugins.length !== 1 ? "s" : ""} available
                </p>
              </Link>
            ))}
          </div>
        </section>
      )}
    </main>
  );
}
