/**
 * Audit service: run a site's signed /audit and persist its findings. Shared by
 * the manual dashboard action and the background scheduler so both behave
 * identically (mirrors services/refresh.ts).
 */

import { list, secretFor, type Site } from '../repo/sites.js';
import { runAudit } from '../client/siteClient.js';
import { saveAudit } from '../repo/pipeline.js';
import { reportUsage } from '../platform/billing.js';

/** Audit one site and store its findings. Returns false when the call failed. */
export async function auditSite(site: Site, secret: string): Promise<boolean> {
  const res = await runAudit(site, secret);
  if (res.ok) {
    await saveAudit(site.id, res.findings);
    // Meter the audit into the one platform wallet (Phase 5); no-op if unconfigured.
    await reportUsage({
      tenantId: site.platform_tenant_id,
      type: 'seo_audit',
      usageId: `seo-audit-${site.id}-${Date.now()}`,
    });
    return true;
  }
  return false;
}

/** Audit every enrolled site, isolating per-site failures. */
export async function auditAllSites(): Promise<{ sites: number; audited: number }> {
  const sites = await list();
  let audited = 0;
  for (const site of sites) {
    const secret = await secretFor(site.key_id);
    if (secret === null) {
      continue;
    }
    try {
      if (await auditSite(site, secret)) {
        audited += 1;
      }
    } catch {
      // Isolate per-site failures so one bad site doesn't stop the run.
    }
  }
  return { sites: sites.length, audited };
}
