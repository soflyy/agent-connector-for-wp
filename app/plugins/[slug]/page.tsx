import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";
import { PLUGINS } from "@/data/plugins";
import { getAIStatus } from "@/lib/db";
import { StatusBadge } from "@/components/StatusBadge";
import { CATEGORY_LABELS } from "@/lib/types";
import type { AIStatus } from "@/lib/types";

export const revalidate = 3600;

export async function generateStaticParams() {
  return PLUGINS.map((p) => ({ slug: p.slug }));
}

async function getStatus(slug: string): Promise<AIStatus> {
  return (
    (await getAIStatus(slug)) ?? {
      level: "none" as const,
      unofficialPlugins: [],
      lastVerified: "unknown",
    }
  );
}

export async function generateMetadata({
  params,
}: {
  params: Promise<{ slug: string }>;
}): Promise<Metadata> {
  const { slug } = await params;
  const plugin = PLUGINS.find((p) => p.slug === slug);
  if (!plugin) return {};
  return {
    title: `${plugin.name} AI Status — WP AI Ready`,
    description: `Does ${plugin.name} have WordPress AI Abilities or an MCP adapter? Check the latest status.`,
  };
}

export default async function PluginDetailPage({
  params,
}: {
  params: Promise<{ slug: string }>;
}) {
  const { slug } = await params;
  const plugin = PLUGINS.find((p) => p.slug === slug);
  if (!plugin) notFound();

  const aiStatus = await getStatus(slug);

  const statusHeadings: Record<string, { title: string; desc: string }> = {
    official: {
      title: "Official AI Abilities shipped",
      desc: "This plugin's developers have registered first-party WordPress AI Abilities.",
    },
    unofficial: {
      title: "Community plugin available",
      desc: "No official AI Abilities yet, but a third-party plugin fills the gap.",
    },
    coming_soon: {
      title: "AI Abilities coming soon",
      desc: "The developer has announced AI support but hasn't shipped it yet.",
    },
    none: {
      title: "No AI integration yet",
      desc: "This plugin hasn't shipped or announced AI Abilities. Know something? Submit an update.",
    },
  };

  const sh = statusHeadings[aiStatus.level];

  return (
    <main className="mx-auto max-w-4xl px-4 py-10">
      {/* Breadcrumb */}
      <nav className="mb-6 text-sm text-slate-500">
        <Link href="/plugins" className="hover:text-slate-700">
          Directory
        </Link>{" "}
        / <span className="text-slate-900">{plugin.name}</span>
      </nav>

      {/* Plugin header */}
      <div className="mb-8 rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
        <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
          <div>
            <div className="mb-2 flex flex-wrap items-center gap-2">
              <h1 className="text-2xl font-bold text-slate-900">{plugin.name}</h1>
              {plugin.isPremium && (
                <span className="rounded bg-purple-100 px-2 py-0.5 text-xs font-medium text-purple-700">
                  Premium
                </span>
              )}
            </div>
            <p className="text-slate-600">{plugin.tagline}</p>
            <div className="mt-3 flex flex-wrap gap-2">
              {plugin.categories.map((cat) => (
                <span
                  key={cat}
                  className="rounded bg-slate-100 px-2.5 py-0.5 text-sm text-slate-600"
                >
                  {CATEGORY_LABELS[cat]}
                </span>
              ))}
            </div>
          </div>
          <StatusBadge level={aiStatus.level} />
        </div>

        <div className="mt-4 flex flex-wrap items-center gap-4 border-t border-slate-100 pt-4 text-sm text-slate-500">
          <span>{plugin.activeInstalls} active installs</span>
          {plugin.author && (
            <span>
              by{" "}
              {plugin.authorUrl ? (
                <a
                  href={plugin.authorUrl}
                  className="text-wp-blue hover:underline"
                  target="_blank"
                  rel="noopener noreferrer"
                >
                  {plugin.author}
                </a>
              ) : (
                plugin.author
              )}
            </span>
          )}
          {plugin.wpOrgUrl && (
            <a
              href={plugin.wpOrgUrl}
              className="text-wp-blue hover:underline"
              target="_blank"
              rel="noopener noreferrer"
            >
              WordPress.org →
            </a>
          )}
        </div>
      </div>

      {/* AI Status */}
      <div className="mb-6 rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
        <h2 className="mb-1 text-lg font-semibold text-slate-900">{sh.title}</h2>
        <p className="mb-4 text-slate-600">{sh.desc}</p>

        {/* Official details */}
        {aiStatus.level === "official" && (
          <div className="space-y-4">
            <div className="rounded-lg bg-emerald-50 p-4">
              <div className="flex flex-wrap gap-4 text-sm">
                {aiStatus.officialSince && (
                  <div>
                    <p className="text-xs font-medium uppercase tracking-wide text-emerald-700">
                      Available since
                    </p>
                    <p className="font-semibold text-emerald-900">
                      v{aiStatus.officialSince}
                    </p>
                  </div>
                )}
                {aiStatus.abilitiesCount && (
                  <div>
                    <p className="text-xs font-medium uppercase tracking-wide text-emerald-700">
                      Abilities registered
                    </p>
                    <p className="font-semibold text-emerald-900">
                      {aiStatus.abilitiesCount}
                    </p>
                  </div>
                )}
              </div>
              {aiStatus.officialDocsUrl && (
                <a
                  href={aiStatus.officialDocsUrl}
                  className="mt-3 inline-flex items-center gap-1 text-sm font-medium text-emerald-700 hover:underline"
                  target="_blank"
                  rel="noopener noreferrer"
                >
                  Official docs / announcement →
                </a>
              )}
            </div>

            {aiStatus.abilities && aiStatus.abilities.length > 0 && (
              <div>
                <h3 className="mb-2 text-sm font-medium text-slate-700">
                  Registered abilities
                </h3>
                <div className="flex flex-wrap gap-2">
                  {aiStatus.abilities.map((ability) => (
                    <code
                      key={ability}
                      className="rounded bg-slate-100 px-2 py-1 text-xs font-mono text-slate-700"
                    >
                      {ability}
                    </code>
                  ))}
                </div>
              </div>
            )}
          </div>
        )}

        {/* Coming soon */}
        {aiStatus.level === "coming_soon" && aiStatus.officialDocsUrl && (
          <div className="rounded-lg bg-blue-50 p-4">
            <a
              href={aiStatus.officialDocsUrl}
              className="inline-flex items-center gap-1 text-sm font-medium text-blue-700 hover:underline"
              target="_blank"
              rel="noopener noreferrer"
            >
              Follow the roadmap →
            </a>
          </div>
        )}

        {/* Notes */}
        {aiStatus.notes && (
          <div className="mt-4 rounded-lg bg-slate-50 p-4">
            <p className="text-sm text-slate-600">
              <strong>Note:</strong> {aiStatus.notes}
            </p>
          </div>
        )}

        {/* Last verified */}
        {aiStatus.lastVerified && aiStatus.lastVerified !== "unknown" && (
          <p className="mt-4 text-xs text-slate-400">
            Status last verified: {aiStatus.lastVerified}
          </p>
        )}
      </div>

      {/* Unofficial plugins */}
      {aiStatus.unofficialPlugins.length > 0 && (
        <div className="mb-6 rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
          <h2 className="mb-4 text-lg font-semibold text-slate-900">
            Community AI Plugins
          </h2>
          <div className="space-y-4">
            {aiStatus.unofficialPlugins.map((up) => (
              <div
                key={up.pluginUrl}
                className="rounded-lg border border-amber-200 bg-amber-50 p-4"
              >
                <div className="mb-1 flex items-center justify-between gap-2">
                  <h3 className="font-semibold text-slate-900">{up.name}</h3>
                  <a
                    href={up.pluginUrl}
                    className="shrink-0 text-sm font-medium text-amber-700 hover:underline"
                    target="_blank"
                    rel="noopener noreferrer"
                  >
                    View plugin →
                  </a>
                </div>
                <p className="mb-2 text-sm text-slate-600">{up.description}</p>
                <p className="text-xs text-slate-500">
                  by{" "}
                  {up.authorUrl ? (
                    <a
                      href={up.authorUrl}
                      className="text-wp-blue hover:underline"
                      target="_blank"
                      rel="noopener noreferrer"
                    >
                      {up.author}
                    </a>
                  ) : (
                    up.author
                  )}
                </p>
              </div>
            ))}
          </div>
        </div>
      )}

      {/* CTA */}
      <div className="rounded-xl border border-dashed border-slate-300 p-6 text-center">
        <p className="mb-3 text-slate-600">
          Is this information outdated? Know of a community plugin or official
          announcement we missed?
        </p>
        <Link
          href={`/submit?plugin=${slug}`}
          className="inline-block rounded-lg bg-wp-blue px-5 py-2.5 font-medium text-white transition-colors hover:bg-wp-blue-dark"
        >
          Submit an update
        </Link>
      </div>
    </main>
  );
}
