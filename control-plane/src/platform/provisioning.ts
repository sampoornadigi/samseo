/**
 * Auto-provisioning: when a site is enrolled + mapped to a CRM tenant, fetch that
 * tenant's platform embed keys and push them to the WordPress site so the single
 * Sampoorna plugin lights up the CRM chat widget AND the AdSync analytics SDK with
 * no manual key-pasting.
 *
 * Keys come from each product's M2M endpoint, over the same service-token channel
 * (role ai_system): the CRM (PLATFORM_BILLING_URL) returns the widget key, AdSync
 * (PLATFORM_ADSYNC_URL) the analytics key. Best-effort: a missing URL/token, an
 * unreachable service, or an old plugin (which ignores unknown option fields) all
 * degrade quietly — enrollment still succeeds.
 */

import { randomUUID } from 'node:crypto';
import { deploy } from '../client/siteClient.js';
import type { Site } from '../repo/sites.js';
import type { Change } from '../repo/pipeline.js';

interface ProvisioningKeys {
  widgetKey: string | null;
  analyticsKey: string | null;
}

/** Signed-with-service-token GET to a product's /platform/provisioning/:tenantId. */
async function fetchKey<T extends Record<string, unknown>>(
  baseEnv: string | undefined,
  tenantId: string,
  label: string,
): Promise<T | null> {
  const token = process.env.PLATFORM_SERVICE_TOKEN;
  if (!baseEnv || !token) return null;
  try {
    const res = await fetch(`${baseEnv.replace(/\/$/, '')}/platform/provisioning/${encodeURIComponent(tenantId)}`, {
      headers: { authorization: `Bearer ${token}` },
      signal: AbortSignal.timeout(10_000),
    });
    if (!res.ok) {
      console.error(`[provisioning] ${label} fetch failed (${res.status})`);
      return null;
    }
    return (await res.json()) as T;
  } catch (err) {
    console.error(`[provisioning] ${label} fetch error`, err instanceof Error ? err.message : String(err));
    return null;
  }
}

async function fetchKeys(tenantId: string): Promise<ProvisioningKeys> {
  const [crm, ads] = await Promise.all([
    fetchKey<{ widgetKey?: string | null }>(process.env.PLATFORM_BILLING_URL, tenantId, 'CRM'),
    fetchKey<{ analyticsKey?: string | null }>(process.env.PLATFORM_ADSYNC_URL, tenantId, 'AdSync'),
  ]);
  return { widgetKey: crm?.widgetKey ?? null, analyticsKey: ads?.analyticsKey ?? null };
}

/**
 * Push the tenant's embed keys to a site via the signed /apply deploy (as `option`
 * changes the plugin's Settings allow-list accepts). Returns which fields landed.
 */
export async function provisionSite(
  site: Pick<Site, 'reach_url' | 'key_id'>,
  secret: string,
  tenantId: string | null,
): Promise<{ ok: boolean; pushed: string[]; error?: string }> {
  if (!tenantId) return { ok: false, pushed: [], error: 'site is not mapped to a tenant' };
  const keys = await fetchKeys(tenantId);

  const changes: Change[] = [];
  if (keys.widgetKey) changes.push({ type: 'option', id: 0, field: 'widget_key', value: keys.widgetKey });
  if (keys.analyticsKey) changes.push({ type: 'option', id: 0, field: 'analytics_key', value: keys.analyticsKey });
  if (changes.length === 0) return { ok: true, pushed: [] };

  const r = await deploy(site as Site, secret, `prov_${randomUUID()}`, changes);
  return { ok: r.ok, pushed: r.ok ? changes.map((c) => c.field) : [], error: r.error };
}
