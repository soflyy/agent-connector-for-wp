import Link from "next/link";
import { getAllPluginsWithStatus } from "@/lib/db";

// Always render from the live database — no static/ISR snapshot.
export const dynamic = "force-dynamic";

function YesNo({ value }: { value: boolean }) {
  return value ? (
    <span className="font-medium text-emerald-600">Yes</span>
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
      <h1 className="mb-8 text-3xl font-bold text-slate-900">
        Agent Ready Plugins Tracker
      </h1>

      <div className="overflow-hidden rounded-xl border border-slate-200">
        <table className="w-full text-sm">
          <thead className="bg-slate-50 text-left text-slate-500">
            <tr>
              <th className="px-4 py-2 font-medium">Plugin</th>
              <th className="px-4 py-2 font-medium">Slug</th>
              <th className="px-4 py-2 font-medium">Official abilities</th>
              <th className="px-4 py-2 font-medium">Unofficial abilities</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {plugins.map((p) => {
              const hasOfficial = p.aiStatus.level === "official";
              const hasUnofficial =
                p.aiStatus.level === "unofficial" || p.aiStatus.unofficialPlugins.length > 0;
              return (
                <tr key={p.slug} className="hover:bg-slate-50">
                  <td className="px-4 py-2">
                    <Link href={`/plugins/${p.slug}`} className="font-medium text-slate-900 hover:text-wp-blue hover:underline">
                      {p.name}
                    </Link>
                  </td>
                  <td className="px-4 py-2">
                    <code className="text-xs text-slate-400">{p.slug}</code>
                  </td>
                  <td className="px-4 py-2">
                    <YesNo value={hasOfficial} />
                  </td>
                  <td className="px-4 py-2">
                    <YesNo value={hasUnofficial} />
                  </td>
                </tr>
              );
            })}
            {plugins.length === 0 && (
              <tr>
                <td colSpan={4} className="px-4 py-6 text-center text-slate-400">
                  No plugins tracked yet.
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </div>
    </main>
  );
}
