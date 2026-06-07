import Link from "next/link";
import { getAllPlugins } from "@/lib/db";

// Always render from the live database — no static/ISR snapshot.
export const dynamic = "force-dynamic";

function Yes({ on }: { on: boolean }) {
  return on ? (
    <span className="inline-flex items-center rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-medium text-emerald-700 ring-1 ring-inset ring-emerald-600/20">
      Yes
    </span>
  ) : (
    <span className="text-slate-300">—</span>
  );
}

export default async function HomePage() {
  const plugins = await getAllPlugins();
  plugins.sort((a, b) => a.name.localeCompare(b.name));

  return (
    <main className="mx-auto max-w-4xl px-4 py-10">
      <h1 className="mb-8 text-3xl font-bold tracking-tight text-slate-900">
        Agent Ready Plugins Tracker
      </h1>

      <div className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        {/* Header */}
        <div className="grid grid-cols-[1fr_6rem_6rem_6rem] items-center gap-4 border-b border-slate-200 bg-slate-50 px-5 py-2.5 text-xs font-medium uppercase tracking-wide text-slate-500">
          <div>Plugin</div>
          <div className="text-center">Official</div>
          <div className="text-center">3rd-party</div>
          <div className="text-center">AC4WP pack</div>
        </div>

        {/* Rows */}
        <div className="divide-y divide-slate-100">
          {plugins.map((p) => (
            <Link
              key={p.slug}
              href={`/plugins/${p.urlSlug}`}
              className="grid grid-cols-[1fr_6rem_6rem_6rem] items-center gap-4 px-5 py-3.5 transition-colors hover:bg-slate-50"
            >
              <div className="min-w-0">
                <div className="truncate font-medium text-slate-900">{p.name}</div>
                <code className="text-xs text-slate-400">{p.slug}</code>
              </div>
              <div className="text-center"><Yes on={p.includesAbilities} /></div>
              <div className="text-center"><Yes on={p.thirdPartyAbilities.length > 0} /></div>
              <div className="text-center"><Yes on={!!p.ac4wpAbilityPackUrl} /></div>
            </Link>
          ))}
          {plugins.length === 0 && (
            <p className="px-5 py-10 text-center text-sm text-slate-400">
              No plugins tracked yet.
            </p>
          )}
        </div>
      </div>
    </main>
  );
}
