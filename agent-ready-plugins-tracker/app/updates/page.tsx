import Link from "next/link";
import type { Metadata } from "next";

export const metadata: Metadata = {
  title: "Updates — Agent Ready Plugins Tracker",
  description:
    "A running changelog of the Agent Ready Plugins Tracker project — new ability packs, official-support changes, and site updates.",
};

/**
 * Running changelog. Add the newest entry to the top of the array.
 * Dates are ISO (YYYY-MM-DD); keep entries short and factual.
 */
type Update = { date: string; title: string; body: string };

const UPDATES: Update[] = [
  {
    date: "2026-06-07",
    title: "Renamed to Agent Ready Plugins Tracker",
    body: "The project is now the Agent Ready Plugins Tracker. The directory is the homepage, and there's a dedicated Updates page (this one) for project news.",
  },
  {
    date: "2026-06-06",
    title: "About, Contribute, and Disclaimer pages",
    body: "Added pages explaining what the directory is, how to contribute an ability pack, and how AI-generated the project is.",
  },
  {
    date: "2026-06-06",
    title: "Simplified statuses",
    body: "Dropped the 'Coming Soon' status. Plugins are now Official Abilities, Unofficial Abilities, or Unknown.",
  },
];

function formatDate(iso: string) {
  const [y, m, d] = iso.split("-").map(Number);
  const months = [
    "Jan", "Feb", "Mar", "Apr", "May", "Jun",
    "Jul", "Aug", "Sep", "Oct", "Nov", "Dec",
  ];
  return `${months[m - 1]} ${d}, ${y}`;
}

export default function UpdatesPage() {
  return (
    <main className="mx-auto max-w-2xl px-4 py-12">
      <div className="mb-10">
        <Link href="/" className="text-sm text-wp-blue hover:underline">
          ← Back to directory
        </Link>
        <h1 className="mt-4 text-3xl font-bold tracking-tight text-slate-900">
          Updates
        </h1>
        <p className="mt-3 text-lg text-slate-600">
          The latest on the project — new ability packs, official-support
          changes, and site updates.
        </p>
      </div>

      <ol className="space-y-8 border-l border-slate-200 pl-6">
        {UPDATES.map((u, i) => (
          <li key={i} className="relative">
            <span className="absolute -left-[1.6875rem] top-1.5 h-2.5 w-2.5 rounded-full border-2 border-white bg-wp-blue" />
            <time className="text-xs font-medium uppercase tracking-wide text-slate-400">
              {formatDate(u.date)}
            </time>
            <h2 className="mt-1 font-semibold text-slate-900">{u.title}</h2>
            <p className="mt-1 leading-relaxed text-slate-600">{u.body}</p>
          </li>
        ))}
      </ol>
    </main>
  );
}
