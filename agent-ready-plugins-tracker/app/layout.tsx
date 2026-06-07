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
      </body>
    </html>
  );
}
