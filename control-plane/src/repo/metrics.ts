/**
 * Data access for health-score snapshots.
 */

import { pool } from '../db/pool.js';
import type { Scores, Signals } from '../score/scorer.js';

export interface Snapshot {
  scores: Scores;
  signals: Signals;
  overall: number | null;
  captured_at: string;
}

export async function insertSnapshot(
  siteId: number,
  signals: Signals,
  scores: Scores,
): Promise<void> {
  await pool.query(
    `INSERT INTO site_metrics (site_id, signals, scores, overall)
     VALUES ($1, $2, $3, $4)`,
    [siteId, JSON.stringify(signals), JSON.stringify(scores), scores.overall],
  );
}

export async function latestForSite(siteId: number): Promise<Snapshot | null> {
  const { rows } = await pool.query<Snapshot>(
    `SELECT scores, signals, overall, captured_at
       FROM site_metrics
      WHERE site_id = $1
      ORDER BY captured_at DESC
      LIMIT 1`,
    [siteId],
  );
  return rows[0] ?? null;
}

/** Latest overall + capture time per site, keyed by site_id (for the list view). */
export async function latestOverallBySite(): Promise<Map<number, { overall: number | null; captured_at: string }>> {
  const { rows } = await pool.query<{ site_id: number; overall: number | null; captured_at: string }>(
    `SELECT DISTINCT ON (site_id) site_id, overall, captured_at
       FROM site_metrics
      ORDER BY site_id, captured_at DESC`,
  );
  const map = new Map<number, { overall: number | null; captured_at: string }>();
  for (const r of rows) {
    map.set(r.site_id, { overall: r.overall, captured_at: r.captured_at });
  }
  return map;
}

export async function snapshotCount(siteId: number): Promise<number> {
  const { rows } = await pool.query<{ n: string }>(
    'SELECT COUNT(*)::text AS n FROM site_metrics WHERE site_id = $1',
    [siteId],
  );
  return Number(rows[0]?.n ?? 0);
}
