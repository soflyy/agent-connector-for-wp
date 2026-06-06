import { cookies } from "next/headers";
import { redirect } from "next/navigation";

/** Cookie holding the admin session (its value is the ADMIN_SECRET). */
export const ADMIN_COOKIE = "acfw_admin";

/** Whether the current request carries a valid admin session. */
export async function isAdmin(): Promise<boolean> {
  const secret = process.env.ADMIN_SECRET;
  if (!secret) return false;
  const jar = await cookies();
  return jar.get(ADMIN_COOKIE)?.value === secret;
}

/** Redirect to the admin login unless the request is authenticated. */
export async function requireAdmin(): Promise<void> {
  if (!(await isAdmin())) redirect("/admin/login");
}
