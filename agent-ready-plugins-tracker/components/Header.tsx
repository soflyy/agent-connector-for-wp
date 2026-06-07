import Link from "next/link";

const NAV = [
  { href: "/", label: "Directory" },
  { href: "/submit", label: "Submit" },
  { href: "/disclaimer", label: "Disclaimer" },
];

export function Header() {
  return (
    <header className="border-b border-slate-200 bg-white">
      <div className="mx-auto flex max-w-6xl items-center justify-between gap-4 px-4 py-4">
        <Link href="/" className="flex items-center gap-2.5">
          <span className="flex h-8 w-8 items-center justify-center rounded-lg bg-wp-blue text-sm font-bold text-white">
            AR
          </span>
          <span className="font-semibold text-slate-900">
            Agent Ready Plugins Tracker
          </span>
        </Link>
        <nav className="flex flex-wrap items-center gap-x-6 gap-y-2 text-sm">
          {NAV.map(({ href, label }) => (
            <Link
              key={href}
              href={href}
              className="text-slate-600 transition-colors hover:text-slate-900"
            >
              {label}
            </Link>
          ))}
        </nav>
      </div>
    </header>
  );
}
