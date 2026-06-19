/**
 * Stateless signed session cookies for the control-plane dashboard.
 *
 * A cookie is `base64url(payload).base64url(hmacSHA256(payload))` where payload
 * is { u: username, r: role, exp }. No server-side session store — the HMAC
 * (keyed by CP_SESSION_SECRET) makes it tamper-proof, and exp bounds its life.
 */

import { createHmac, timingSafeEqual } from 'node:crypto';
import type { FastifyReply, FastifyRequest } from 'fastify';
import { config } from '../config.js';

export interface SessionUser {
  username: string;
  role: string;
}

const COOKIE = 'cp_session';
const TTL_SECONDS = 8 * 60 * 60;

function sign(body: string): string {
  return createHmac('sha256', config.sessionSecret).update(body).digest('base64url');
}

export function makeToken(username: string, role: string): string {
  const payload = JSON.stringify({ u: username, r: role, exp: Math.floor(Date.now() / 1000) + TTL_SECONDS });
  const body = Buffer.from(payload).toString('base64url');
  return `${body}.${sign(body)}`;
}

export function readToken(token: string): SessionUser | null {
  const [body, sig] = token.split('.');
  if (!body || !sig) {
    return null;
  }
  const expected = sign(body);
  const a = Buffer.from(sig);
  const b = Buffer.from(expected);
  if (a.length !== b.length || !timingSafeEqual(a, b)) {
    return null;
  }
  try {
    const p = JSON.parse(Buffer.from(body, 'base64url').toString('utf8')) as { u?: string; r?: string; exp?: number };
    if (!p.u || !p.r || typeof p.exp !== 'number' || p.exp < Math.floor(Date.now() / 1000)) {
      return null;
    }
    return { username: p.u, role: p.r };
  } catch {
    return null;
  }
}

/** Read + validate the session from a request's Cookie header. */
export function readSession(request: FastifyRequest): SessionUser | null {
  const cookie = request.headers.cookie ?? '';
  const part = cookie
    .split(';')
    .map((s) => s.trim())
    .find((s) => s.startsWith(`${COOKIE}=`));
  if (!part) {
    return null;
  }
  return readToken(part.slice(COOKIE.length + 1));
}

export function setSessionCookie(reply: FastifyReply, token: string): void {
  reply.header('set-cookie', `${COOKIE}=${token}; HttpOnly; Path=/; SameSite=Lax; Max-Age=${TTL_SECONDS}`);
}

export function clearSessionCookie(reply: FastifyReply): void {
  reply.header('set-cookie', `${COOKIE}=; HttpOnly; Path=/; SameSite=Lax; Max-Age=0`);
}
