/**
 * Outbound client: the plane calling a site's signed REST endpoints.
 *
 * Proves the plane→site direction of the contract — Node signs, the plugin's
 * Handshake::verify_request verifies. The signed ROUTE is the WP route without
 * /wp-json (matching get_route()), while the fetched URL includes /wp-json.
 */

import { sign } from '../crypto/signer.js';
import type { Descriptor, Site } from '../repo/sites.js';
import type { Signals } from '../score/scorer.js';

const STATUS_ROUTE = '/sampoorna-seo/v1/status';
const METRICS_ROUTE = '/sampoorna-seo/v1/metrics';

/** Signed GET to a site route with an empty body; returns parsed JSON or an error. */
async function signedGet<T>(site: Site, secret: string, route: string): Promise<{ ok: boolean; status: number; data?: T; error?: string }> {
  const timestamp = String(Math.floor(Date.now() / 1000));
  const signature = sign('GET', route, timestamp, '', secret);
  const url = `${site.reach_url.replace(/\/$/, '')}/wp-json${route}`;
  try {
    const res = await fetch(url, {
      method: 'GET',
      headers: {
        'X-Sampoorna-Key-Id': site.key_id,
        'X-Sampoorna-Timestamp': timestamp,
        'X-Sampoorna-Signature': signature,
      },
      signal: AbortSignal.timeout(15000),
    });
    if (!res.ok) {
      return { ok: false, status: res.status, error: `site returned ${res.status}` };
    }
    return { ok: true, status: res.status, data: (await res.json()) as T };
  } catch (err) {
    return { ok: false, status: 0, error: err instanceof Error ? err.message : String(err) };
  }
}

export interface PullResult {
  ok: boolean;
  status: number;
  descriptor?: Descriptor;
  error?: string;
}

export interface MetricsResult {
  ok: boolean;
  status: number;
  signals?: Signals;
  error?: string;
}

/** Fetch a site's descriptor via its signed GET /status endpoint. */
export async function pullStatus(site: Site, secret: string): Promise<PullResult> {
  const r = await signedGet<Descriptor>(site, secret, STATUS_ROUTE);
  return { ok: r.ok, status: r.status, descriptor: r.data, error: r.error };
}

/** Fetch a site's raw health signals via its signed GET /metrics endpoint. */
export async function pullMetrics(site: Site, secret: string): Promise<MetricsResult> {
  const r = await signedGet<Signals>(site, secret, METRICS_ROUTE);
  return { ok: r.ok, status: r.status, signals: r.data, error: r.error };
}
