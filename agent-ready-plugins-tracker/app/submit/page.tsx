import { getAllPlugins } from "@/lib/db";
import { SubmitForm } from "./SubmitForm";
import Link from "next/link";
import type { Plugin } from "@/lib/types";

export const revalidate = 3600;

export default async function SubmitPage() {
  const plugins: Plugin[] = await getAllPlugins();

  return (
    <main className="mx-auto max-w-xl px-4 py-10">
      <div className="mb-8">
        <Link href="/plugins" className="text-sm text-wp-blue hover:underline">
          ← Back to directory
        </Link>
        <h1 className="mt-4 text-3xl font-bold text-slate-900">Submit an update</h1>
        <p className="mt-2 text-slate-600">
          Found a community AI plugin, or know that a developer shipped official
          Abilities? Help keep the directory accurate.
        </p>
      </div>
      <div className="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
        <SubmitForm plugins={plugins} />
      </div>
    </main>
  );
}
