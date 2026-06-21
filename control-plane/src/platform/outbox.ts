/**
 * Transactional outbox writer + relay for the SEO control plane (P-D13).
 * enqueue() writes the event on the caller's pg client (same tx as the business
 * change). relayBatch() publishes unsent rows to Redis Streams and marks them
 * sent (retained for replay); at-least-once, so consumers dedupe on event.id.
 */

import { randomUUID } from 'node:crypto';
import type { Pool, PoolClient } from 'pg';
import type { Redis } from 'ioredis';
import { EVENT_SOURCE } from './events.js';

export interface EnqueueInput {
  type: string;
  tenantId: string;
  data: Record<string, unknown>;
  version?: number;
}

/** Enqueue an event on an existing pg client/transaction. Returns the event id. */
export async function enqueue(client: PoolClient, input: EnqueueInput): Promise<string> {
  const eventId = `evt_${randomUUID()}`;
  await client.query(
    `INSERT INTO outbox (event_id, type, version, tenant_id, source, data)
     VALUES ($1, $2, $3, $4, $5, $6)`,
    [eventId, input.type, input.version ?? 1, input.tenantId, EVENT_SOURCE, JSON.stringify(input.data)],
  );
  return eventId;
}

interface OutboxRow {
  id: string;
  event_id: string;
  type: string;
  version: number;
  tenant_id: string;
  source: string;
  data: Record<string, unknown>;
  occurred_at: Date;
}

/** Publish up to `limit` unsent outbox rows to Redis Streams. Returns the count. */
export async function relayBatch(pool: Pool, redis: Redis, limit = 100): Promise<number> {
  const { rows } = await pool.query<OutboxRow>(
    `SELECT id, event_id, type, version, tenant_id, source, data, occurred_at
     FROM outbox WHERE sent_at IS NULL ORDER BY created_at ASC LIMIT $1`,
    [limit],
  );

  let published = 0;
  for (const row of rows) {
    const event = {
      id: row.event_id,
      type: row.type,
      version: row.version,
      tenantId: row.tenant_id,
      occurredAt: new Date(row.occurred_at).toISOString(),
      source: row.source,
      data: row.data,
    };
    try {
      await redis.xadd(`events:${event.type}`, '*', 'event_id', event.id, 'payload', JSON.stringify(event));
      await pool.query(`UPDATE outbox SET sent_at = now() WHERE id = $1`, [row.id]);
      published += 1;
    } catch (err) {
      await pool.query(`UPDATE outbox SET publish_attempts = publish_attempts + 1 WHERE id = $1`, [row.id]);
      console.error('[seo] outbox publish failed', event.id, (err as Error)?.message);
    }
  }
  return published;
}
