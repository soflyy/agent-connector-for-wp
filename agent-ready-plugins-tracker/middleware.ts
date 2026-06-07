import { NextRequest, NextResponse } from "next/server";

// /admin self-gates on the ADMIN_SECRET (see lib/admin-auth), independent of the
// public site password; /api/admin routes carry their own checks.
const PUBLIC_PATHS = ["/login", "/api/auth", "/api/ability-packs", "/admin", "/api/admin"];

export function middleware(req: NextRequest) {
  const { pathname } = req.nextUrl;

  // Allow login page and auth API through
  if (PUBLIC_PATHS.some((p) => pathname.startsWith(p))) {
    return NextResponse.next();
  }

  const token = req.cookies.get("auth_token")?.value;
  const expected = process.env.AUTH_TOKEN_HASH;

  if (!expected || token !== expected) {
    const loginUrl = req.nextUrl.clone();
    loginUrl.pathname = "/login";
    loginUrl.searchParams.set("from", pathname);
    return NextResponse.redirect(loginUrl);
  }

  return NextResponse.next();
}

export const config = {
  matcher: ["/((?!_next/static|_next/image|favicon.ico).*)"],
};
