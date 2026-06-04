import { NextRequest, NextResponse } from "next/server";
import { addSubmission } from "@/lib/db";
import type { Submission } from "@/lib/types";

export async function POST(req: NextRequest) {
  try {
    const body = await req.json();
    const { pluginSlug, type, pluginName, pluginUrl, description, author, email, notes } = body;

    if (!pluginSlug || !type) {
      return NextResponse.json({ error: "pluginSlug and type are required" }, { status: 400 });
    }

    const submission: Submission = {
      id: `${Date.now()}-${Math.random().toString(36).slice(2, 9)}`,
      pluginSlug,
      type,
      submittedAt: new Date().toISOString(),
      submitterEmail: email || undefined,
      notes: notes || undefined,
      reviewed: false,
      data: {
        unofficialPlugin:
          type === "new_unofficial" && pluginName && pluginUrl
            ? { name: pluginName, pluginUrl, description, author }
            : undefined,
      },
    };

    await addSubmission(submission);
    return NextResponse.json({ ok: true });
  } catch (err) {
    console.error("Submit error:", err);
    const message = err instanceof Error ? err.message : "Internal error";
    const isKvError = message.includes("KV not configured");
    return NextResponse.json(
      { error: isKvError ? "Submissions require Vercel KV to be configured." : message },
      { status: isKvError ? 503 : 500 }
    );
  }
}
