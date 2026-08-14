import { ADMIN_SESSION_COOKIE, adminSessionMaxAge, authenticateAdmin, createAdminSession } from "../../../lib/admin-auth";

const attempts = new Map<string, { count: number; resetAt: number }>();
const MAX_ATTEMPTS = 5;
const WINDOW_MS = 15 * 60 * 1000;

export async function POST(request: Request) {
  if (!hasSameOrigin(request)) return json({ error: "Requête refusée." }, 403);

  const client = request.headers.get("x-forwarded-for")?.split(",")[0]?.trim() ?? request.headers.get("x-real-ip") ?? "unknown";
  if (isRateLimited(client)) return json({ error: "Trop de tentatives. Réessayez dans quelques minutes." }, 429);

  let body: { username?: unknown; password?: unknown };
  try {
    body = await request.json();
  } catch {
    return json({ error: "Identifiants invalides." }, 400);
  }

  const identity = await authenticateAdmin(body.username, body.password);
  if (!identity) {
    registerFailure(client);
    return json({ error: "Identifiants invalides." }, 401);
  }

  const session = await createAdminSession(identity);
  if (!session) return json({ error: "La connexion est indisponible. Vérifiez la configuration du serveur." }, 503);

  attempts.delete(client);
  return json({ identity }, 200, { "Set-Cookie": sessionCookie(session, request) });
}

export function DELETE(request: Request) {
  return json({ ok: true }, 200, { "Set-Cookie": sessionCookie("", request, 0) });
}

function hasSameOrigin(request: Request) {
  const origin = request.headers.get("origin");
  if (!origin) return true;
  try {
    return new URL(origin).origin === new URL(request.url).origin;
  } catch {
    return false;
  }
}

function isRateLimited(client: string) {
  const attempt = attempts.get(client);
  if (!attempt) return false;
  if (attempt.resetAt <= Date.now()) {
    attempts.delete(client);
    return false;
  }
  return attempt.count >= MAX_ATTEMPTS;
}

function registerFailure(client: string) {
  const current = attempts.get(client);
  if (!current || current.resetAt <= Date.now()) {
    attempts.set(client, { count: 1, resetAt: Date.now() + WINDOW_MS });
    return;
  }
  attempts.set(client, { ...current, count: current.count + 1 });
}

function sessionCookie(value: string, request: Request, maxAge = adminSessionMaxAge()) {
  const forwardedProtocol = request.headers.get("x-forwarded-proto");
  const secure = new URL(request.url).protocol === "https:" || forwardedProtocol?.split(",")[0]?.trim() === "https";
  return `${ADMIN_SESSION_COOKIE}=${value}; Path=/; HttpOnly; SameSite=Lax; Max-Age=${maxAge}; ${secure ? "Secure; " : ""}`;
}

function json(payload: object, status: number, headers: HeadersInit = {}) {
  return new Response(JSON.stringify(payload), { status, headers: { "Content-Type": "application/json; charset=utf-8", "Cache-Control": "no-store", ...headers } });
}
