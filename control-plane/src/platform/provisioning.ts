/**
 * Auto-provisioning: when a site is enrolled + mapped to a CRM tenant, fetch that
 * tenant's platform embed keys and push them to the WordPress site so the single
 * Sampoorna plugin lights up the CRM chat widget (and, once AdSync exposes it, the
 * analytics SDK) with no manual key-pasting.
 *
 * Reuses the same service-token M2M channel as billing (PLATFORM_BILLING_URL +
 * PLATFORM_SERVICE_TOKEN, role ai_system). Best-effort: a missing token, an
 * unreachable site, or an old plugin (which ignores unknown option fields) all
 * degrade quietly — enrollment still succeeds.
 */

import { randomUUID } from 'node:crypto';
import { deploy } from '../client/siteClient.js';
import type { Site } from '../repo/sites.js';
import type { Change } from '../repo/pipeline.js';

interface ProvisioningKeys {
  widgetKey?: string | null;
  analyticsKey?: string | null;
}

/** Fetch a tenant's client-side embed keys from the CRM platform endpoint. */
async function fetchKeys(tenantId: string): Promise<ProvisioningKeys | null> {
  const base = process.env.PLATFORM_BILLING_URL;
  const token = process.env.PLATFORM_SERVICE_TOKEN;
  if (!base || !token) return null;
  try {
    const res = await fetch(`${base.replace(/\/$/, '')}/platform/provisioning/${encodeURIComponent(tenantId)}`, {
      headers: { authorization: `Bearer ${token}` },
      signal: AbortSignal.timeout(10_000),
    });
    if (!res.ok) {
      console.error(`[provisioning] CRM fetch failed (${res.status})`);
      return null;
    }
    return (await res.json()) as ProvisioningKeys;
  } catch (err) {
    console.error('[provisioning] CRM fetch error', err instanceof Error ? err.message : String(err));
    return null;
  }
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
  if (!keys) return { ok: false, pushed: [], error: 'no provisioning keys available' };

  const changes: Change[] = [];
  if (keys.widgetKey) changes.push({ type: 'option', id: 0, field: 'widget_key', value: keys.widgetKey });
  if (keys.analyticsKey) changes.push({ type: 'option', id: 0, field: 'analytics_key', value: keys.analyticsKey });
  if (changes.length === 0) return { ok: true, pushed: [] };

  const r = await deploy(site as Site, secret, `prov_${randomUUID()}`, changes);
  return { ok: r.ok, pushed: r.ok ? changes.map((c) => c.field) : [], error: r.error };
}
