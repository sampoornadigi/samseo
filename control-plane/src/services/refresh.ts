/**
 * Site refresh service: pull a site's signed status + metrics, score, and store
 * a snapshot. Shared by the manual dashboard action and the background
 * scheduler so both behave identically.
 */

import { applyDescriptor, list, secretFor, type Site } from '../repo/sites.js';
import { pullMetrics, pullStatus } from '../client/siteClient.js';
import { score } from '../score/scorer.js';
import { insertSnapshot } from '../repo/metrics.js';
import { fetchUxScore } from './pagespeed.js';
import { config } from '../config.js';
import { maybeAlert } from './alerts.js';

/** Refresh one site (descriptor + scored metrics snapshot). */
export async function refreshSite(site: Site, secret: string): Promise<{ status: boolean; metrics: boolean }> {
  let status = false;
  let metrics = false;
  let overall: number | null = null;

  const s = await pullStatus(site, secret);
  if (s.ok && s.descriptor) {
    await applyDescriptor(site.key_id, s.descriptor);
    status = true;
  }

  const m = await pullMetrics(site, secret);
  if (m.ok && m.signals) {
    const signals = m.signals;
    // UX/CWV from PageSpeed Insights (only when a key is configured); the key
    // stays on the plane, never on the site. Best-effort — null leaves UX unscored.
    let uxScore: number | null = null;
    if (config.pagespeedKey !== '') {
      const ux = await fetchUxScore(site.site_url || site.reach_url, config.pagespeedKey, config.pagespeedStrategy);
      uxScore = ux.score;
      if (ux.metrics) {
        signals.ux = { ...signals.ux, available: true, ...ux.metrics };
      }
    }
    const scored = score(signals, uxScore);
    overall = scored.overall;
    await insertSnapshot(site.id, signals, scored);
    metrics = true;
  }

  // Fire a webhook alert on any health-state change (best-effort).
  await maybeAlert(site, { reachable: status || metrics, overall });

  return { status, metrics };
}

/** Refresh every enrolled site, isolating per-site failures. */
export async function refreshAllSites(): Promise<{ sites: number; refreshed: number }> {
  const sites = await list();
  let refreshed = 0;
  for (const site of sites) {
    const secret = await secretFor(site.key_id);
    if (secret === null) {
      continue;
    }
    try {
      const r = await refreshSite(site, secret);
      if (r.status || r.metrics) {
        refreshed += 1;
      }
    } catch {
      // Isolate per-site failures so one bad site doesn't stop the run.
    }
  }
  return { sites: sites.length, refreshed };
}
