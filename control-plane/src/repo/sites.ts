/**
 * Data access for enrolled sites.
 *
 * The shared secret is encrypted at rest (crypto/vault.ts); callers receive the
 * decrypted secret only via secretFor(), never on the row objects.
 */

import { pool } from '../db/pool.js';
import { decrypt, encrypt } from '../crypto/vault.js';

export interface Site {
  id: number;
  label: string;
  site_url: string;
  reach_url: string;
  key_id: string;
  plugin_version: string | null;
  wp_version: string | null;
  modules: Record<string, unknown>;
  last_seen: string | null;
  created_at: string;
  platform_tenant_id: string | null;
}

const COLUMNS =
  'id, label, site_url, reach_url, key_id, plugin_version, wp_version, modules, last_seen, created_at, platform_tenant_id';

export async function list(): Promise<Site[]> {
  const { rows } = await pool.query<Site>(`SELECT ${COLUMNS} FROM sites ORDER BY label, id`);
  return rows;
}

export async function getById(id: number): Promise<Site | null> {
  const { rows } = await pool.query<Site>(`SELECT ${COLUMNS} FROM sites WHERE id = $1`, [id]);
  return rows[0] ?? null;
}

/** Decrypted shared secret for a key_id, or null when the site is unknown. */
export async function secretFor(keyId: string): Promise<string | null> {
  const { rows } = await pool.query<{ secret_enc: string }>(
    'SELECT secret_enc FROM sites WHERE key_id = $1',
    [keyId],
  );
  if (!rows[0]) {
    return null;
  }
  return decrypt(rows[0].secret_enc);
}

export interface EnrollInput {
  label: string;
  reachUrl: string;
  keyId: string;
  secret: string;
}

/** Add a site by config (manual enrollment). Throws on duplicate key_id. */
export async function enroll(input: EnrollInput): Promise<void> {
  await pool.query(
    `INSERT INTO sites (label, reach_url, key_id, secret_enc)
     VALUES ($1, $2, $3, $4)`,
    [input.label, input.reachUrl, input.keyId, encrypt(input.secret)],
  );
}

export interface Descriptor {
  site_url?: string;
  plugin_version?: string;
  wp_version?: string;
  modules?: Record<string, unknown>;
}

/** Record a fresh descriptor + last_seen for an enrolled site, keyed by key_id. */
export async function applyDescriptor(keyId: string, d: Descriptor): Promise<void> {
  await pool.query(
    `UPDATE sites
        SET site_url       = COALESCE($2, site_url),
            plugin_version = $3,
            wp_version     = $4,
            modules        = $5,
            last_seen      = now()
      WHERE key_id = $1`,
    [
      keyId,
      d.site_url ?? null,
      d.plugin_version ?? null,
      d.wp_version ?? null,
      JSON.stringify(d.modules ?? {}),
    ],
  );
}
