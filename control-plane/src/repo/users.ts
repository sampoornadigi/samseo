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

export async function listUsers(): Promise<Array<{ username: string; role: string; created_at: string }>> {
  const { rows } = await pool.query<{ username: string; role: string; created_at: string }>(
    'SELECT username, role, created_at FROM cp_users ORDER BY username',
  );
  return rows;
}

export async function countUsers(): Promise<number> {
  const { rows } = await pool.query<{ n: string }>('SELECT COUNT(*)::text AS n FROM cp_users');
  return Number(rows[0]?.n ?? 0);
}
