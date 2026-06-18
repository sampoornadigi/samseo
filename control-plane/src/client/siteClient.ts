/**
 * Outbound client: the plane calling a site's signed REST endpoints.
 *
 * Proves the plane→site direction of the contract — Node signs, the plugin's
 * Handshake::verify_request verifies. The signed ROUTE is the WP route without
 * /wp-json (matching get_route()), while the fetched URL includes /wp-json.
 */

import { sign } from '../crypto/signer.js';
import type { Descriptor, Site } from '../repo/sites.js';

const STATUS_ROUTE = '/sampoorna-seo/v1/status';

export interface PullResult {
  ok: boolean;
  status: number;
  descriptor?: Descriptor;
  error?: string;
}

/** Fetch a site's descriptor via its signed GET /status endpoint. */
export async function pullStatus(site: Site, secret: string): Promise<PullResult> {
  const timestamp = String(Math.floor(Date.now() / 1000));
  const signature = sign('GET', STATUS_ROUTE, timestamp, '', secret);
  const url = `${site.reach_url.replace(/\/$/, '')}/wp-json${STATUS_ROUTE}`;

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
    const descriptor = (await res.json()) as Descriptor;
    return { ok: true, status: res.status, descriptor };
  } catch (err) {
    return { ok: false, status: 0, error: err instanceof Error ? err.message : String(err) };
  }
}
