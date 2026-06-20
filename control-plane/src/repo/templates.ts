/**
 * Data access for config templates (vertical setting bundles).
 *
 * `settings` is a flat map of templatable field -> string value, matching the
 * plugin's ControlPlane\Settings allow-list. Pushing a template turns these into
 * option-type changes deployed over the signed handshake.
 */

import { pool } from '../db/pool.js';

export interface Template {
  id: number;
  name: string;
  vertical: string;
  settings: Record<string, string>;
  created_at: string;
  updated_at: string;
}

export async function list(): Promise<Template[]> {
  const { rows } = await pool.query<Template>(
    'SELECT id, name, vertical, settings, created_at, updated_at FROM templates ORDER BY name, id',
  );
  return rows;
}

export async function getById(id: number): Promise<Template | null> {
  const { rows } = await pool.query<Template>(
    'SELECT id, name, vertical, settings, created_at, updated_at FROM templates WHERE id = $1',
    [id],
  );
  return rows[0] ?? null;
}

export async function create(
  name: string,
  vertical: string,
  settings: Record<string, string>,
): Promise<void> {
  await pool.query('INSERT INTO templates (name, vertical, settings) VALUES ($1, $2, $3)', [
    name,
    vertical,
    JSON.stringify(settings),
  ]);
}

export async function remove(id: number): Promise<void> {
  await pool.query('DELETE FROM templates WHERE id = $1', [id]);
}
