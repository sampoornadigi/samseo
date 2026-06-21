/**
 * Single sign-on handoff (P-D15). The platform sets an `smp_sso` cookie on the
 * apex domain at login; this route reads it, verifies it against the shared JWKS,
 * and starts a local control-plane session — so agency staff who signed in at the
 * platform reach the SEO dashboard with no second login.
 *
 * The control plane is an AGENCY tool, so only the platform super_admin (agency
 * scope) is admitted here; client logins use the per-site WordPress plugin.
 */

import type { FastifyInstance, FastifyRequest } from 'fastify';
import { verifyPlatformJwt } from '../platform/jwksVerify.js';
import { makeToken, setSessionCookie } from '../auth/session.js';

const SSO_COOKIE = 'smp_sso';

function readCookie(request: FastifyRequest, name: string): string | null {
  const header = request.headers.cookie;
  if (!header) return null;
  for (const part of header.split(';')) {
    const idx = part.indexOf('=');
    if (idx === -1) continue;
    if (part.slice(0, idx).trim() === name) return decodeURIComponent(part.slice(idx + 1).trim());
  }
  return null;
}

export function registerSso(app: FastifyInstance): void {
  app.get('/sso', async (request: FastifyRequest<{ Querystring: { token?: string } }>, reply) => {
    const token = readCookie(request, SSO_COOKIE) ?? request.query.token ?? null;
    if (!token) return reply.redirect('/login');

    let principal;
    try {
      principal = await verifyPlatformJwt(token);
    } catch {
      return reply.redirect('/login?sso=invalid');
    }

    if (principal.role !== 'super_admin') {
      return reply.code(403).send('The SEO control plane is restricted to agency staff.');
    }

    // Bootstrap a local session from the verified platform identity.
    setSessionCookie(reply, makeToken(principal.userId ?? 'platform-admin', 'admin'));
    return reply.redirect('/');
  });
}
