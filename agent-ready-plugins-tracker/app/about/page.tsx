import Link from "next/link";
import type { Metadata } from "next";

export const metadata: Metadata = {
  title: "About — WP AI Ready",
  description:
    "What WP AI Ready is, why it exists, and the goal: a world where every WordPress plugin ships official AI Abilities and this directory is no longer needed.",
};

const REPO = "https://github.com/soflyy/agent-connector-for-wp";

export default function AboutPage() {
  return (
    <main className="mx-auto max-w-2xl px-4 py-12">
      <div className="mb-10">
        <Link href="/plugins" className="text-sm text-wp-blue hover:underline">
          ← Back to directory
        </Link>
        <h1 className="mt-4 text-3xl font-bold tracking-tight text-slate-900">
          About WP AI Ready
        </h1>
        <p className="mt-3 text-lg text-slate-600">
          A community directory tracking which WordPress plugins can be driven by
          AI agents — and a project quietly working to make itself obsolete.
        </p>
      </div>

      <div className="space-y-8 text-slate-700">
        <section>
          <h2 className="mb-3 text-xl font-semibold text-slate-900">
            What this directory is
          </h2>
          <p className="leading-relaxed">
            WP AI Ready tracks the AI-readiness of WordPress plugins. For each
            plugin we record whether it exposes{" "}
            <strong>WordPress AI Abilities</strong> — structured actions an AI
            agent can invoke over MCP — and, crucially, whether those abilities
            are <strong>official</strong> (shipped by the plugin's own
            developers) or <strong>unofficial</strong> (provided by a third
            party on the plugin's behalf).
          </p>
          <p className="mt-3 leading-relaxed">
            The unofficial abilities are{" "}
            <strong>auto-generated MCP surfaces</strong>. Using the{" "}
            <code className="rounded bg-slate-100 px-1.5 py-0.5 text-sm text-slate-800">
              wp-mcp-generator
            </code>{" "}
            skill, we turn an ordinary WordPress plugin into a full-coverage set
            of abilities an agent can drive — installed, understood from its own
            code, and verified to actually work — then publish that as an{" "}
            <em>ability pack</em>. This directory keeps track of which plugins
            already have official abilities and which only have these generated,
            stop-gap packs.
          </p>
        </section>

        <section className="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
          <h2 className="mb-3 text-xl font-semibold text-slate-900">
            The goal: to become unnecessary
          </h2>
          <p className="leading-relaxed">
            We want every WordPress plugin to ship its own official AI
            Abilities. When that happens, the unofficial ability packs, the{" "}
            <a href={REPO} className="text-wp-blue hover:underline">
              Agent Connector for WP
            </a>{" "}
            plugin, and this very directory should all be{" "}
            <strong>deprecated</strong>. Success for this project looks like
            shutting it down because it's no longer needed — every plugin AI
            ready out of the box, and nothing here left to track.
          </p>
          <p className="mt-3 leading-relaxed">
            Until then, this directory is the honest scoreboard: it shows where
            official support exists, where the community has filled the gap, and
            where there's still work to do.
          </p>
        </section>

        <section>
          <h2 className="mb-3 text-xl font-semibold text-slate-900">
            It's all open source
          </h2>
          <p className="leading-relaxed">
            Everything — this site, the generator skill, the Agent Connector
            plugin, and every generated ability pack — lives in one open-source
            monorepo:
          </p>
          <p className="mt-3">
            <a
              href={REPO}
              className="font-medium text-wp-blue hover:underline"
            >
              github.com/soflyy/agent-connector-for-wp
            </a>
          </p>
          <p className="mt-3 leading-relaxed">
            We need contributions — see the{" "}
            <Link href="/contribute" className="text-wp-blue hover:underline">
              Contribute
            </Link>{" "}
            page for what we're looking for and how to help.
          </p>
        </section>

        <section>
          <h2 className="mb-3 text-xl font-semibold text-slate-900">Donations</h2>
          <p className="leading-relaxed">
            We are <strong>not</strong> currently taking donations. If the
            server and AI generation costs ever get out of control we may start,
            but for now the best way to support the project is to{" "}
            <Link href="/contribute" className="text-wp-blue hover:underline">
              contribute
            </Link>
            .
          </p>
        </section>

        <section>
          <p className="text-sm text-slate-500">
            A note on accuracy: much of the content and code behind this project
            is AI-generated. Please read the{" "}
            <Link href="/disclaimer" className="text-wp-blue hover:underline">
              disclaimer
            </Link>
            .
          </p>
        </section>
      </div>
    </main>
  );
}
