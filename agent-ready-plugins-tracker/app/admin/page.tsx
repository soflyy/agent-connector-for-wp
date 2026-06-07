import Link from "next/link";
import { requireAdmin } from "@/lib/admin-auth";
import { getAllPlugins } from "@/lib/db";
import { AddPluginForm, AiCheckAllButton, AiCheckButton, DeletePluginButton, LogoutButton } from "./AdminClient";

export const dynamic = "force-dynamic";

function YesNo({ on }: { on: boolean }) {
  return on ? (
    <span className="font-medium text-emerald-600">Yes</span>
  ) : (
    <span className="text-slate-300">—</span>
  );
}

export default async function AdminPage() {
  await requireAdmin();
  const plugins = await getAllPlugins();
  plugins.sort((a, b) => a.name.localeCompare(b.name));

  return (
    <div className="mx-auto max-w-5xl px-4 py-8">
      <div className="mb-6 flex items-center justify-between">
        <h1 className="text-2xl font-bold text-slate-900">Admin — plugins</h1>
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
          The AI check researches each plugin via web search and sets two things — whether the plugin ships
          official abilities, and which third-party plugins provide abilities for it. It runs daily
          (least-recently-checked first). The ability-pack link is curated by hand and never overwritten.
        </p>
        <div className="overflow-hidden rounded-xl border border-slate-200">
          <table className="w-full text-sm">
            <thead className="bg-slate-50 text-left text-slate-500">
              <tr>
                <th className="px-4 py-2 font-medium">Plugin</th>
                <th className="px-4 py-2 font-medium">Official</th>
                <th className="px-4 py-2 font-medium">3rd-party</th>
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
                  <td className="px-4 py-2"><YesNo on={p.includesAbilities} /></td>
                  <td className="px-4 py-2 text-slate-500">{p.thirdPartyAbilities.length || "—"}</td>
                  <td className="px-4 py-2">
                    <div className="flex items-center justify-end gap-3">
                      <AiCheckButton slug={p.slug} />
                      <Link href={`/admin/plugins/${p.urlSlug}`} className="text-wp-blue hover:underline">
                        Edit
                      </Link>
                      <DeletePluginButton slug={p.slug} name={p.name} />
                    </div>
                  </td>
                </tr>
              ))}
              {plugins.length === 0 && (
                <tr>
                  <td colSpan={4} className="px-4 py-6 text-center text-slate-400">
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
          Add a plugin by its file (e.g. <code>woocommerce/woocommerce.php</code>), then click Edit to fill in
          the rest — or run the AI check to populate it automatically.
        </p>
        <AddPluginForm />
      </section>
    </div>
  );
}
