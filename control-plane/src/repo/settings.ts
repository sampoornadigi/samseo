/**
 * Key/value control-plane settings (branding + alerts), with a small cache so
 * the per-request branding lookup is free after the first read.
 */

import { pool } from '../db/pool.js';

export interface Branding {
  name: string;
  logo: string;
  accent: string;
  supportUrl: string;
}

export interface Alerting {
  webhook: string;
  threshold: number;
}

const DEFAULTS: Record<string, string> = {
  brand_name: 'Sampoorna · Control Plane',
  brand_logo: '',
  brand_accent: '#2271b1',
  support_url: '',
  alert_webhook: '',
  alert_threshold: '50',
};

let cache: Record<string, string> | null = null;

/** All settings merged over defaults (cached until invalidated). */
export async function getAll(): Promise<Record<string, string>> {
  if (cache) {
    return cache;
  }
  const { rows } = await pool.query<{ key: string; value: string }>('SELECT key, value FROM cp_settings');
  const merged = { ...DEFAULTS };
  for (const r of rows) {
    merged[r.key] = r.value;
  }
  cache = merged;
  return merged;
}

/** Upsert a set of settings and invalidate the cache. */
export async function setMany(values: Record<string, string>): Promise<void> {
  const entries = Object.entries(values);
  for (const [key, value] of entries) {
    await pool.query(
      `INSERT INTO cp_settings (key, value) VALUES ($1, $2)
       ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value`,
      [key, value],
    );
  }
  cache = null;
}

export async function getBranding(): Promise<Branding> {
  const s = await getAll();
  return { name: s.brand_name, logo: s.brand_logo, accent: s.brand_accent, supportUrl: s.support_url };
}

export async function getAlerting(): Promise<Alerting> {
  const s = await getAll();
  const threshold = Number(s.alert_threshold);
  return { webhook: s.alert_webhook, threshold: Number.isFinite(threshold) ? threshold : 50 };
}
