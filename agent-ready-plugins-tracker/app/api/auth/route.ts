import { NextRequest, NextResponse } from "next/server";

export async function POST(req: NextRequest) {
  const { password, from } = await req.json();

  if (!process.env.SITE_PASSWORD || !process.env.AUTH_TOKEN_HASH) {
    return NextResponse.json({ error: "Auth not configured" }, { status: 503 });
  }

  if (password !== process.env.SITE_PASSWORD) {
    return NextResponse.json({ error: "Wrong password" }, { status: 401 });
  }

  const res = NextResponse.json({ ok: true, redirect: from || "/" });
  res.cookies.set("auth_token", process.env.AUTH_TOKEN_HASH, {
    httpOnly: true,
    secure: process.env.NODE_ENV === "production",
    sameSite: "lax",
    maxAge: 60 * 60 * 24 * 30, // 30 days
    path: "/",
  });
  return res;
}
