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
import type { Change, Finding } from '../repo/pipeline.js';

const STATUS_ROUTE = '/sampoorna-seo/v1/status';
const METRICS_ROUTE = '/sampoorna-seo/v1/metrics';
const AUDIT_ROUTE = '/sampoorna-seo/v1/audit';
const APPLY_ROUTE = '/sampoorna-seo/v1/apply';
const ROLLBACK_ROUTE = '/sampoorna-seo/v1/rollback';
const ACTION_ROUTE = '/sampoorna-seo/v1/action';
const GSC_OPPORTUNITIES_ROUTE = '/sampoorna-seo/v1/gsc/opportunities';

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

/** Signed POST to a site route with a JSON body (signed over the exact body string). */
async function signedPost<T>(
  site: Site,
  secret: string,
  route: string,
  bodyObj: unknown,
): Promise<{ ok: boolean; status: number; data?: T; error?: string }> {
  const body = JSON.stringify(bodyObj);
  const timestamp = String(Math.floor(Date.now() / 1000));
  const signature = sign('POST', route, timestamp, body, secret);
  const url = `${site.reach_url.replace(/\/$/, '')}/wp-json${route}`;
  try {
    const res = await fetch(url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-Sampoorna-Key-Id': site.key_id,
        'X-Sampoorna-Timestamp': timestamp,
        'X-Sampoorna-Signature': signature,
      },
      body,
      signal: AbortSignal.timeout(30000),
    });
    if (!res.ok) {
      return { ok: false, status: res.status, error: `site returned ${res.status}` };
    }
    return { ok: true, status: res.status, data: (await res.json()) as T };
  } catch (err) {
    return { ok: false, status: 0, error: err instanceof Error ? err.message : String(err) };
  }
}

/** Run a signed audit on the site, returning its findings. */
export async function runAudit(
  site: Site,
  secret: string,
): Promise<{ ok: boolean; findings: Finding[]; error?: string }> {
  const r = await signedGet<{ findings: Finding[] }>(site, secret, AUDIT_ROUTE);
  return { ok: r.ok, findings: r.data?.findings ?? [], error: r.error };
}

/** Deploy a reversible changeset to the site. */
export async function deploy(
  site: Site,
  secret: string,
  deployId: string,
  changes: Change[],
): Promise<{ ok: boolean; status: number; data?: unknown; error?: string }> {
  return signedPost(site, secret, APPLY_ROUTE, { deploy_id: deployId, changes });
}

/** A query-dimension GSC row (striking-distance) — `label` is the search query. */
export interface GscQueryRow { label: string; clicks: number; impressions: number; ctr: number; position: number }
/** A page-dimension GSC row (low-CTR pages) — `page_url` is the URL. */
export interface GscPageRow { page_url: string; clicks: number; impressions: number; ctr: number; position: number }
/** A cannibalizing query — `pages` of the site compete for the same `label` term. */
export interface GscCannibalRow { label: string; pages: number; impressions: number; clicks: number; best_position: number }
export interface GscOpportunities {
  connected: boolean;
  property: string;
  strikingDistance: GscQueryRow[];
  lowCtrPages: GscPageRow[];
  cannibalization?: GscCannibalRow[];
}

/** Pull mined GSC search opportunities from the site (plugin >= 0.2.0). */
export async function pullGscOpportunities(
  site: Site,
  secret: string,
): Promise<{ ok: boolean; status: number; data?: GscOpportunities; error?: string }> {
  return signedGet<GscOpportunities>(site, secret, GSC_OPPORTUNITIES_ROUTE);
}

/** Run an allow-listed bulk maintenance action on the site. */
export async function runAction(
  site: Site,
  secret: string,
  action: string,
): Promise<{ ok: boolean; status: number; data?: unknown; error?: string }> {
  return signedPost(site, secret, ACTION_ROUTE, { action });
}

/** Roll a deployment back on the site. */
export async function rollback(
  site: Site,
  secret: string,
  deployId: string,
): Promise<{ ok: boolean; status: number; data?: unknown; error?: string }> {
  return signedPost(site, secret, ROLLBACK_ROUTE, { deploy_id: deployId });
}
