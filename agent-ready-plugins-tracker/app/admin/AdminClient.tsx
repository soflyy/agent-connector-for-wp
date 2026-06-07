"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import type { Plugin, Submission } from "@/lib/types";
import { addPluginAction, reviewSubmissionAction } from "./actions";

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
      const applied = (data.results || []).filter((r: { applied?: boolean }) => r.applied).length;
      const failed = (data.results || []).filter((r: { ok?: boolean }) => r.ok === false).length;
      return { ok: true, message: `Checked ${data.checked}; applied ${applied}${failed ? `; ${failed} failed` : ""}.` };
    }
    if (data.ok === false) return { ok: false, message: data.error || "Check failed." };
    return { ok: true, message: `${data.level ?? "done"}${data.applied === false ? " (kept manual edit)" : ""}.` };
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
  const [tagline, setTagline] = useState("");
  const [author, setAuthor] = useState("");
  const [wpOrgUrl, setWpOrgUrl] = useState("");
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
      tagline: tagline.trim(),
      author: author.trim(),
      wpOrgUrl: wpOrgUrl.trim() || undefined,
      isPremium: false,
      categories: [],
      activeInstalls: "",
    };
    const res = await addPluginAction(plugin);
    if (res.ok) {
      setMsg(`Added ${plugin.slug}.`);
      setSlug("");
      setName("");
      setTagline("");
      setAuthor("");
      setWpOrgUrl("");
      router.refresh();
    } else {
      setMsg(`Failed: ${res.error}`);
    }
    setBusy(false);
  }

  return (
    <form onSubmit={submit} className="grid grid-cols-2 gap-3">
      <input className={input} value={slug} onChange={(e) => setSlug(e.target.value)} placeholder="slug (e.g. gravityforms) *" />
      <input className={input} value={name} onChange={(e) => setName(e.target.value)} placeholder="Name *" />
      <input className={input} value={tagline} onChange={(e) => setTagline(e.target.value)} placeholder="Tagline" />
      <input className={input} value={author} onChange={(e) => setAuthor(e.target.value)} placeholder="Author" />
      <input className={input} value={wpOrgUrl} onChange={(e) => setWpOrgUrl(e.target.value)} placeholder="wordpress.org URL" />
      <div className="flex items-center gap-3">
        <button type="submit" disabled={busy} className="rounded-lg bg-wp-blue px-4 py-2 text-sm font-semibold text-white hover:bg-wp-blue-dark disabled:opacity-60">
          {busy ? "Adding…" : "Add plugin"}
        </button>
        {msg && <span className="text-sm text-slate-600">{msg}</span>}
      </div>
    </form>
  );
}

export function SubmissionItem({ submission }: { submission: Submission }) {
  const router = useRouter();
  const [busy, setBusy] = useState(false);
  return (
    <li className="flex items-start justify-between gap-4 rounded-lg border border-slate-200 p-3">
      <div className="text-sm">
        <span className="font-medium text-slate-900">{submission.pluginSlug}</span>{" "}
        <span className="text-slate-500">· {submission.type}</span>
        {submission.notes && <p className="mt-1 text-slate-600">{submission.notes}</p>}
        {submission.submitterEmail && <p className="mt-0.5 text-xs text-slate-400">{submission.submitterEmail}</p>}
      </div>
      <button
        disabled={busy}
        onClick={async () => {
          setBusy(true);
          const res = await reviewSubmissionAction(submission.id);
          if (res.ok) router.refresh();
          else alert(res.error);
          setBusy(false);
        }}
        className="shrink-0 rounded-md border border-slate-300 px-2.5 py-1 text-xs font-medium hover:bg-slate-50 disabled:opacity-60"
      >
        {busy ? "…" : "Mark reviewed"}
      </button>
    </li>
  );
}
