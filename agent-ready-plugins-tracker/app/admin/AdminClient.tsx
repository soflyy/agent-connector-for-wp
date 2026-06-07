"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import type { Plugin } from "@/lib/types";
import { addPluginAction } from "./actions";

const input =
  "w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-wp-blue focus:outline-none focus:ring-1 focus:ring-wp-blue";

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
