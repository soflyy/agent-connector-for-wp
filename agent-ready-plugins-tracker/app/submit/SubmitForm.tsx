"use client";

import { useEffect, useRef, useState } from "react";

const SITE_KEY = process.env.NEXT_PUBLIC_TURNSTILE_SITE_KEY;

// Minimal typing for the Turnstile global we load from Cloudflare's script.
interface Turnstile {
  render: (el: HTMLElement, opts: Record<string, unknown>) => string;
  reset: (id?: string) => void;
}
declare global {
  interface Window {
    turnstile?: Turnstile;
  }
}

const input =
  "w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-wp-blue focus:outline-none focus:ring-1 focus:ring-wp-blue";
const labelCls = "mb-1 block text-sm font-medium text-slate-700";

type Result = { kind: "ok" | "rejected" | "error"; message: string };

export default function SubmitForm() {
  const [name, setName] = useState("");
  const [slug, setSlug] = useState("");
  const [link, setLink] = useState("");
  const [token, setToken] = useState("");
  const [busy, setBusy] = useState(false);
  const [result, setResult] = useState<Result | null>(null);

  const widgetRef = useRef<HTMLDivElement>(null);
  const widgetId = useRef<string | null>(null);

  // Load + render the Cloudflare Turnstile widget (only when a site key is set).
  useEffect(() => {
    if (!SITE_KEY) return;
    function render() {
      if (window.turnstile && widgetRef.current && widgetId.current === null) {
        widgetId.current = window.turnstile.render(widgetRef.current, {
          sitekey: SITE_KEY,
          callback: (t: string) => setToken(t),
          "error-callback": () => setToken(""),
          "expired-callback": () => setToken(""),
        });
      }
    }
    if (window.turnstile) {
      render();
      return;
    }
    const s = document.createElement("script");
    s.src = "https://challenges.cloudflare.com/turnstile/v0/api.js";
    s.async = true;
    s.defer = true;
    s.onload = render;
    document.head.appendChild(s);
  }, []);

  const needsToken = !!SITE_KEY;
  const canSubmit = name.trim() && slug.trim() && link.trim() && (!needsToken || token) && !busy;

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    if (!canSubmit) return;
    setBusy(true);
    setResult(null);
    try {
      const res = await fetch("/api/submit", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ name: name.trim(), slug: slug.trim(), link: link.trim(), turnstileToken: token }),
      });
      const data = await res.json().catch(() => ({}));
      if (data?.ok) {
        setResult({ kind: "ok", message: data.reason || "Approved and added to the directory!" });
        setName("");
        setSlug("");
        setLink("");
      } else if (data?.approved === false) {
        setResult({ kind: "rejected", message: data.error || "Not approved." });
      } else {
        setResult({ kind: "error", message: data?.error || `Something went wrong (HTTP ${res.status}).` });
      }
    } catch (e) {
      setResult({ kind: "error", message: e instanceof Error ? e.message : "Request failed." });
    } finally {
      // Each token is single-use — reset the widget so a retry gets a fresh one.
      setToken("");
      if (window.turnstile && widgetId.current) window.turnstile.reset(widgetId.current);
      setBusy(false);
    }
  }

  return (
    <form onSubmit={submit} className="space-y-5">
      <div>
        <label className={labelCls}>Plugin name</label>
        <input className={input} value={name} onChange={(e) => setName(e.target.value)} placeholder="WooCommerce" maxLength={99} />
      </div>
      <div>
        <label className={labelCls}>Plugin slug</label>
        <input className={input} value={slug} onChange={(e) => setSlug(e.target.value)} placeholder="woocommerce/woocommerce.php" maxLength={99} />
        <p className="mt-1 text-xs text-slate-400">The plugin folder/main file, e.g. <code>woocommerce/woocommerce.php</code>.</p>
      </div>
      <div>
        <label className={labelCls}>Plugin link</label>
        <input className={input} value={link} onChange={(e) => setLink(e.target.value)} placeholder="https://wordpress.org/plugins/woocommerce/" />
        <p className="mt-1 text-xs text-slate-400">Its wordpress.org page or commercial site.</p>
      </div>

      {SITE_KEY ? <div ref={widgetRef} className="min-h-[65px]" /> : null}

      <button
        type="submit"
        disabled={!canSubmit}
        className="rounded-lg bg-wp-blue px-5 py-2.5 font-semibold text-white hover:bg-wp-blue-dark disabled:opacity-60"
      >
        {busy ? "Reviewing…" : "Submit for review"}
      </button>

      {result && (
        <div
          className={`rounded-lg px-4 py-3 text-sm ${
            result.kind === "ok"
              ? "bg-emerald-50 text-emerald-700"
              : result.kind === "rejected"
              ? "bg-amber-50 text-amber-800"
              : "bg-red-50 text-red-700"
          }`}
        >
          {result.kind === "ok" ? "✓ " : ""}
          {result.message}
        </div>
      )}
    </form>
  );
}
