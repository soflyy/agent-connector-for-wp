import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";
import { getPluginByUrlSlug } from "@/lib/db";

// Always render from the live database — no static/ISR snapshot.
export const dynamic = "force-dynamic";

export async function generateMetadata({
  params,
}: {
  params: Promise<{ slug: string }>;
}): Promise<Metadata> {
  const { slug } = await params;
  const plugin = await getPluginByUrlSlug(slug);
  if (!plugin) return {};
  return {
    title: `${plugin.name} — Agent Ready Plugins Tracker`,
    description: `AI-ability status for the ${plugin.name} WordPress plugin.`,
  };
}

function YesNo({ value }: { value: boolean }) {
  return value ? (
    <span className="font-medium text-emerald-600">Yes</span>
  ) : (
    <span className="text-slate-400">No</span>
  );
}

export default async function PluginDetailPage({
  params,
}: {
  params: Promise<{ slug: string }>;
}) {
  const { slug } = await params;
  const plugin = await getPluginByUrlSlug(slug);
  if (!plugin) notFound();

  return (
    <main className="mx-auto max-w-3xl px-4 py-10">
      <nav className="mb-6 text-sm text-slate-500">
        <Link href="/" className="hover:text-slate-700">Directory</Link>{" "}
        / <span className="text-slate-900">{plugin.name}</span>
      </nav>

      <h1 className="text-2xl font-bold text-slate-900">{plugin.name}</h1>
      <code className="text-xs text-slate-400">{plugin.slug}</code>
      {plugin.link && (
        <div className="mt-2">
          <a
            href={plugin.link}
            className="text-sm font-medium text-wp-blue hover:underline"
            target="_blank"
            rel="noopener noreferrer"
          >
            Plugin page →
          </a>
        </div>
      )}

      <dl className="mt-6 divide-y divide-slate-100 overflow-hidden rounded-xl border border-slate-200 text-sm">
        <div className="flex items-center justify-between px-4 py-3">
          <dt className="text-slate-500">Includes abilities</dt>
          <dd><YesNo value={plugin.includesAbilities} /></dd>
        </div>
        <div className="flex items-center justify-between px-4 py-3">
          <dt className="text-slate-500">Agent Connector ability pack</dt>
          <dd>
            {plugin.ac4wpAbilityPackUrl ? (
              <a
                href={plugin.ac4wpAbilityPackUrl}
                className="font-medium text-wp-blue hover:underline"
                target="_blank"
                rel="noopener noreferrer"
              >
                View pack →
              </a>
            ) : (
              <span className="text-slate-400">No</span>
            )}
          </dd>
        </div>
      </dl>

      <div className="mt-6">
        <h2 className="mb-2 text-sm font-medium text-slate-700">3rd-party abilities provided by</h2>
        {plugin.thirdPartyAbilities.length === 0 ? (
          <p className="text-sm text-slate-400">None.</p>
        ) : (
          <ul className="divide-y divide-slate-100 overflow-hidden rounded-xl border border-slate-200 text-sm">
            {plugin.thirdPartyAbilities.map((t, i) => (
              <li key={i} className="flex items-center justify-between gap-4 px-4 py-3">
                <div className="min-w-0">
                  <div className="truncate text-slate-900">{t.pluginName}</div>
                  {t.pluginSlug && <code className="text-xs text-slate-400">{t.pluginSlug}</code>}
                </div>
                {t.pluginLink && (
                  <a
                    href={t.pluginLink}
                    className="shrink-0 font-medium text-wp-blue hover:underline"
                    target="_blank"
                    rel="noopener noreferrer"
                  >
                    View →
                  </a>
                )}
              </li>
            ))}
          </ul>
        )}
      </div>
    </main>
  );
}
