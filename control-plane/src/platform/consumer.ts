/**
 * Idempotent Redis Streams consumer for the SEO control plane (P-D13). Dedupes on
 * event.id via processed_events, retries via the pending list, dead-letters after
 * maxAttempts. At-least-once + idempotency = effectively once.
 */

import type { Pool } from 'pg';
import type { Redis } from 'ioredis';
import type { PlatformEvent } from './events.js';

export type EventHandler = (event: PlatformEvent) => Promise<void>;

export interface PumpResult {
  processed: number;
  deduped: number;
  deadLettered: number;
}

interface StreamMessage {
  id: string;
  event: PlatformEvent;
}

async function ensureGroup(redis: Redis, stream: string, group: string): Promise<void> {
  try {
    await redis.xgroup('CREATE', stream, group, '0', 'MKSTREAM');
  } catch (err) {
    if (!String((err as Error)?.message).includes('BUSYGROUP')) throw err;
  }
}

function parseEntries(entries: Array<[string, string[]]> | undefined): StreamMessage[] {
  return (entries ?? []).map(([id, fields]) => {
    const map: Record<string, string> = {};
    for (let i = 0; i < fields.length; i += 2) map[fields[i]] = fields[i + 1];
    return { id, event: JSON.parse(map.payload ?? '{}') as PlatformEvent };
  });
}

async function alreadyProcessed(pool: Pool, group: string, eventId: string): Promise<boolean> {
  const { rowCount } = await pool.query(
    `SELECT 1 FROM processed_events WHERE consumer = $1 AND event_id = $2`,
    [group, eventId],
  );
  return (rowCount ?? 0) > 0;
}

/** Process the currently-available messages for (type, group) once. */
export async function pump(
  pool: Pool,
  redis: Redis,
  type: string,
  group: string,
  handler: EventHandler,
  opts: { maxAttempts?: number; count?: number; blockMs?: number } = {},
): Promise<PumpResult> {
  const maxAttempts = opts.maxAttempts ?? 3;
  const count = opts.count ?? 100;
  const stream = `events:${type}`;
  const consumer = `${group}-1`;
  await ensureGroup(redis, stream, group);

  const claimed = (await redis.xautoclaim(stream, group, consumer, 0, '0', 'COUNT', count)) as [
    string,
    Array<[string, string[]]>,
    string[],
  ];
  const fresh = (await redis.xreadgroup(
    'GROUP',
    group,
    consumer,
    'COUNT',
    count,
    'BLOCK',
    opts.blockMs ?? 200,
    'STREAMS',
    stream,
    '>',
  )) as Array<[string, Array<[string, string[]]>]> | null;

  const messages = [...parseEntries(claimed?.[1]), ...parseEntries(fresh ? fresh[0][1] : [])];

  const result: PumpResult = { processed: 0, deduped: 0, deadLettered: 0 };
  for (const { id: entryId, event } of messages) {
    if (await alreadyProcessed(pool, group, event.id)) {
      await redis.xack(stream, group, entryId);
      result.deduped += 1;
      continue;
    }
    try {
      await handler(event);
      await pool.query(
        `INSERT INTO processed_events (consumer, event_id) VALUES ($1, $2)
         ON CONFLICT (consumer, event_id) DO NOTHING`,
        [group, event.id],
      );
      await redis.xack(stream, group, entryId);
      result.processed += 1;
    } catch (err) {
      const pending = (await redis.xpending(stream, group, entryId, entryId, 1)) as Array<
        [string, string, number, number]
      >;
      const deliveries = pending?.[0]?.[3] ?? 0;
      if (deliveries >= maxAttempts) {
        await pool.query(
          `INSERT INTO event_dlq (consumer, event_id, type, tenant_id, data, error)
           VALUES ($1, $2, $3, $4, $5, $6)`,
          [group, event.id, event.type, event.tenantId, JSON.stringify(event.data), String((err as Error)?.message ?? err)],
        );
        await redis.xack(stream, group, entryId);
        result.deadLettered += 1;
      }
    }
  }
  return result;
}
