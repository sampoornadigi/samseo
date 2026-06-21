/**
 * Platform SSO — verify Sampoorna-Identity's RS256 JWTs against the shared JWKS
 * (platform decision P-D7). The SEO control plane consumes platform-issued tokens
 * with only the public JWKS — no shared secret. Maps `tid` (platform tenant id)
 * and `ent` (entitlements) onto a principal.
 */

import { createRemoteJWKSet, jwtVerify, type JWTPayload, type KeyLike, type JWTVerifyGetKey } from 'jose';
import type { FastifyReply, FastifyRequest } from 'fastify';

const ISSUER = process.env.PLATFORM_JWT_ISSUER ?? 'sampoorna-identity';

export interface PlatformPrincipal {
  userId?: string;
  tenantId: string | null;
  role?: string;
  entitlements: string[];
}

let remoteJwks: JWTVerifyGetKey | null = null;
function defaultKeySet(): JWTVerifyGetKey {
  if (!remoteJwks) {
    const url = process.env.PLATFORM_JWKS_URL;
    if (!url) throw new Error('PLATFORM_JWKS_URL is not set');
    remoteJwks = createRemoteJWKSet(new URL(url));
  }
  return remoteJwks;
}

function mapPrincipal(payload: JWTPayload): PlatformPrincipal {
  const ent = (payload as Record<string, unknown>).ent;
  const tid = (payload as Record<string, unknown>).tid ?? (payload as Record<string, unknown>).tenantId;
  return {
    userId: payload.sub,
    tenantId: (tid as string | undefined) ?? null,
    role: (payload as Record<string, unknown>).role as string | undefined,
    entitlements: Array.isArray(ent) ? (ent as string[]) : [],
  };
}

/**
 * Verify a platform JWT. `key` defaults to the remote JWKS; tests pass a local
 * public key so verification is fully offline. Throws if invalid/expired.
 */
export async function verifyPlatformJwt(
  token: string,
  key?: KeyLike | Uint8Array | JWTVerifyGetKey,
): Promise<PlatformPrincipal> {
  const resolved = key ?? defaultKeySet();
  const opts = { issuer: ISSUER, algorithms: ['RS256'] };
  // typeof-narrow so each call matches a single jwtVerify overload (key vs JWKS fn).
  const { payload } =
    typeof resolved === 'function'
      ? await jwtVerify(token, resolved, opts)
      : await jwtVerify(token, resolved, opts);
  return mapPrincipal(payload);
}

/** Fastify preHandler: require a valid platform JWT; attaches request.principal. */
export async function requirePlatformJwt(request: FastifyRequest, reply: FastifyReply): Promise<void> {
  const header = request.headers.authorization ?? '';
  const token = header.startsWith('Bearer ') ? header.slice(7) : null;
  if (!token) {
    await reply.code(401).send({ error: 'missing bearer token' });
    return;
  }
  try {
    (request as FastifyRequest & { principal?: PlatformPrincipal }).principal = await verifyPlatformJwt(token);
  } catch {
    await reply.code(401).send({ error: 'invalid token' });
  }
}
