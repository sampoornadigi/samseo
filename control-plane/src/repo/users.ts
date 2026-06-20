/**
 * Data access for control-plane operator accounts.
 */

import { pool } from '../db/pool.js';

export interface User {
  id: number;
  username: string;
  password_hash: string;
  role: string;
  created_at: string;
}

export async function findByUsername(username: string): Promise<User | null> {
  const { rows } = await pool.query<User>('SELECT * FROM cp_users WHERE username = $1', [username]);
  return rows[0] ?? null;
}

export async function createUser(username: string, passwordHash: string, role: string): Promise<void> {
  await pool.query(
    'INSERT INTO cp_users (username, password_hash, role) VALUES ($1, $2, $3) ON CONFLICT (username) DO NOTHING',
    [username, passwordHash, role],
  );
}

export async function listUsers(): Promise<Array<{ id: number; username: string; role: string; created_at: string }>> {
  const { rows } = await pool.query<{ id: number; username: string; role: string; created_at: string }>(
    'SELECT id, username, role, created_at FROM cp_users ORDER BY username',
  );
  return rows;
}

/** Site ids a client user is scoped to (empty for admin/viewer — they are unscoped). */
export async function siteIdsForUsername(username: string): Promise<number[]> {
  const { rows } = await pool.query<{ site_id: number }>(
    `SELECT us.site_id
       FROM cp_user_sites us
       JOIN cp_users u ON u.id = us.user_id
      WHERE u.username = $1`,
    [username],
  );
  return rows.map((r) => r.site_id);
}

/** Map of user_id -> assigned site ids (for the Users admin screen). */
export async function siteAssignments(): Promise<Map<number, number[]>> {
  const { rows } = await pool.query<{ user_id: number; site_id: number }>(
    'SELECT user_id, site_id FROM cp_user_sites',
  );
  const map = new Map<number, number[]>();
  for (const r of rows) {
    map.set(r.user_id, [...(map.get(r.user_id) ?? []), r.site_id]);
  }
  return map;
}

/** Replace a user's assigned sites. */
export async function setUserSites(userId: number, siteIds: number[]): Promise<void> {
  await pool.query('DELETE FROM cp_user_sites WHERE user_id = $1', [userId]);
  for (const siteId of siteIds) {
    await pool.query(
      'INSERT INTO cp_user_sites (user_id, site_id) VALUES ($1, $2) ON CONFLICT DO NOTHING',
      [userId, siteId],
    );
  }
}

export async function idForUsername(username: string): Promise<number | null> {
  const { rows } = await pool.query<{ id: number }>('SELECT id FROM cp_users WHERE username = $1', [username]);
  return rows[0]?.id ?? null;
}

export async function countUsers(): Promise<number> {
  const { rows } = await pool.query<{ n: string }>('SELECT COUNT(*)::text AS n FROM cp_users');
  return Number(rows[0]?.n ?? 0);
}
