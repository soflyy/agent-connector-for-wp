"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import type { Plugin } from "@/lib/types";
import { addPluginAction, deletePluginAction } from "./actions";

const input =
  "w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-wp-blue focus:outline-none focus:ring-1 focus:ring-wp-blue";

interface AiDebug {
  model?: string;
  temperature?: number;
  prompt?: string;
  request?: unknown;
  rawResponse?: unknown;
  object?: unknown;
  usage?: unknown;
  finishReason?: string;
  warnings?: unknown;
}
interface CheckData {
  ok?: boolean;
  error?: string;
  includesAbilities?: boolean;
  thirdPartyCount?: number;
  debug?: AiDebug;
}

async function callAiCheck(body: object): Promise<{ httpOk: boolean; data: CheckData }> {
  const res = await fetch("/api/admin/ai-check", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(body),
  });
  const data = await res.json().catch(() => ({}));
  return { httpOk: res.ok, data };
}

function pretty(v: unknown): string {
  if (v === undefined || v === null) return "—";
  if (typeof v === "string") return v;
  try {
    return JSON.stringify(v, null, 2);
  } catch {
    return String(v);
  }
}

function DebugSection({ title, value }: { title: string; value: unknown }) {
  return (
    <div className="mt-4">
      <h4 className="mb-1 text-xs font-semibold uppercase tracking-wide text-slate-500">{title}</h4>
      <pre className="max-h-72 overflow-auto whitespace-pre-wrap break-words rounded-lg bg-slate-50 p-3 text-xs font-mono text-slate-700">
        {pretty(value)}
      </pre>
    </div>
  );
}

function DebugModal({ slug, data, onClose }: { slug: string; data: CheckData; onClose: () => void }) {
  const d = data.debug ?? {};
  return (
    <div
      className="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/40 p-4"
      onClick={onClose}
    >
      <div className="my-8 w-full max-w-3xl rounded-xl bg-white p-6 shadow-xl" onClick={(e) => e.stopPropagation()}>
        <div className="mb-3 flex items-center justify-between">
          <h3 className="text-lg font-semibold text-slate-900">
            AI re-check — <code className="text-sm text-slate-500">{slug}</code>
          </h3>
          <button onClick={onClose} className="rounded-md border border-slate-300 px-3 py-1 text-sm font-medium hover:bg-slate-50">
            Close
          </button>
        </div>

        {data.ok === false ? (
          <p className="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">Error: {data.error || "unknown"}</p>
        ) : (
          <p className="rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-700">
            Applied: {data.includesAbilities ? "official abilities" : "no official abilities"}, {data.thirdPartyCount ?? 0} third-party.
          </p>
        )}

        <p className="mt-4 text-sm text-slate-600">
          <span className="font-medium">Model:</span> <code>{d.model ?? "—"}</code>
          {" · "}
          <span className="font-medium">temperature:</span> {String(d.temperature ?? "—")}
          {d.finishReason ? <> {" · "} <span className="font-medium">finish:</span> {d.finishReason}</> : null}
        </p>

        <DebugSection title="Prompt" value={d.prompt} />
        <DebugSection title="Request sent to provider" value={d.request} />
        <DebugSection title="Raw response from provider" value={d.rawResponse} />
        <DebugSection title="Parsed object" value={d.object} />
        <DebugSection title="Usage" value={d.usage} />
        {d.warnings ? <DebugSection title="Warnings" value={d.warnings} /> : null}
      </div>
    </div>
  );
}

export function AiCheckButton({ slug }: { slug: string }) {
  const router = useRouter();
  const [busy, setBusy] = useState(false);
  const [result, setResult] = useState<CheckData | null>(null);
  return (
    <>
      <button
        disabled={busy}
        onClick={async () => {
          setBusy(true);
          try {
            const { httpOk, data } = await callAiCheck({ slug });
            const shaped: CheckData = httpOk ? data : { ok: false, error: data?.error || "Request failed", debug: data?.debug };
            setResult(shaped);
            if (httpOk && data?.ok) router.refresh();
          } catch (e) {
            setResult({ ok: false, error: e instanceof Error ? e.message : "Request failed" });
          }
          setBusy(false);
        }}
        className="rounded-md border border-slate-300 px-2.5 py-1 text-xs font-medium hover:bg-slate-50 disabled:opacity-60"
      >
        {busy ? "Checking…" : "Re-check (AI)"}
      </button>
      {result && <DebugModal slug={slug} data={result} onClose={() => setResult(null)} />}
    </>
  );
}

