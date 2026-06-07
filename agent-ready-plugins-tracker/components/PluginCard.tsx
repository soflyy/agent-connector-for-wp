import Link from "next/link";
import type { PluginWithStatus } from "@/lib/types";
import { StatusBadge } from "./StatusBadge";
import { CATEGORY_LABELS } from "@/lib/types";

interface Props {
  plugin: PluginWithStatus;
}

export function PluginCard({ plugin }: Props) {
  const { slug, name, tagline, isPremium, categories, activeInstalls, aiStatus } =
    plugin;

  const hasUnofficial =
    aiStatus.level === "unofficial" && aiStatus.unofficialPlugins.length > 0;

  return (
    <Link
      href={`/plugins/${slug}`}
      className="group flex flex-col rounded-xl border border-slate-200 bg-white p-5 shadow-sm transition-all hover:border-wp-blue hover:shadow-md"
    >
      {/* Header */}
      <div className="mb-3 flex items-start justify-between gap-3">
        <div className="min-w-0">
          <div className="flex items-center gap-2">
            <h3 className="truncate font-semibold text-slate-900 group-hover:text-wp-blue">
              {name}
            </h3>
            {isPremium && (
              <span className="shrink-0 rounded bg-purple-100 px-1.5 py-0.5 text-xs font-medium text-purple-700">
                Premium
              </span>
            )}
          </div>
          <p className="mt-0.5 line-clamp-2 text-sm text-slate-500">{tagline}</p>
        </div>
        <StatusBadge level={aiStatus.level} size="sm" />
      </div>

      {/* Categories */}
      <div className="mb-3 flex flex-wrap gap-1">
        {categories.map((cat) => (
          <span
            key={cat}
            className="rounded bg-slate-100 px-2 py-0.5 text-xs text-slate-600"
          >
            {CATEGORY_LABELS[cat]}
          </span>
        ))}
      </div>

      {/* Footer */}
      <div className="mt-auto flex items-center justify-between border-t border-slate-100 pt-3 text-xs text-slate-400">
        <span>{activeInstalls} installs</span>
        {aiStatus.level === "official" && aiStatus.abilitiesCount && (
          <span className="text-emerald-600">
            {aiStatus.abilitiesCount} abilities
          </span>
        )}
        {hasUnofficial && (
          <span className="text-amber-600">
            {aiStatus.unofficialPlugins!.length} unofficial pack
            {aiStatus.unofficialPlugins!.length !== 1 ? "s" : ""}
          </span>
        )}
      </div>
    </Link>
  );
}
