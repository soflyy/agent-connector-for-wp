import type { Metadata } from "next";
import { Inter } from "next/font/google";
import "./globals.css";
import { Header } from "@/components/Header";

const inter = Inter({ subsets: ["latin"] });

export const metadata: Metadata = {
  title: "Agent Ready Plugins Tracker",
  description:
    "Track which WordPress plugins have official AI Abilities, an unofficial auto-generated ability pack, or no AI integration yet.",
  openGraph: {
    title: "Agent Ready Plugins Tracker",
    description: "Which WordPress plugins can AI agents drive?",
    type: "website",
  },
};

export default function RootLayout({
  children,
}: Readonly<{ children: React.ReactNode }>) {
  return (
    <html lang="en">
      <body className={`${inter.className} bg-slate-50 text-slate-900`}>
        <Header />
        {children}
        <footer className="mt-16 border-t border-slate-200 bg-white py-8 text-center text-sm text-slate-400">
          <p>
            Agent Ready Plugins Tracker{" "}
            <span className="px-1">·</span>{" "}
            <a href="/about" className="hover:text-slate-600 hover:underline">
              About
            </a>{" "}
            ·{" "}
            <a href="/updates" className="hover:text-slate-600 hover:underline">
              Updates
            </a>{" "}
            ·{" "}
            <a
              href="/contribute"
              className="hover:text-slate-600 hover:underline"
            >
              Contribute
            </a>{" "}
            ·{" "}
            <a
              href="https://github.com/soflyy/agent-connector-for-wp"
              className="hover:text-slate-600 hover:underline"
            >
              GitHub
            </a>{" "}
            ·{" "}
            <a href="/disclaimer" className="hover:text-slate-600 hover:underline">
              Disclaimer
            </a>
          </p>
        </footer>
      </body>
    </html>
  );
}
