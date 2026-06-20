/**
 * Last-known alert state per site (for change-detection / de-duplication).
 */

import { pool } from '../db/pool.js';

export type AlertKind = 'ok' | 'low' | 'unreachable';

export async function getState(siteId: number): Promise<AlertKind | null> {
  const { rows } = await pool.query<{ kind: AlertKind }>(
    'SELECT kind FROM alert_state WHERE site_id = $1',
    [siteId],
  );
  return rows[0]?.kind ?? null;
}

export async function setState(siteId: number, kind: AlertKind): Promise<void> {
  await pool.query(
    `INSERT INTO alert_state (site_id, kind, updated_at) VALUES ($1, $2, now())
     ON CONFLICT (site_id) DO UPDATE SET kind = EXCLUDED.kind, updated_at = now()`,
    [siteId, kind],
  );
}
