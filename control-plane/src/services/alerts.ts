/**
 * Critical-event alerts via a configurable webhook (Slack/Discord/generic).
 *
 * After each refresh a site is classified ok | low | unreachable. We POST to the
 * webhook only when that classification *changes* (so a persistently-down site
 * alerts once, not every refresh), including recovery back to ok. The payload is
 * a generic `{ text, ... }` shape that Slack and Discord both accept.
 */

import type { Site } from '../repo/sites.js';
import { getAlerting } from '../repo/settings.js';
import { getState, setState, type AlertKind } from '../repo/alerts.js';

interface SiteHealth {
  reachable: boolean;
  overall: number | null;
}

/** Classify a site's current health into an alert kind. */
export function classify(h: SiteHealth, threshold: number): AlertKind {
  if (!h.reachable) {
    return 'unreachable';
  }
  if (h.overall !== null && h.overall < threshold) {
    return 'low';
  }
  return 'ok';
}

function message(site: Site, kind: AlertKind, overall: number | null, threshold: number): string {
  const name = site.label || site.site_url || site.key_id;
  if (kind === 'unreachable') {
    return `🔴 ${name} is unreachable from the control plane.`;
  }
  if (kind === 'low') {
    return `🟠 ${name} health dropped to ${overall ?? '?'} (below ${threshold}).`;
  }
  return `🟢 ${name} recovered — health is ${overall ?? '?'}.`;
}

async function post(url: string, payload: Record<string, unknown>): Promise<boolean> {
  try {
    const res = await fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
      signal: AbortSignal.timeout(10000),
    });
    return res.ok;
  } catch {
    return false;
  }
}

/**
 * Evaluate a site after refresh and fire a webhook alert on a state change.
 * Best-effort: never throws into the refresh path.
 */
export async function maybeAlert(site: Site, health: SiteHealth): Promise<void> {
  try {
    const { webhook, threshold } = await getAlerting();
    if (webhook === '') {
      return;
    }
    const kind = classify(health, threshold);
    const prev = await getState(site.id);
    if (kind === prev) {
      return; // no change — don't repeat the alert
    }
    await setState(site.id, kind);
    // Don't announce the very first observation when it's healthy.
    if (kind === 'ok' && prev === null) {
      return;
    }
    await post(webhook, {
      text: message(site, kind, health.overall, threshold),
      site: site.label || site.site_url,
      kind,
      overall: health.overall,
    });
  } catch {
    // Alerting must never break a refresh.
  }
}

/** Send a one-off test message to verify the webhook is wired correctly. */
export async function sendTestAlert(): Promise<boolean> {
  const { webhook } = await getAlerting();
  if (webhook === '') {
    return false;
  }
  return post(webhook, { text: '✅ Sampoorna control plane: test alert. Webhook is working.' });
}
