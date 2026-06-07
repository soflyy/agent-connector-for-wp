import Link from "next/link";
import { getAllPluginsWithStatus } from "@/lib/db";

// Always render from the live database — no static/ISR snapshot.
export const dynamic = "force-dynamic";

function Ability({ has }: { has: boolean }) {
  return has ? (
    <span className="inline-flex items-center rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-medium text-emerald-700 ring-1 ring-inset ring-emerald-600/20">
      Yes
    </span>
  ) : (
    <span className="text-slate-300">—</span>
  );
}

export default async function HomePage() {
  const plugins = await getAllPluginsWithStatus();

  const ORDER: Record<string, number> = { official: 0, unofficial: 1, none: 2 };
  plugins.sort((a, b) => {
    const diff = (ORDER[a.aiStatus.level] ?? 99) - (ORDER[b.aiStatus.level] ?? 99);
    return diff !== 0 ? diff : a.name.localeCompare(b.name);
  });

  return (
    <main className="mx-auto max-w-4xl px-4 py-10">
      <h1 className="mb-8 text-3xl font-bold tracking-tight text-slate-900">
        Agent Ready Plugins Tracker
      </h1>

      <div className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        {/* Header */}
        <div className="grid grid-cols-[1fr_7rem_7rem] items-center gap-4 border-b border-slate-200 bg-slate-50 px-5 py-2.5 text-xs font-medium uppercase tracking-wide text-slate-500">
          <div>Plugin</div>
          <div className="text-center">Official</div>
          <div className="text-center">Unofficial</div>
        </div>

        {/* Rows */}
        <div className="divide-y divide-slate-100">
          {plugins.map((p) => {
            const hasOfficial = p.aiStatus.level === "official";
            const hasUnofficial =
              p.aiStatus.level === "unofficial" || p.aiStatus.unofficialPlugins.length > 0;
            return (
              <Link
                key={p.slug}
                href={`/plugins/${p.slug}`}
                className="grid grid-cols-[1fr_7rem_7rem] items-center gap-4 px-5 py-3.5 transition-colors hover:bg-slate-50"
              >
                <div className="min-w-0">
                  <div className="truncate font-medium text-slate-900">{p.name}</div>
                  <code className="text-xs text-slate-400">{p.slug}</code>
                </div>
                <div className="text-center"><Ability has={hasOfficial} /></div>
                <div className="text-center"><Ability has={hasUnofficial} /></div>
              </Link>
            );
          })}
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
