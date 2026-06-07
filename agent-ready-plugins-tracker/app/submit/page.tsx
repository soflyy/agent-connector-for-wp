import type { Metadata } from "next";
import SubmitForm from "./SubmitForm";

export const metadata: Metadata = {
  title: "Submit a plugin — Agent Ready Plugins Tracker",
  description: "Submit a WordPress plugin to the directory. Submissions are automatically reviewed.",
};

export const dynamic = "force-dynamic";

export default function SubmitPage() {
  return (
    <main className="mx-auto max-w-xl px-4 py-10">
      <h1 className="text-3xl font-bold tracking-tight text-slate-900">Submit a plugin</h1>
      <p className="mt-2 text-slate-600">
        Suggest a WordPress plugin for the directory. Submissions are automatically reviewed — if it
        checks out as a real plugin, it&apos;s added right away.
      </p>
      <div className="mt-8">
        <SubmitForm />
      </div>
    </main>
  );
}
