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
import { randomBytes } from 'node:crypto';
import { verifyPlatformJwt } from '../platform/jwksVerify.js';
import { makeToken, setSessionCookie } from '../auth/session.js';
import { resolveSeoSso, SsoDenied } from './sso-user.js';
import { createUser, idForUsername, setUserSites } from '../repo/users.js';
import { siteIdsForTenant } from '../repo/sites.js';
import { hashPassword } from '../crypto/password.js';

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

    // super_admin → unscoped agency session; a CRM client → a 'client' session
    // scoped to their own enrolled sites (see resolveSeoSso).
    try {
      const { username, role } = await resolveSeoSso(principal, {
        ensureUser: (u, r) => createUser(u, hashPassword(randomBytes(24).toString('hex')), r),
        idForUsername,
        siteIdsForTenant,
        setUserSites,
      });
      setSessionCookie(reply, makeToken(username, role));
      return reply.redirect('/');
    } catch (err) {
      if (err instanceof SsoDenied) return reply.code(err.status).send(err.message);
      request.log.error({ err }, 'sso handoff failed');
      return reply.redirect('/login?sso=error');
    }
  });
}
