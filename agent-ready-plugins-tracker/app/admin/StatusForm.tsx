"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import Link from "next/link";
import type { AIStatus, AIStatusLevel, UnofficialPlugin } from "@/lib/types";
import { saveStatusAction } from "./actions";

const LEVELS: AIStatusLevel[] = ["official", "unofficial", "none"];

const input =
  "w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-wp-blue focus:outline-none focus:ring-1 focus:ring-wp-blue";
const labelCls = "mb-1 block text-sm font-medium text-slate-700";

export default function StatusForm({
  slug,
  name,
  initial,
}: {
  slug: string;
  name: string;
  initial: AIStatus;
}) {
  const router = useRouter();
  const [level, setLevel] = useState<AIStatusLevel>(initial.level ?? "none");
  const [officialSince, setOfficialSince] = useState(initial.officialSince ?? "");
  const [officialDocsUrl, setOfficialDocsUrl] = useState(initial.officialDocsUrl ?? "");
  const [abilitiesCount, setAbilitiesCount] = useState(
    initial.abilitiesCount != null ? String(initial.abilitiesCount) : ""
  );
  const [abilitiesText, setAbilitiesText] = useState((initial.abilities ?? []).join("\n"));
  const [notes, setNotes] = useState(initial.notes ?? "");
  const [unofficial, setUnofficial] = useState<UnofficialPlugin[]>(initial.unofficialPlugins ?? []);
  const [saving, setSaving] = useState(false);
  const [msg, setMsg] = useState("");

  function setRow(i: number, patch: Partial<UnofficialPlugin>) {
    setUnofficial((rows) => rows.map((r, idx) => (idx === i ? { ...r, ...patch } : r)));
  }
  function addRow() {
    setUnofficial((rows) => [...rows, { name: "", pluginUrl: "", description: "", author: "" }]);
  }
  function removeRow(i: number) {
    setUnofficial((rows) => rows.filter((_, idx) => idx !== i));
  }

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setSaving(true);
    setMsg("");
    const status: AIStatus = {
      level,
      officialSince: officialSince.trim() || undefined,
      officialDocsUrl: officialDocsUrl.trim() || undefined,
      abilitiesCount: abilitiesCount.trim() ? Number(abilitiesCount) : undefined,
      abilities: abilitiesText
        .split(/[\n,]/)
        .map((s) => s.trim())
        .filter(Boolean),
      unofficialPlugins: unofficial
        .filter((u) => u.name.trim())
        .map((u) => ({
          name: u.name.trim(),
          pluginUrl: u.pluginUrl.trim(),
          description: u.description.trim(),
          author: u.author.trim(),
          authorUrl: u.authorUrl?.trim() || undefined,
        })),
      notes: notes.trim() || undefined,
      lastVerified: initial.lastVerified ?? "",
    };
    try {
      await saveStatusAction(slug, status);
      setMsg("Saved.");
      router.refresh();
    } catch {
      setMsg("Save failed.");
    } finally {
      setSaving(false);
    }
  }

  return (
    <div className="mx-auto max-w-3xl px-4 py-8">
      <Link href="/admin" className="text-sm text-wp-blue hover:underline">
        ← Back to dashboard
      </Link>
      <h1 className="mt-2 text-2xl font-bold text-slate-900">{name}</h1>
      <p className="mb-6 text-sm text-slate-500">
        <code>{slug}</code> — AI ability status
      </p>

      <form onSubmit={handleSubmit} className="space-y-5">
        <div>
          <label className={labelCls}>Level</label>
          <select
            value={level}
            onChange={(e) => setLevel(e.target.value as AIStatusLevel)}
            className={input}
          >
            {LEVELS.map((l) => (
              <option key={l} value={l}>
                {l}
              </option>
            ))}
          </select>
        </div>

        <div className="grid grid-cols-2 gap-4">
          <div>
            <label className={labelCls}>Official since (version)</label>
            <input className={input} value={officialSince} onChange={(e) => setOfficialSince(e.target.value)} placeholder="9.4" />
          </div>
          <div>
            <label className={labelCls}>Abilities count</label>
            <input className={input} type="number" min="0" value={abilitiesCount} onChange={(e) => setAbilitiesCount(e.target.value)} />
          </div>
        </div>

        <div>
          <label className={labelCls}>Official docs / roadmap URL</label>
          <input className={input} value={officialDocsUrl} onChange={(e) => setOfficialDocsUrl(e.target.value)} placeholder="https://…" />
        </div>

        <div>
          <label className={labelCls}>Official abilities (one per line or comma-separated)</label>
          <textarea className={input} rows={4} value={abilitiesText} onChange={(e) => setAbilitiesText(e.target.value)} />
        </div>

        <div>
          <div className="mb-2 flex items-center justify-between">
            <label className={labelCls + " mb-0"}>Third-party unofficial extensions</label>
            <button type="button" onClick={addRow} className="rounded-md border border-slate-300 px-2.5 py-1 text-xs font-medium hover:bg-slate-50">
              + Add
            </button>
          </div>
          <div className="space-y-3">
            {unofficial.length === 0 && <p className="text-sm text-slate-400">None.</p>}
            {unofficial.map((u, i) => (
              <div key={i} className="rounded-lg border border-slate-200 p-3">
                <div className="grid grid-cols-2 gap-3">
                  <input className={input} value={u.name} onChange={(e) => setRow(i, { name: e.target.value })} placeholder="Name *" />
                  <input className={input} value={u.pluginUrl} onChange={(e) => setRow(i, { pluginUrl: e.target.value })} placeholder="Plugin URL" />
                  <input className={input} value={u.author} onChange={(e) => setRow(i, { author: e.target.value })} placeholder="Author" />
                  <input className={input} value={u.authorUrl ?? ""} onChange={(e) => setRow(i, { authorUrl: e.target.value })} placeholder="Author URL" />
                </div>
                <textarea className={input + " mt-3"} rows={2} value={u.description} onChange={(e) => setRow(i, { description: e.target.value })} placeholder="Description" />
                <button type="button" onClick={() => removeRow(i)} className="mt-2 text-xs text-red-600 hover:underline">
                  Remove
                </button>
              </div>
            ))}
          </div>
        </div>

        <div>
          <label className={labelCls}>Notes</label>
          <textarea className={input} rows={2} value={notes} onChange={(e) => setNotes(e.target.value)} />
        </div>

        <div className="flex items-center gap-3">
          <button type="submit" disabled={saving} className="rounded-lg bg-wp-blue px-5 py-2.5 font-semibold text-white hover:bg-wp-blue-dark disabled:opacity-60">
            {saving ? "Saving…" : "Save status"}
          </button>
          {msg && <span className="text-sm text-slate-600">{msg}</span>}
        </div>
      </form>
    </div>
  );
}
