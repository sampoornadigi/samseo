/**
 * Shared-Redis fixed-window rate limiter for the control plane. The dashboard had
 * NO brute-force protection on its password login; this backs a per-IP limit with
 * the shared Redis (sampark-redis-1) so the count holds across replicas.
 *
 * Fails OPEN: any Redis error (or no Redis configured) allows the request rather
 * than locking admins out — Redis is best-effort here, not a hard dependency.
 */
import IORedis, { type Redis } from 'ioredis';

let redis: Redis | null = null;
let disabled = false;

function client(): Redis | null {
  if (disabled) return null;
  // No Redis configured (local dev without REDIS_HOST) → skip limiting.
  if (!process.env.REDIS_HOST && !process.env.REDIS_PORT) {
    disabled = true;
    return null;
  }
  if (!redis) {
    redis = new IORedis({
      host: process.env.REDIS_HOST || 'localhost',
      port: Number(process.env.REDIS_PORT || 6379),
      maxRetriesPerRequest: null,
      enableOfflineQueue: false,
    });
    redis.on('error', () => undefined); // tolerate blips; exceedsRateLimit fails open
  }
  return redis;
}

// Atomic fixed-window: INCR the bucket, set TTL on the first hit so it expires.
const RL_LUA =
  "local c = redis.call('INCR', KEYS[1]); if c == 1 then redis.call('PEXPIRE', KEYS[1], ARGV[1]) end; return c";

/** True when this id has EXCEEDED `max` hits in the window. Fails open on error. */
export async function exceedsRateLimit(bucket: string, id: string, max: number, windowMs = 60_000): Promise<boolean> {
  const r = client();
  if (!r) return false;
  try {
    const key = `seo:rl:${bucket}:${id}:${Math.floor(Date.now() / windowMs)}`;
    const count = Number(await r.eval(RL_LUA, 1, key, String(windowMs)));
    return count > max;
  } catch {
    return false; // fail open
  }
}

/** Best-effort client IP behind nginx (X-Real-IP at the edge), else first XFF hop. */
export function clientIp(headers: Record<string, unknown>, fallback?: string): string {
  const xr = headers['x-real-ip'];
  if (typeof xr === 'string' && xr.trim()) return xr.trim();
  const xff = headers['x-forwarded-for'];
  if (typeof xff === 'string' && xff.trim()) return xff.split(',')[0].trim();
  return fallback || 'unknown';
}
