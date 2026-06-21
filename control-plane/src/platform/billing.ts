/**
 * Platform billing client — report billable usage into the ONE platform wallet
 * (Phase 5 metering contract). The SEO control plane calls this after a successful
 * site audit. Uses an agency service token (PLATFORM_SERVICE_TOKEN) and names the
 * target tenant explicitly (the M2M path /billing/usage allows). No-ops when
 * unconfigured so it never breaks an audit run.
 */

export interface ReportUsageInput {
  tenantId: string | null;
  type: string;
  usageId: string;
  units?: number;
  product?: string;
}

export async function reportUsage(input: ReportUsageInput): Promise<{ ok?: boolean; skipped?: boolean }> {
  const base = process.env.PLATFORM_BILLING_URL;
  const token = process.env.PLATFORM_SERVICE_TOKEN;
  if (!base || !token) return { skipped: true };
  if (!input.tenantId) {
    console.warn('[billing] no platform_tenant_id for site — usage not reported');
    return { skipped: true };
  }
  try {
    const res = await fetch(`${base}/billing/usage`, {
      method: 'POST',
      headers: { 'content-type': 'application/json', authorization: `Bearer ${token}` },
      body: JSON.stringify({
        product: input.product ?? 'seo',
        type: input.type,
        units: input.units ?? 1,
        usageId: input.usageId,
        tenantId: input.tenantId,
      }),
    });
    if (!res.ok) {
      console.error(`[billing] usage report failed (${res.status})`);
      return { ok: false };
    }
    return { ok: true };
  } catch (err) {
    console.error('[billing] usage report error', err instanceof Error ? err.message : String(err));
    return { ok: false };
  }
}
