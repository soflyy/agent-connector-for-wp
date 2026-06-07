"use client";

import { useState, useMemo } from "react";
import type { AIStatusLevel, PluginCategory, PluginWithStatus } from "@/lib/types";
import { CATEGORY_LABELS } from "@/lib/types";
import { PluginCard } from "./PluginCard";

const STATUS_FILTERS: { value: AIStatusLevel | "all"; label: string }[] = [
  { value: "all", label: "All" },
  { value: "official", label: "Official Abilities" },
  { value: "unofficial", label: "Unofficial Abilities" },
  { value: "none", label: "Unknown" },
];

interface Props {
  plugins: PluginWithStatus[];
}

export function PluginDirectory({ plugins }: Props) {
  const [query, setQuery] = useState("");
  const [statusFilter, setStatusFilter] = useState<AIStatusLevel | "all">("all");
  const [categoryFilter, setCategoryFilter] = useState<PluginCategory | "all">("all");

  const allCategories = useMemo(() => {
    const cats = new Set<PluginCategory>();
    plugins.forEach((p) => p.categories.forEach((c) => cats.add(c)));
    return Array.from(cats).sort();
  }, [plugins]);

  const filtered = useMemo(() => {
    return plugins.filter((p) => {
      if (query) {
        const q = query.toLowerCase();
        if (
          !p.name.toLowerCase().includes(q) &&
          !p.tagline.toLowerCase().includes(q) &&
          !p.author.toLowerCase().includes(q)
        )
          return false;
      }
      if (statusFilter !== "all" && p.aiStatus.level !== statusFilter) return false;
      if (categoryFilter !== "all" && !p.categories.includes(categoryFilter))
        return false;
      return true;
    });
  }, [plugins, query, statusFilter, categoryFilter]);

  const counts = useMemo(() => {
    const c = { official: 0, unofficial: 0, none: 0 };
    plugins.forEach((p) => {
      if (p.aiStatus.level in c) c[p.aiStatus.level as keyof typeof c]++;
    });
    return c;
  }, [plugins]);

  return (
    <div>
      {/* Stats bar */}
      <div className="mb-6 grid grid-cols-3 gap-3">
        {[
          { label: "Official Abilities", count: counts.official, color: "text-emerald-600" },
          { label: "Unofficial Abilities", count: counts.unofficial, color: "text-amber-600" },
          { label: "Unknown", count: counts.none, color: "text-slate-500" },
        ].map(({ label, count, color }) => (
          <div
            key={label}
            className="rounded-lg border border-slate-200 bg-white px-4 py-3 text-center"
          >
            <p className={`text-2xl font-bold ${color}`}>{count}</p>
            <p className="text-xs text-slate-500">{label}</p>
          </div>
        ))}
      </div>

      {/* Search + filters */}
      <div className="mb-6 flex flex-col gap-3 sm:flex-row sm:items-center">
        <div className="relative flex-1">
          <svg
            className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400"
            fill="none"
            stroke="currentColor"
            viewBox="0 0 24 24"
          >
            <path
              strokeLinecap="round"
              strokeLinejoin="round"
              strokeWidth={2}
              d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"
            />
          </svg>
          <input
            type="text"
            placeholder="Search plugins..."
            value={query}
            onChange={(e) => setQuery(e.target.value)}
            className="w-full rounded-lg border border-slate-300 py-2.5 pl-9 pr-4 text-sm focus:border-wp-blue focus:outline-none focus:ring-1 focus:ring-wp-blue"
          />
        </div>
        <select
          value={categoryFilter}
          onChange={(e) =>
            setCategoryFilter(e.target.value as PluginCategory | "all")
          }
          className="rounded-lg border border-slate-300 px-3 py-2.5 text-sm focus:border-wp-blue focus:outline-none focus:ring-1 focus:ring-wp-blue"
        >
          <option value="all">All Categories</option>
          {allCategories.map((cat) => (
            <option key={cat} value={cat}>
              {CATEGORY_LABELS[cat]}
            </option>
          ))}
        </select>
      </div>

      {/* Status tabs */}
      <div className="mb-6 flex flex-wrap gap-2">
        {STATUS_FILTERS.map(({ value, label }) => (
          <button
            key={value}
            onClick={() => setStatusFilter(value)}
            className={`rounded-full px-4 py-1.5 text-sm font-medium transition-colors ${
              statusFilter === value
                ? "bg-wp-blue text-white"
                : "bg-slate-100 text-slate-600 hover:bg-slate-200"
            }`}
          >
            {label}
            {value !== "all" && (
              <span className="ml-1.5 text-xs opacity-75">
                {value === "official"
                  ? counts.official
                  : value === "unofficial"
                  ? counts.unofficial
                  : counts.none}
              </span>
            )}
          </button>
        ))}
      </div>

      {/* Grid */}
      {filtered.length === 0 ? (
        <div className="rounded-xl border border-dashed border-slate-300 py-16 text-center">
          <p className="text-slate-500">No plugins match your filters.</p>
          <button
            onClick={() => {
              setQuery("");
              setStatusFilter("all");
              setCategoryFilter("all");
            }}
            className="mt-2 text-sm text-wp-blue hover:underline"
          >
            Clear filters
          </button>
        </div>
      ) : (
        <>
          <p className="mb-4 text-sm text-slate-500">
            Showing {filtered.length} of {plugins.length} plugins
          </p>
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {filtered.map((plugin) => (
              <PluginCard key={plugin.slug} plugin={plugin} />
            ))}
          </div>
        </>
      )}
    </div>
  );
}
