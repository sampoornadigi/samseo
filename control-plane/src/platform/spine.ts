/**
 * Runtime wiring for the event spine (P-D13). A periodic tick relays the outbox
 * to Redis Streams and pumps the consumers. Gated by EVENTS_ENABLED so it's
 * opt-in until the shared Redis is configured.
 */

import IORedis, { type Redis } from 'ioredis';
import { pool } from '../db/pool.js';
import { relayBatch } from './outbox.js';
import { pump } from './consumer.js';
import type { PlatformEvent } from './events.js';

interface Logger {
  info: (msg: string) => void;
  error: (msg: string) => void;
}

let redis: Redis | null = null;
function getRedis(): Redis {
  if (!redis) {
    redis = new IORedis({
      host: process.env.REDIS_HOST || 'localhost',
      port: Number(process.env.REDIS_PORT || 6379),
      maxRetriesPerRequest: null,
    });
  }
  return redis;
}

/** Consume identity.tenant.created — a tenant exists upstream; a site is created
 *  later via the signed WP handshake, so we only record provisioning here. */
async function onTenantCreated(event: PlatformEvent, log: Logger): Promise<void> {
  log.info(`identity.tenant.created: tenant ${event.tenantId} provisioned upstream`);
}

/**
 * Start the event spine. Relays produced events (seo.lead.captured) and consumes
 * identity.tenant.created. No-op unless EVENTS_ENABLED=true.
 */
export function startEventSpine(log: Logger): NodeJS.Timeout | null {
  if (process.env.EVENTS_ENABLED !== 'true') {
    log.info('event spine disabled (EVENTS_ENABLED!=true)');
    return null;
  }
  const r = getRedis();
  log.info('event spine enabled: every 10s (relay + identity.tenant.created consumer)');
  const timer = setInterval(() => {
    (async () => {
      await relayBatch(pool, r);
      await pump(pool, r, 'identity.tenant.created', 'seo-provision', (e) => onTenantCreated(e, log));
    })().catch((e) => log.error(`event spine tick failed: ${e instanceof Error ? e.message : String(e)}`));
  }, 10_000);
  timer.unref();
  return timer;
}
