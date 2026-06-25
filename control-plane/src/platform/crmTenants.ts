/**
 * Fetch the CRM client list (id + name) so the dashboard can offer a client
 * dropdown when mapping a site to a tenant — instead of pasting a raw UUID.
 *
 * Uses the agency service token (PLATFORM_SERVICE_TOKEN, role ai_system) against
 * the CRM's M2M directory GET /platform/tenants. Cached briefly. Returns [] on
 * any failure so the views gracefully fall back to a manual tenant-id field.
 */

export interface CrmTenant {
  id: string;
  name: string;
  status: string;
  entitlements: string[];
}

const TTL_MS = 60_000;
let cache: { at: number; tenants: CrmTenant[] } | null = null;

export function _resetCrmTenantsCache(): void {
  cache = null;
}

export async function listCrmTenants(now: number = Date.now()): Promise<CrmTenant[]> {
  if (cache && now - cache.at < TTL_MS) return cache.tenants;
  const base = process.env.PLATFORM_BILLING_URL;
  const token = process.env.PLATFORM_SERVICE_TOKEN;
  if (!base || !token) return cache?.tenants ?? [];
  try {
    const res = await fetch(`${base}/platform/tenants`, {
      headers: { authorization: `Bearer ${token}` },
      signal: AbortSignal.timeout(6000),
    });
    if (!res.ok) return cache?.tenants ?? [];
    const data = (await res.json()) as CrmTenant[];
    const tenants = Array.isArray(data) ? data : [];
    cache = { at: now, tenants };
    return tenants;
  } catch {
    return cache?.tenants ?? [];
  }
}
