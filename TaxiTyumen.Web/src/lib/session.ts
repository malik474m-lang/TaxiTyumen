// Серверные сессии: HMAC-подписанный токен (аналог JWT из AuthService.cs)
import { createHmac, timingSafeEqual } from "node:crypto";
import { NextResponse } from "next/server";

const SECRET = process.env.AUTH_SECRET ?? "taxi-tyumen-dev-secret-change-me";
const TOKEN_TTL_MS = 24 * 3600 * 1000;

export interface Claims {
  uid: string;
  role: string;
  driverId?: string | null;
  exp: number;
}

export function signToken(input: { uid: string; role: string; driverId?: string | null }): string {
  const payload: Claims = { ...input, driverId: input.driverId ?? null, exp: Date.now() + TOKEN_TTL_MS };
  const body = Buffer.from(JSON.stringify(payload), "utf8").toString("base64url");
  const sig = createHmac("sha256", SECRET).update(body).digest("base64url");
  return `${body}.${sig}`;
}

export function readClaims(req: Request): Claims | null {
  const header = req.headers.get("authorization");
  if (!header?.startsWith("Bearer ")) return null;
  const [body, sig] = header.slice(7).split(".");
  if (!body || !sig) return null;
  const expected = createHmac("sha256", SECRET).update(body).digest("base64url");
  const a = Buffer.from(sig, "utf8");
  const b = Buffer.from(expected, "utf8");
  if (a.length !== b.length || !timingSafeEqual(a, b)) return null;
  try {
    const claims = JSON.parse(Buffer.from(body, "base64url").toString("utf8")) as Claims;
    if (!claims.uid || Date.now() > claims.exp) return null;
    return claims;
  } catch {
    return null;
  }
}

export function unauthorized(message = "Требуется вход") {
  return NextResponse.json({ error: message }, { status: 401 });
}

export function forbidden(message = "Недостаточно прав для этой роли") {
  return NextResponse.json({ error: message }, { status: 403 });
}
