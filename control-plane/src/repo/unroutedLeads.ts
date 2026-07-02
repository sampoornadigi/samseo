/**
 * Leads captured from sites that had no platform_tenant_id yet. Persisted at
 * capture time (instead of being dropped) and replayed onto the outbox as
 * `seo.lead.captured` the moment the site is mapped to a tenant.
 */

import { randomUUID } from 'node:crypto';
import { pool } from '../db/pool.js';

export async function saveUnrouted(siteId: number, payload: Record<string, unknown>): Promise<void> {
  await pool.query('INSERT INTO unrouted_leads (site_id, payload) VALUES ($1, $2)', [
    siteId,
    JSON.stringify(payload),
  ]);
}

/** Pending (never-routed) lead count per site — drives the dashboard badges. */
export async function pendingCounts(): Promise<Map<number, number>> {
  const { rows } = await pool.query<{ site_id: number; n: string }>(
    'SELECT site_id, count(*) AS n FROM unrouted_leads WHERE routed_at IS NULL GROUP BY site_id',
  );
  return new Map(rows.map((r) => [r.site_id, Number(r.n)]));
}

export async function pendingCountFor(siteId: number): Promise<number> {
  const { rows } = await pool.query<{ n: string }>(
    'SELECT count(*) AS n FROM unrouted_leads WHERE routed_at IS NULL AND site_id = $1',
    [siteId],
  );
  return Number(rows[0]?.n ?? 0);
}

/**
 * Replay every pending lead for a freshly-mapped site onto the outbox, in
 * original capture order. Transactional: a lead is marked routed only in the
 * same commit that enqueues it, so a crash can't lose or double-route.
 * Returns the number of leads replayed.
 */
export async function replayForSite(siteId: number, platformTenantId: string): Promise<number> {
  const client = await pool.connect();
  try {
    await client.query('BEGIN');
    const { rows } = await client.query<{ id: string; payload: Record<string, unknown> }>(
      `SELECT id, payload FROM unrouted_leads
        WHERE site_id = $1 AND routed_at IS NULL
        ORDER BY id
        FOR UPDATE SKIP LOCKED`,
      [siteId],
    );
    for (const row of rows) {
      await client.query(
        `INSERT INTO outbox (event_id, type, version, tenant_id, source, data)
         VALUES ($1, 'seo.lead.captured', 1, $2, 'seo', $3)`,
        [`evt_${randomUUID()}`, platformTenantId, JSON.stringify(row.payload)],
      );
      await client.query('UPDATE unrouted_leads SET routed_at = now() WHERE id = $1', [row.id]);
    }
    await client.query('COMMIT');
    return rows.length;
  } catch (err) {
    await client.query('ROLLBACK');
    throw err;
  } finally {
    client.release();
  }
}
