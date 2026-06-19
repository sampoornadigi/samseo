/**
 * Background scheduler: periodically refresh all sites' metrics so health data
 * stays current without a manual click. Interval set by CP_REFRESH_MINUTES
 * (0 disables). Each run isolates per-site failures (see refreshAllSites).
 */

import { config } from './config.js';
import { refreshAllSites } from './services/refresh.js';

interface Logger {
  info: (msg: string) => void;
  error: (msg: string) => void;
}

export function startScheduler(log: Logger): NodeJS.Timeout | null {
  const mins = config.refreshMinutes;
  if (!Number.isFinite(mins) || mins <= 0) {
    log.info('metrics scheduler disabled (set CP_REFRESH_MINUTES to enable)');
    return null;
  }
  log.info(`metrics scheduler enabled: refreshing all sites every ${mins} min`);
  const timer = setInterval(() => {
    refreshAllSites()
      .then((r) => log.info(`scheduled refresh: ${r.refreshed}/${r.sites} site(s)`))
      .catch((e) => log.error(`scheduled refresh failed: ${e instanceof Error ? e.message : String(e)}`));
  }, mins * 60 * 1000);
  timer.unref();
  return timer;
}
