"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import Link from "next/link";
import type { Plugin, ThirdPartyAbility } from "@/lib/types";
import { savePluginAction } from "./actions";

const input =
  "w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-wp-blue focus:outline-none focus:ring-1 focus:ring-wp-blue";
const labelCls = "mb-1 block text-sm font-medium text-slate-700";

export default function StatusForm({ initial }: { initial: Plugin }) {
  const router = useRouter();
  const [name, setName] = useState(initial.name);
  const [link, setLink] = useState(initial.link ?? "");
  const [includesAbilities, setIncludesAbilities] = useState(initial.includesAbilities);
  const [thirdParty, setThirdParty] = useState<ThirdPartyAbility[]>(initial.thirdPartyAbilities ?? []);
  const [saving, setSaving] = useState(false);
  const [msg, setMsg] = useState("");

  function setRow(i: number, patch: Partial<ThirdPartyAbility>) {
    setThirdParty((rows) => rows.map((r, idx) => (idx === i ? { ...r, ...patch } : r)));
  }
  function addRow() {
    setThirdParty((rows) => [...rows, { pluginName: "", pluginSlug: "", pluginLink: "" }]);
  }
  function removeRow(i: number) {
    setThirdParty((rows) => rows.filter((_, idx) => idx !== i));
  }

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setSaving(true);
    setMsg("");
    const plugin: Plugin = {
      slug: initial.slug,
      urlSlug: initial.urlSlug,
      name: name.trim(),
      link: link.trim() || undefined,
      includesAbilities,
      thirdPartyAbilities: thirdParty
        .filter((t) => t.pluginName.trim())
        .map((t) => ({
          pluginName: t.pluginName.trim(),
          pluginSlug: t.pluginSlug.trim(),
          pluginLink: t.pluginLink.trim(),
        })),
    };
    const res = await savePluginAction(plugin);
    setMsg(res.ok ? "Saved." : `Save failed: ${res.error}`);
    if (res.ok) router.refresh();
    setSaving(false);
  }

  return (
    <div className="mx-auto max-w-3xl px-4 py-8">
      <Link href="/admin" className="text-sm text-wp-blue hover:underline">
        ← Back to dashboard
      </Link>
      <h1 className="mt-2 text-2xl font-bold text-slate-900">{initial.name}</h1>
      <p className="mb-6 text-sm text-slate-500">
        <code>{initial.slug}</code>
      </p>

      <form onSubmit={handleSubmit} className="space-y-5">
        <div>
          <label className={labelCls}>Name</label>
          <input className={input} value={name} onChange={(e) => setName(e.target.value)} />
        </div>

        <div>
          <label className={labelCls}>Plugin link (.org or commercial page)</label>
          <input className={input} value={link} onChange={(e) => setLink(e.target.value)} placeholder="https://…" />
        </div>

        <label className="flex items-center gap-2 text-sm font-medium text-slate-700">
          <input
            type="checkbox"
            checked={includesAbilities}
            onChange={(e) => setIncludesAbilities(e.target.checked)}
            className="h-4 w-4 rounded border-slate-300 text-wp-blue focus:ring-wp-blue"
          />
          Plugin includes its own (official) abilities
        </label>

        <div>
          <div className="mb-2 flex items-center justify-between">
            <label className={labelCls + " mb-0"}>3rd-party abilities provided by</label>
            <button type="button" onClick={addRow} className="rounded-md border border-slate-300 px-2.5 py-1 text-xs font-medium hover:bg-slate-50">
              + Add
            </button>
          </div>
          <p className="mb-2 text-xs text-slate-400">Includes our own Agent Connector ability packs — add them here too.</p>
          <div className="space-y-3">
            {thirdParty.length === 0 && <p className="text-sm text-slate-400">None.</p>}
            {thirdParty.map((t, i) => (
              <div key={i} className="rounded-lg border border-slate-200 p-3">
                <div className="grid grid-cols-3 gap-3">
                  <input className={input} value={t.pluginName} onChange={(e) => setRow(i, { pluginName: e.target.value })} placeholder="Plugin name *" />
                  <input className={input} value={t.pluginSlug} onChange={(e) => setRow(i, { pluginSlug: e.target.value })} placeholder="Slug" />
                  <input className={input} value={t.pluginLink} onChange={(e) => setRow(i, { pluginLink: e.target.value })} placeholder="Link" />
                </div>
                <button type="button" onClick={() => removeRow(i)} className="mt-2 text-xs text-red-600 hover:underline">
                  Remove
                </button>
              </div>
            ))}
          </div>
        </div>

        <div className="flex items-center gap-3">
          <button type="submit" disabled={saving} className="rounded-lg bg-wp-blue px-5 py-2.5 font-semibold text-white hover:bg-wp-blue-dark disabled:opacity-60">
            {saving ? "Saving…" : "Save plugin"}
          </button>
          {msg && <span className="text-sm text-slate-600">{msg}</span>}
        </div>
      </form>
    </div>
  );
}