export function AiCheckAllButton() {
  const router = useRouter();
  const [busy, setBusy] = useState(false);
  const [msg, setMsg] = useState("");
  return (
    <div className="flex items-center gap-3">
      <button
        disabled={busy}
        onClick={async () => {
          setBusy(true);
          setMsg("");
          try {
            const { httpOk, data } = await callAiCheck({ all: true });
            if (!httpOk) {
              setMsg((data as { error?: string })?.error || "Request failed");
            } else {
              const results = (data as unknown as { checked?: number; results?: { ok?: boolean }[] });
              const failed = (results.results || []).filter((r) => r.ok === false).length;
              setMsg(`Checked ${results.checked ?? 0}${failed ? `; ${failed} failed` : ""}.`);
              router.refresh();
            }
          } catch (e) {
            setMsg(e instanceof Error ? e.message : "Request failed");
          }
          setBusy(false);
        }}
        className="rounded-lg bg-wp-blue px-4 py-2 text-sm font-semibold text-white hover:bg-wp-blue-dark disabled:opacity-60"
      >
        {busy ? "Checking…" : "Run AI check now"}
      </button>
      {msg && <span className="text-sm text-slate-600">{msg}</span>}
    </div>
  );
}

export function DeletePluginButton({ slug, name }: { slug: string; name: string }) {
  const router = useRouter();
  const [busy, setBusy] = useState(false);

  async function onDelete() {
    if (!confirm(`Delete "${name}" (${slug})? This cannot be undone.`)) return;
    setBusy(true);
    const res = await deletePluginAction(slug);
    if (res.ok) {
      router.refresh();
    } else {
      alert(`Failed to delete: ${res.error}`);
      setBusy(false);
    }
  }

  return (
    <button onClick={onDelete} disabled={busy} className="text-red-600 hover:underline disabled:opacity-60">
      {busy ? "Deleting…" : "Delete"}
    </button>
  );
}

export function LogoutButton() {
  const router = useRouter();
  return (
    <button
      onClick={async () => {
        await fetch("/api/admin/login", { method: "DELETE" });
        router.push("/admin/login");
        router.refresh();
      }}
      className="rounded-md border border-slate-300 px-3 py-1.5 text-sm font-medium hover:bg-slate-50"
    >
      Log out
    </button>
  );
}

export function AddPluginForm() {
  const router = useRouter();
  const [slug, setSlug] = useState("");
  const [name, setName] = useState("");
  const [link, setLink] = useState("");
  const [busy, setBusy] = useState(false);
  const [msg, setMsg] = useState("");

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    if (!slug.trim() || !name.trim()) return;
    setBusy(true);
    setMsg("");
    const plugin: Plugin = {
      slug: slug.trim(),
      name: name.trim(),
      link: link.trim() || undefined,
      includesAbilities: false,
      thirdPartyAbilities: [],
    };
    const res = await addPluginAction(plugin);
    if (res.ok) {
      setMsg(`Added ${plugin.slug}.`);
      setSlug("");
      setName("");
      setLink("");
      router.refresh();
    } else {
      setMsg(`Failed: ${res.error}`);
    }
    setBusy(false);
  }

  return (
    <form onSubmit={submit} className="grid grid-cols-2 gap-3">
      <input className={input} value={slug} onChange={(e) => setSlug(e.target.value)} placeholder="slug — e.g. woocommerce/woocommerce.php *" />
      <input className={input} value={name} onChange={(e) => setName(e.target.value)} placeholder="Name *" />
      <input className={input} value={link} onChange={(e) => setLink(e.target.value)} placeholder="Plugin link (.org or commercial)" />
      <div className="flex items-center gap-3">
        <button type="submit" disabled={busy} className="rounded-lg bg-wp-blue px-4 py-2 text-sm font-semibold text-white hover:bg-wp-blue-dark disabled:opacity-60">
          {busy ? "Adding…" : "Add plugin"}
        </button>
        {msg && <span className="text-sm text-slate-600">{msg}</span>}
      </div>
    </form>
  );
}
