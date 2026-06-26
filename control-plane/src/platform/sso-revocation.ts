/**
 * Cross-product SSO revocation check. The CRM sets `sso:revoked:tenant:<tid>` in
 * the shared Redis (sampark-redis-1) when a tenant leaves 'active'
 * (suspended/archived/purged). We check it at the /sso handoff so a still-valid
 * smp_sso token can't grant SEO access after the tenant was disabled.
 *
 * Fails OPEN (returns false) on any Redis error or when no Redis is configured —
 * the handoff still works during a blip, and the CRM's own per-request enforcement
 * remains authoritative.
 */
import IORedis, { type Redis } from 'ioredis';

let redis: Redis | null = null;
let disabled = false;

function client(): Redis | null {
  if (disabled) return null;
  if (!process.env.REDIS_HOST && !process.env.REDIS_PORT) {
    disabled = true;
    return null;
  }
  if (!redis) {
    redis = new IORedis({
      host: process.env.REDIS_HOST || 'localhost',
      port: Number(process.env.REDIS_PORT || 6379),
      maxRetriesPerRequest: 1,
      // Offline queue ON so the first call (before the connection is ready) waits
      // for it rather than failing open on a cold start; commandTimeout bounds a
      // truly-down Redis so the check still fails open quickly.
      commandTimeout: 1000,
    });
    redis.on('error', () => undefined);
  }
  return redis;
}

/** True if the CRM has revoked SSO for this tenant. Fails open on error. */
export async function isTenantRevoked(tenantId: string): Promise<boolean> {
  const r = client();
  if (!r) return false;
  try {
    return (await r.exists(`sso:revoked:tenant:${tenantId}`)) === 1;
  } catch {
    return false;
  }
}
