import type { AIStatusLevel } from "@/lib/types";

const CONFIG: Record<
  AIStatusLevel,
  { label: string; className: string; dot: string; icon: string }
> = {
  official: {
    label: "Official Abilities",
    className: "bg-emerald-100 text-emerald-800 border-emerald-200",
    dot: "bg-emerald-500",
    icon: "✓",
  },
  unofficial: {
    label: "Unofficial Abilities",
    className: "bg-amber-100 text-amber-800 border-amber-200",
    dot: "bg-amber-500",
    icon: "~",
  },
  none: {
    label: "Unknown",
    className: "bg-slate-100 text-slate-600 border-slate-200",
    dot: "bg-slate-400",
    icon: "–",
  },
};

interface Props {
  level: AIStatusLevel;
  size?: "sm" | "md";
}

export function StatusBadge({ level, size = "md" }: Props) {
  const c = CONFIG[level] ?? CONFIG.none;
  const textSize = size === "sm" ? "text-xs" : "text-sm";
  const padding = size === "sm" ? "px-2 py-0.5" : "px-2.5 py-1";

  return (
    <span
      className={`inline-flex items-center gap-1.5 rounded-full border font-medium ${textSize} ${padding} ${c.className}`}
    >
      <span className={`h-1.5 w-1.5 rounded-full ${c.dot}`} />
      {c.label}
    </span>
  );
}

export function StatusIcon({ level }: { level: AIStatusLevel }) {
  const c = CONFIG[level] ?? CONFIG.none;
  return (
    <span
      className={`inline-flex h-8 w-8 items-center justify-center rounded-full text-sm font-bold ${c.className}`}
      title={c.label}
    >
      {c.icon}
    </span>
  );
}
