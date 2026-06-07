"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import type { Plugin } from "@/lib/types";
import { addPluginAction, deletePluginAction } from "./actions";

const input =
  "w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-wp-blue focus:outline-none focus:ring-1 focus:ring-wp-blue";

async function postAiCheck(body: object): Promise<{ ok: boolean; message: string }> {
  try {
    const res = await fetch("/api/admin/ai-check", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(body),
    });
    const data = await res.json();
    if (!res.ok) return { ok: false, message: data?.error || `HTTP ${res.status}` };
    if (typeof data.checked === "number") {
      const failed = (data.results || []).filter((r: { ok?: boolean }) => r.ok === false).length;
      return { ok: true, message: `Checked ${data.checked}${failed ? `; ${failed} failed` : ""}.` };
    }
    if (data.ok === false) return { ok: false, message: data.error || "Check failed." };
    const official = data.includesAbilities ? "official" : "no official";
    return { ok: true, message: `${official}, ${data.thirdPartyCount ?? 0} third-party.` };
  } catch (e) {
    return { ok: false, message: e instanceof Error ? e.message : "Request failed" };
  }
}

export function AiCheckButton({ slug }: { slug: string }) {
  const router = useRouter();
  const [busy, setBusy] = useState(false);
  const [msg, setMsg] = useState("");
  return (
    <span className="inline-flex items-center gap-2">
      <button
        disabled={busy}
        onClick={async () => {
          setBusy(true);
          setMsg("");
          const r = await postAiCheck({ slug });
          setMsg(r.message);
          if (r.ok) router.refresh();
          setBusy(false);
        }}
        className="rounded-md border border-slate-300 px-2.5 py-1 text-xs font-medium hover:bg-slate-50 disabled:opacity-60"
      >
        {busy ? "Checking…" : "Re-check (AI)"}
      </button>
      {msg && <span className="text-xs text-slate-500">{msg}</span>}
    </span>
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
          const r = await postAiCheck({ all: true });
          setMsg(r.message);
          if (r.ok) router.refresh();
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
