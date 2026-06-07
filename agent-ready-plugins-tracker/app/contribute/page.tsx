import Link from "next/link";
import type { Metadata } from "next";

export const metadata: Metadata = {
  title: "Contribute — WP AI Ready",
  description:
    "How to contribute to WP AI Ready: generate an ability pack for a WordPress plugin with the wp-mcp-generator skill and open a PR to the monorepo.",
};

const REPO = "https://github.com/soflyy/agent-connector-for-wp";

export default function ContributePage() {
  return (
    <main className="mx-auto max-w-2xl px-4 py-12">
      <div className="mb-10">
        <Link href="/plugins" className="text-sm text-wp-blue hover:underline">
          ← Back to directory
        </Link>
        <h1 className="mt-4 text-3xl font-bold tracking-tight text-slate-900">
          Contribute
        </h1>
        <p className="mt-3 text-lg text-slate-600">
          This is a community project, and it only works if people pitch in.
          Here's what we need and how to help.
        </p>
      </div>

      <div className="space-y-8 text-slate-700">
        <section>
          <h2 className="mb-3 text-xl font-semibold text-slate-900">
            What we're looking for
          </h2>
          <ul className="space-y-3">
            <li className="flex gap-3">
              <span className="mt-1 h-1.5 w-1.5 shrink-0 rounded-full bg-wp-blue" />
              <span>
                <strong>New ability packs.</strong> Pick a WordPress plugin that
                doesn't yet have AI Abilities and generate an unofficial pack for
                it. This is the most valuable contribution — every pack makes
                another plugin agent-drivable.
              </span>
            </li>
            <li className="flex gap-3">
              <span className="mt-1 h-1.5 w-1.5 shrink-0 rounded-full bg-wp-blue" />
              <span>
                <strong>Directory updates.</strong> Spotted a plugin that shipped
                official Abilities, or know of a community pack we're missing?{" "}
                <Link href="/submit" className="text-wp-blue hover:underline">
                  Submit an update
                </Link>{" "}
                to keep the scoreboard accurate.
              </span>
            </li>
            <li className="flex gap-3">
              <span className="mt-1 h-1.5 w-1.5 shrink-0 rounded-full bg-wp-blue" />
              <span>
                <strong>Improvements to the tooling.</strong> The generator
                skill, the Agent Connector plugin, and this site all live in the
                same repo and all welcome fixes and features.
              </span>
            </li>
          </ul>
        </section>

        <section className="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
          <h2 className="mb-4 text-xl font-semibold text-slate-900">
            How to contribute an ability pack
          </h2>
          <ol className="space-y-4">
            {[
              {
                title: "Fork the repo",
                body: (
                  <>
                    Fork{" "}
                    <a href={REPO} className="text-wp-blue hover:underline">
                      soflyy/agent-connector-for-wp
                    </a>{" "}
                    on GitHub and clone your fork.
                  </>
                ),
              },
              {
                title: "Generate the pack with the skill",
                body: (
                  <>
                    Use the{" "}
                    <code className="rounded bg-slate-100 px-1.5 py-0.5 text-sm text-slate-800">
                      wp-mcp-generator
                    </code>{" "}
                    skill (in{" "}
                    <code className="rounded bg-slate-100 px-1.5 py-0.5 text-sm text-slate-800">
                      abilities-generator/
                    </code>
                    ) to build the ability pack for your target plugin. The skill
                    installs the plugin, understands it from its own code, designs
                    an agent-ergonomic ability surface, and verifies every ability
                    actually works. A pack isn't done until its coverage report
                    accounts for every surface and every ability passes
                    acceptance.
                  </>
                ),
              },
              {
                title: "Open a PR back to the main repo",
                body: (
                  <>
                    Save the finished pack into{" "}
                    <code className="rounded bg-slate-100 px-1.5 py-0.5 text-sm text-slate-800">
                      ability-packs/
                    </code>
                    , commit it, and open a pull request from your fork against
                    the main repository. We'll review it and, once merged, the new
                    pack shows up in this directory.
                  </>
                ),
              },
            ].map((step, i) => (
              <li key={i} className="flex gap-4">
                <span className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-wp-blue text-sm font-semibold text-white">
                  {i + 1}
                </span>
                <div>
                  <p className="font-medium text-slate-900">{step.title}</p>
                  <p className="mt-1 leading-relaxed">{step.body}</p>
                </div>
              </li>
            ))}
          </ol>
          <p className="mt-5 text-sm text-slate-500">
            Full details, including how to set up a sandbox and the standard for
            what "done" means, are in the repo's{" "}
            <a
              href={`${REPO}/blob/master/abilities-generator/README.md`}
              className="text-wp-blue hover:underline"
            >
              abilities-generator README
            </a>{" "}
            and{" "}
            <a
              href={`${REPO}/blob/master/CONTRIBUTING.md`}
              className="text-wp-blue hover:underline"
            >
              CONTRIBUTING.md
            </a>
            .
          </p>
        </section>

        <section>
          <h2 className="mb-3 text-xl font-semibold text-slate-900">
            About donations
          </h2>
          <p className="leading-relaxed">
            We aren't taking donations right now — contributions of packs and
            code are what move the project forward. If server and AI costs ever
            become unsustainable we may revisit it, but code and packs are always
            the most useful thing you can give.
          </p>
        </section>

        <div className="flex flex-col items-start gap-3 sm:flex-row">
          <a
            href={REPO}
            className="rounded-lg bg-wp-blue px-6 py-3 font-semibold text-white transition-colors hover:bg-wp-blue-dark"
          >
            Open the repo on GitHub
          </a>
          <Link
            href="/about"
            className="rounded-lg border border-slate-300 bg-white px-6 py-3 font-semibold text-slate-700 transition-colors hover:bg-slate-50"
          >
            What is this project?
          </Link>
        </div>
      </div>
    </main>
  );
}
