import Link from "next/link";

export function Header() {
  return (
    <header className="border-b border-slate-200 bg-white">
      <div className="mx-auto flex max-w-6xl items-center justify-between px-4 py-4">
        <Link href="/" className="flex items-center gap-2.5">
          <span className="flex h-8 w-8 items-center justify-center rounded-lg bg-wp-blue text-sm font-bold text-white">
            AI
          </span>
          <span className="font-semibold text-slate-900">WP AI Ready</span>
        </Link>
        <nav className="flex items-center gap-6 text-sm">
          <Link
            href="/plugins"
            className="text-slate-600 transition-colors hover:text-slate-900"
          >
            Directory
          </Link>
          <Link
            href="/submit"
            className="rounded-lg bg-wp-blue px-3.5 py-1.5 font-medium text-white transition-colors hover:bg-wp-blue-dark"
          >
            Submit a Plugin
          </Link>
        </nav>
      </div>
    </header>
  );
}
