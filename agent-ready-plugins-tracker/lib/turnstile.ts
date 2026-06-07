const VERIFY_URL = "https://challenges.cloudflare.com/turnstile/v0/siteverify";

/** Whether Turnstile is configured (a secret is set). */
export function turnstileConfigured(): boolean {
  return !!process.env.TURNSTILE_SECRET_KEY;
}

/**
 * Verify a Cloudflare Turnstile token server-side. When no secret is configured
 * (e.g. local dev), verification is skipped and returns true so the form still
 * works — set TURNSTILE_SECRET_KEY in production to actually enforce it.
 */
export async function verifyTurnstile(token: string | undefined, ip?: string): Promise<boolean> {
  const secret = process.env.TURNSTILE_SECRET_KEY;
  if (!secret) return true; // not configured — skip (dev convenience)
  if (!token) return false;
  try {
    const form = new URLSearchParams();
    form.set("secret", secret);
    form.set("response", token);
    if (ip) form.set("remoteip", ip);
    const res = await fetch(VERIFY_URL, { method: "POST", body: form });
    const data = (await res.json()) as { success?: boolean };
    return !!data.success;
  } catch {
    return false;
  }
}
