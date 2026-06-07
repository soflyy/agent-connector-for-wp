export interface UrlCheck {
  ok: boolean;
  status?: number;
  error?: string;
}

/** Block obvious internal/loopback/private hosts to limit SSRF from user-supplied URLs. */
function isPrivateHost(host: string): boolean {
  const h = host.toLowerCase().replace(/^\[|\]$/g, "");
  if (h === "localhost" || h.endsWith(".local") || h.endsWith(".localhost")) return true;
  if (h === "::1" || h.startsWith("fc") || h.startsWith("fd") || h.startsWith("fe80")) return true; // IPv6 loopback / ULA / link-local
  const m = h.match(/^(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})$/);
  if (m) {
    const a = Number(m[1]);
    const b = Number(m[2]);
    if (a === 0 || a === 127 || a === 10) return true;
    if (a === 169 && b === 254) return true;
    if (a === 192 && b === 168) return true;
    if (a === 172 && b >= 16 && b <= 31) return true;
  }
  return false;
}

/**
 * Validate that a user-supplied plugin URL is an http(s) link to a reachable
 * site (not a 404 / error / unreachable host). Best-effort, with a timeout and a
 * basic SSRF guard.
 */
export async function checkPluginUrl(rawUrl: string): Promise<UrlCheck> {
  let url: URL;
  try {
    url = new URL(rawUrl);
  } catch {
    return { ok: false, error: "That doesn't look like a valid URL." };
  }
  if (url.protocol !== "http:" && url.protocol !== "https:") {
    return { ok: false, error: "URL must start with http:// or https://." };
  }
  if (isPrivateHost(url.hostname)) {
    return { ok: false, error: "That URL host isn't allowed." };
  }

  const ctrl = new AbortController();
  const timer = setTimeout(() => ctrl.abort(), 8000);
  try {
    const res = await fetch(url.toString(), {
      method: "GET",
      redirect: "follow",
      signal: ctrl.signal,
      headers: {
        "User-Agent": "AgentReadyPluginsTracker/1.0 (+submission check)",
        Accept: "text/html,application/xhtml+xml,*/*",
      },
    });
    if (res.status >= 400) {
      return { ok: false, status: res.status, error: `The link returned HTTP ${res.status}.` };
    }
    return { ok: true, status: res.status };
  } catch (e) {
    const aborted = e instanceof Error && e.name === "AbortError";
    return { ok: false, error: aborted ? "The link timed out." : "The link couldn't be reached." };
  } finally {
    clearTimeout(timer);
  }
}
