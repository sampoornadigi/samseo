/**
 * Background scheduler: periodically run fleet jobs so dashboard data stays
 * current without manual clicks. Three independent intervals, each disabled when
 * its CP_*_MINUTES is 0:
 *   - metrics  (CP_REFRESH_MINUTES)  — refresh + score every site (alerts ride this)
 *   - audits   (CP_AUDIT_MINUTES)    — run each site's signed audit
 *   - citation (CP_CITATION_MINUTES) — sample saved prompts through the LLM
 * Each run isolates per-site failures (see the service functions).
 */

import { config } from './config.js';
import { refreshAllSites } from './services/refresh.js';
import { auditAllSites } from './services/audit.js';
import { citationAllSites } from './services/citation.js';

interface Logger {
  info: (msg: string) => void;
  error: (msg: string) => void;
}

/** Schedule one recurring job; returns its timer or null when disabled. */
function every(
  minutes: number,
  label: string,
  run: () => Promise<string>,
  log: Logger,
): NodeJS.Timeout | null {
  if (!Number.isFinite(minutes) || minutes <= 0) {
    log.info(`${label} scheduler disabled`);
    return null;
  }
  log.info(`${label} scheduler enabled: every ${minutes} min`);
  const timer = setInterval(() => {
    run()
      .then((summary) => log.info(`scheduled ${label}: ${summary}`))
      .catch((e) => log.error(`scheduled ${label} failed: ${e instanceof Error ? e.message : String(e)}`));
  }, minutes * 60 * 1000);
  timer.unref();
  return timer;
}

export function startScheduler(log: Logger): Array<NodeJS.Timeout | null> {
  return [
    every(config.refreshMinutes, 'metrics', async () => {
      const r = await refreshAllSites();
      return `${r.refreshed}/${r.sites} site(s)`;
    }, log),
    every(config.auditMinutes, 'audit', async () => {
      const r = await auditAllSites();
      return `${r.audited}/${r.sites} site(s)`;
    }, log),
    every(config.citationMinutes, 'citation', async () => {
      const r = await citationAllSites();
      return `${r.ran}/${r.sites} site(s)`;
    }, log),
  ];
}
