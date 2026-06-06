"use client";

import { useState, Suspense } from "react";
import { useSearchParams, useRouter } from "next/navigation";
import type { Plugin } from "@/lib/types";

function Form({ plugins }: { plugins: Plugin[] }) {
  const searchParams = useSearchParams();
  const router = useRouter();
  const prefilledSlug = searchParams.get("plugin") ?? "";

  const [form, setForm] = useState({
    pluginSlug: prefilledSlug,
    type: "new_unofficial" as "new_unofficial" | "status_correction" | "new_official",
    pluginName: "",
    pluginUrl: "",
    description: "",
    author: "",
    email: "",
    notes: "",
  });
  const [state, setState] = useState<"idle" | "loading" | "success" | "error">("idle");
  const [errorMsg, setErrorMsg] = useState("");

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setState("loading");
    setErrorMsg("");
    try {
      const res = await fetch("/api/submit", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(form),
      });
      if (!res.ok) {
        const data = await res.json();
        throw new Error(data.error ?? "Submission failed");
      }
      setState("success");
    } catch (err) {
      setErrorMsg(err instanceof Error ? err.message : "Unknown error");
      setState("error");
    } finally {
      if (state !== "success") setState(state === "loading" ? "error" : state);
    }
  }

  if (state === "success") {
    return (
      <div className="rounded-xl border border-emerald-200 bg-emerald-50 p-8 text-center">
        <p className="text-2xl">✓</p>
        <h2 className="mt-2 text-lg font-semibold text-emerald-800">Submission received!</h2>
        <p className="mt-1 text-sm text-emerald-700">
          A maintainer will review it and update the directory. Thanks for contributing!
        </p>
        <button onClick={() => router.push("/plugins")} className="mt-4 text-sm text-wp-blue hover:underline">
          ← Back to directory
        </button>
      </div>
    );
  }

  return (
    <form onSubmit={handleSubmit} className="space-y-5">
      <div>
        <label className="mb-1 block text-sm font-medium text-slate-700">Plugin *</label>
        <select
          required
          value={form.pluginSlug}
          onChange={(e) => setForm({ ...form, pluginSlug: e.target.value })}
          className="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm focus:border-wp-blue focus:outline-none focus:ring-1 focus:ring-wp-blue"
        >
          <option value="">Select a plugin…</option>
          {plugins.map((p) => (
            <option key={p.slug} value={p.slug}>{p.name}</option>
          ))}
          <option value="__other">Other (not listed)</option>
        </select>
      </div>

      <div>
        <label className="mb-1 block text-sm font-medium text-slate-700">What are you submitting? *</label>
        <div className="space-y-2">
          {[
            { value: "new_unofficial", label: "A community plugin that adds AI Abilities for this plugin" },
            { value: "new_official", label: "Official AI Abilities have shipped" },
            { value: "status_correction", label: "A status correction or update" },
          ].map(({ value, label }) => (
            <label key={value} className="flex cursor-pointer items-start gap-2.5">
              <input
                type="radio"
                name="type"
                value={value}
                checked={form.type === value}
                onChange={() => setForm({ ...form, type: value as typeof form.type })}
                className="mt-0.5"
              />
              <span className="text-sm text-slate-700">{label}</span>
            </label>
          ))}
        </div>
      </div>

      {form.type === "new_unofficial" && (
        <>
          <div>
            <label className="mb-1 block text-sm font-medium text-slate-700">Plugin name *</label>
            <input
              type="text"
              required
              placeholder="e.g. WooCommerce MCP Adapter"
              value={form.pluginName}
              onChange={(e) => setForm({ ...form, pluginName: e.target.value })}
              className="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm focus:border-wp-blue focus:outline-none focus:ring-1 focus:ring-wp-blue"
            />
          </div>
          <div>
            <label className="mb-1 block text-sm font-medium text-slate-700">Plugin URL *</label>
            <input
              type="url"
              required
              placeholder="https://wordpress.org/plugins/… or GitHub URL"
              value={form.pluginUrl}
              onChange={(e) => setForm({ ...form, pluginUrl: e.target.value })}
              className="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm focus:border-wp-blue focus:outline-none focus:ring-1 focus:ring-wp-blue"
            />
          </div>
          <div>
            <label className="mb-1 block text-sm font-medium text-slate-700">Author / maintainer *</label>
            <input
              type="text"
              required
              value={form.author}
              onChange={(e) => setForm({ ...form, author: e.target.value })}
              className="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm focus:border-wp-blue focus:outline-none focus:ring-1 focus:ring-wp-blue"
            />
          </div>
          <div>
            <label className="mb-1 block text-sm font-medium text-slate-700">Description *</label>
            <textarea
              required
              rows={3}
              placeholder="What abilities does this plugin register?"
              value={form.description}
              onChange={(e) => setForm({ ...form, description: e.target.value })}
              className="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm focus:border-wp-blue focus:outline-none focus:ring-1 focus:ring-wp-blue"
            />
          </div>
        </>
      )}

      <div>
        <label className="mb-1 block text-sm font-medium text-slate-700">Additional notes</label>
        <textarea
          rows={2}
          value={form.notes}
          onChange={(e) => setForm({ ...form, notes: e.target.value })}
          className="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm focus:border-wp-blue focus:outline-none focus:ring-1 focus:ring-wp-blue"
        />
      </div>

      <div>
        <label className="mb-1 block text-sm font-medium text-slate-700">
          Your email <span className="font-normal text-slate-400">(optional)</span>
        </label>
        <input
          type="email"
          value={form.email}
          onChange={(e) => setForm({ ...form, email: e.target.value })}
          className="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm focus:border-wp-blue focus:outline-none focus:ring-1 focus:ring-wp-blue"
        />
      </div>

      {state === "error" && (
        <p className="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">{errorMsg}</p>
      )}

      <button
        type="submit"
        disabled={state === "loading"}
        className="w-full rounded-lg bg-wp-blue py-3 font-semibold text-white transition-colors hover:bg-wp-blue-dark disabled:opacity-60"
      >
        {state === "loading" ? "Submitting…" : "Submit for review"}
      </button>

      <p className="text-center text-xs text-slate-400">
        Submissions are reviewed by maintainers before appearing in the directory.
      </p>
    </form>
  );
}

export function SubmitForm({ plugins }: { plugins: Plugin[] }) {
  return (
    <Suspense>
      <Form plugins={plugins} />
    </Suspense>
  );
}
