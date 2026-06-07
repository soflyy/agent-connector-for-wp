import Link from "next/link";
import { requireAdmin } from "@/lib/admin-auth";
import { getAllPluginsWithStatus, getSubmissions } from "@/lib/db";
import { AddPluginForm, AiCheckAllButton, AiCheckButton, LogoutButton, SubmissionItem } from "./AdminClient";

export const dynamic = "force-dynamic";

const BADGE: Record<string, string> = {
  official: "bg-green-100 text-green-800",
  unofficial: "bg-amber-100 text-amber-800",
  coming_soon: "bg-blue-100 text-blue-800",
  none: "bg-slate-100 text-slate-500",
};

export default async function AdminPage() {
  await requireAdmin();
  const plugins = await getAllPluginsWithStatus();
  const submissions = await getSubmissions();
  const pending = submissions.filter((s) => !s.reviewed);

  return (
    <div className="mx-auto max-w-5xl px-4 py-8">
      <div className="mb-6 flex items-center justify-between">
        <h1 className="text-2xl font-bold text-slate-900">Admin — AI statuses</h1>
        <LogoutButton />
      </div>

      <section className="mb-10">
        <div className="mb-3 flex items-center justify-between">
          <h2 className="text-lg font-semibold text-slate-900">
            Plugins <span className="text-sm font-normal text-slate-400">({plugins.length})</span>
          </h2>
          <AiCheckAllButton />
        </div>
        <p className="mb-3 text-sm text-slate-500">
          The AI check researches each plugin&apos;s current AI-ability status via web search and writes the
          result here. It runs daily (least-recently-checked first) and overwrites everything except statuses
          you&apos;ve edited by hand (those keep your edits; the AI result is saved as a suggestion).
        </p>
        <div className="overflow-hidden rounded-xl border border-slate-200">
          <table className="w-full text-sm">
            <thead className="bg-slate-50 text-left text-slate-500">
              <tr>
                <th className="px-4 py-2 font-medium">Plugin</th>
                <th className="px-4 py-2 font-medium">Level</th>
                <th className="px-4 py-2 font-medium">Source</th>
                <th className="px-4 py-2 font-medium">Checked</th>
                <th className="px-4 py-2"></th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {plugins.map((p) => (
                <tr key={p.slug}>
                  <td className="px-4 py-2">
                    <span className="font-medium text-slate-900">{p.name}</span>{" "}
                    <code className="text-xs text-slate-400">{p.slug}</code>
                  </td>
                  <td className="px-4 py-2">
                    <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${BADGE[p.aiStatus.level] ?? BADGE.none}`}>
                      {p.aiStatus.level}
                    </span>
                  </td>
                  <td className="px-4 py-2 text-slate-500">
                    {p.aiStatus.source === "manual" ? "✎ manual" : p.aiStatus.source === "ai" ? "AI" : "—"}
                  </td>
                  <td className="px-4 py-2 text-slate-500">
                    {p.aiStatus.autoCheckedAt ? p.aiStatus.autoCheckedAt.slice(0, 10) : "never"}
                  </td>
                  <td className="px-4 py-2">
                    <div className="flex items-center justify-end gap-3">
                      <AiCheckButton slug={p.slug} />
                      <Link href={`/admin/plugins/${p.slug}`} className="text-wp-blue hover:underline">
                        Edit
                      </Link>
                    </div>
                  </td>
                </tr>
              ))}
              {plugins.length === 0 && (
                <tr>
                  <td colSpan={5} className="px-4 py-6 text-center text-slate-400">
                    No plugins yet. Add one below.
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      </section>

      <section className="mb-10">
        <h2 className="mb-3 text-lg font-semibold text-slate-900">Add a plugin</h2>
        <p className="mb-3 text-sm text-slate-500">
          A plugin must exist before you can set its AI status. Add it here, then click Edit above.
        </p>
        <AddPluginForm />
      </section>

      <section>
        <h2 className="mb-3 text-lg font-semibold text-slate-900">
          Pending submissions <span className="text-sm font-normal text-slate-400">({pending.length})</span>
        </h2>
        {pending.length === 0 ? (
          <p className="text-sm text-slate-400">Nothing pending.</p>
        ) : (
          <ul className="space-y-2">
            {pending.map((s) => (
              <SubmissionItem key={s.id} submission={s} />
            ))}
          </ul>
        )}
      </section>
    </div>
  );
}
