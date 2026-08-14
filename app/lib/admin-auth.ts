export type AdminIdentity = "MKD" | "ICE";

export const ADMIN_SESSION_COOKIE = "lhorloger_admin_session";
const SESSION_DURATION_SECONDS = 12 * 60 * 60;
const encoder = new TextEncoder();
const decoder = new TextDecoder();

type SessionPayload = { identity: AdminIdentity; expiresAt: number };

export async function authenticateAdmin(username: unknown, password: unknown): Promise<AdminIdentity | null> {
  if (typeof username !== "string" || typeof password !== "string") return null;

  const identity = username.trim().toUpperCase();
  if (identity !== "MKD" && identity !== "ICE") return null;

  const expectedPassword = identity === "MKD" ? process.env.ADMIN_MKD_PASSWORD : process.env.ADMIN_ICE_PASSWORD;
  if (!expectedPassword) return null;

  return (await constantTimeEquals(password, expectedPassword)) ? identity : null;
}

export async function createAdminSession(identity: AdminIdentity): Promise<string | null> {
  const secret = getSessionSecret();
  if (!secret) return null;

  const payload: SessionPayload = {
    identity,
    expiresAt: Date.now() + SESSION_DURATION_SECONDS * 1000,
  };
  const encodedPayload = encodeBase64Url(encoder.encode(JSON.stringify(payload)));
  const signature = await sign(encodedPayload, secret);
  return `${encodedPayload}.${signature}`;
}

export async function verifyAdminSession(value: string | undefined): Promise<SessionPayload | null> {
  const secret = getSessionSecret();
  if (!value || !secret) return null;

  const [encodedPayload, signature] = value.split(".");
  if (!encodedPayload || !signature || value.split(".").length !== 2) return null;
  if (!(await constantTimeEquals(signature, await sign(encodedPayload, secret)))) return null;

  try {
    const payload = JSON.parse(decoder.decode(decodeBase64Url(encodedPayload))) as SessionPayload;
    if ((payload.identity !== "MKD" && payload.identity !== "ICE") || !Number.isFinite(payload.expiresAt) || payload.expiresAt <= Date.now()) return null;
    return payload;
  } catch {
    return null;
  }
}

export const adminSessionMaxAge = () => SESSION_DURATION_SECONDS;

function getSessionSecret() {
  const secret = process.env.ADMIN_SESSION_SECRET;
  return secret && secret.length >= 32 ? secret : null;
}

async function sign(value: string, secret: string) {
  const key = await crypto.subtle.importKey("raw", encoder.encode(secret), { name: "HMAC", hash: "SHA-256" }, false, ["sign"]);
  const signature = await crypto.subtle.sign("HMAC", key, encoder.encode(value));
  return encodeBase64Url(new Uint8Array(signature));
}

async function constantTimeEquals(left: string, right: string) {
  const [leftDigest, rightDigest] = await Promise.all([digest(left), digest(right)]);
  let difference = 0;
  for (let index = 0; index < leftDigest.length; index += 1) difference |= leftDigest[index] ^ rightDigest[index];
  return difference === 0;
}

async function digest(value: string) {
  return new Uint8Array(await crypto.subtle.digest("SHA-256", encoder.encode(value)));
}

function encodeBase64Url(bytes: Uint8Array) {
  let binary = "";
  bytes.forEach((byte) => { binary += String.fromCharCode(byte); });
  return btoa(binary).replace(/\+/g, "-").replace(/\//g, "_").replace(/=+$/, "");
}

function decodeBase64Url(value: string) {
  const normalized = value.replace(/-/g, "+").replace(/_/g, "/");
  const padded = normalized + "=".repeat((4 - normalized.length % 4) % 4);
  const binary = atob(padded);
  return Uint8Array.from(binary, (character) => character.charCodeAt(0));
}
