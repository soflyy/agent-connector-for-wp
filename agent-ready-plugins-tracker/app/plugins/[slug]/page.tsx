import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";
import { getPluginWithStatus } from "@/lib/db";

// Always render from the live database — no static/ISR snapshot.
export const dynamic = "force-dynamic";

export async function generateMetadata({
  params,
}: {
  params: Promise<{ slug: string }>;
}): Promise<Metadata> {
  const { slug } = await params;
  const plugin = await getPluginWithStatus(slug);
  if (!plugin) return {};
  return {
    title: `${plugin.name} — Agent Ready Plugins Tracker`,
    description: `Does ${plugin.name} have WordPress AI Abilities or an MCP adapter? Check the latest status.`,
  };
}

function YesNo({ value }: { value: boolean }) {
  return value ? (
    <span className="font-medium text-emerald-600">Yes</span>
  ) : (
    <span className="text-slate-300">—</span>
  );
}

export default async function PluginDetailPage({
  params,
}: {
  params: Promise<{ slug: string }>;
}) {
  const { slug } = await params;
  const plugin = await getPluginWithStatus(slug);
  if (!plugin) notFound();

  const { aiStatus } = plugin;
  const hasOfficial = aiStatus.level === "official";
  const hasUnofficial =
    aiStatus.level === "unofficial" || aiStatus.unofficialPlugins.length > 0;

  return (
    <main className="mx-auto max-w-3xl px-4 py-10">
      <nav className="mb-6 text-sm text-slate-500">
        <Link href="/" className="hover:text-slate-700">Directory</Link>{" "}
        / <span className="text-slate-900">{plugin.name}</span>
      </nav>

      <h1 className="text-2xl font-bold text-slate-900">{plugin.name}</h1>
      <code className="text-xs text-slate-400">{plugin.slug}</code>

      <dl className="mt-6 divide-y divide-slate-100 overflow-hidden rounded-xl border border-slate-200 text-sm">
        <div className="flex items-center justify-between px-4 py-3">
          <dt className="text-slate-500">Official abilities</dt>
          <dd><YesNo value={hasOfficial} /></dd>
        </div>
        <div className="flex items-center justify-between px-4 py-3">
          <dt className="text-slate-500">Unofficial abilities</dt>
          <dd><YesNo value={hasUnofficial} /></dd>
        </div>
      </dl>

      {/* Official ability details */}
      {hasOfficial && (aiStatus.officialDocsUrl || (aiStatus.abilities?.length ?? 0) > 0) && (
        <div className="mt-6 space-y-3">
          {aiStatus.officialDocsUrl && (
            <a
              href={aiStatus.officialDocsUrl}
              className="inline-block text-sm font-medium text-wp-blue hover:underline"
              target="_blank"
              rel="noopener noreferrer"
            >
              Official docs →
            </a>
          )}
          {aiStatus.abilities && aiStatus.abilities.length > 0 && (
            <div className="flex flex-wrap gap-2">
              {aiStatus.abilities.map((ability) => (
                <code key={ability} className="rounded bg-slate-100 px-2 py-1 text-xs font-mono text-slate-700">
                  {ability}
                </code>
              ))}
            </div>
          )}
        </div>
      )}

      {/* Unofficial ability packs */}
      {aiStatus.unofficialPlugins.length > 0 && (
        <div className="mt-6 space-y-2">
          {aiStatus.unofficialPlugins.map((up) => (
            <a
              key={up.pluginUrl}
              href={up.pluginUrl}
              className="block text-sm font-medium text-wp-blue hover:underline"
              target="_blank"
              rel="noopener noreferrer"
            >
              {up.name} →
            </a>
          ))}
        </div>
      )}
    </main>
  );
}
