/**
 * Health scoring: raw site signals -> 5 dimension scores + overall.
 *
 * Mirrors the plugin's deterministic good/ok/bad -> weight math
 * (Content\Analyzer::score_from_checks): each signal bands to good (full
 * weight) / ok (half) / bad (zero); the dimension score is earned/total * 100.
 * Scoring lives here (not on the sites) so the agency can tune the rubric
 * centrally. No fabrication: a dimension with no real data source is null and
 * is excluded from the overall.
 *
 * Thresholds are named constants so they can be tuned without touching logic.
 */

export type Band = 'good' | 'ok' | 'bad';

export interface Signals {
  content: {
    published: number;
    missing_title: number;
    missing_desc: number;
    missing_focus: number;
    sample_size: number;
    avg_onpage: number | null;
    avg_readability: number | null;
    avg_aeo: number | null;
  };
  technical: {
    redirects_active: number;
    not_found_new: number;
    issues: Record<string, number>;
    robots_configured: boolean;
    indexnow_enabled: boolean;
    sitemap_cached: boolean;
  };
  authority: {
    gsc_connected: boolean;
    property_selected: boolean;
    clicks_28d: number | null;
    impressions_28d: number | null;
    avg_position: number | null;
  };
  geo: {
    org_name_set: boolean;
    org_logo_set: boolean;
    social_count: number;
    local_business: boolean;
    avg_aeo: number | null;
  };
  ux: { available: boolean; mobile_issues: number | null };
}

export interface Scores {
  content: number | null;
  authority: number | null;
  technical: number | null;
  ux: number | null;
  geo: number | null;
  overall: number | null;
}

const BAND_WEIGHT: Record<Band, number> = { good: 1, ok: 0.5, bad: 0 };

/** Weighted 0–100 from (band, weight) pairs; null when there are no checks. */
function weighted(checks: Array<[Band, number]>): number | null {
  if (checks.length === 0) {
    return null;
  }
  const total = checks.reduce((s, [, w]) => s + w, 0);
  const earned = checks.reduce((s, [b, w]) => s + BAND_WEIGHT[b] * w, 0);
  return total > 0 ? Math.round((earned / total) * 100) : 0;
}

/** Band a 0–100 score: >=70 good, >=40 ok, else bad. */
function scoreBand(v: number, good = 70, ok = 40): Band {
  return v >= good ? 'good' : v >= ok ? 'ok' : 'bad';
}

/** Band a ratio that should be LOW (0 best): <=goodMax good, <=okMax ok. */
function lowRatioBand(value: number, total: number, goodMax = 0.1, okMax = 0.35): Band {
  if (total <= 0) {
    return 'good';
  }
  const r = value / total;
  return r <= goodMax ? 'good' : r <= okMax ? 'ok' : 'bad';
}

/** Band a count that should be LOW (0 best). */
function lowCountBand(n: number, goodMax: number, okMax: number): Band {
  return n <= goodMax ? 'good' : n <= okMax ? 'ok' : 'bad';
}

const bool = (b: boolean): Band => (b ? 'good' : 'bad');

function contentScore(c: Signals['content']): number | null {
  if (c.published <= 0) {
    return null;
  }
  const checks: Array<[Band, number]> = [
    [lowRatioBand(c.missing_title, c.published), 15],
    [lowRatioBand(c.missing_desc, c.published), 15],
    [lowRatioBand(c.missing_focus, c.published), 10],
  ];
  if (c.avg_onpage !== null) checks.push([scoreBand(c.avg_onpage), 25]);
  if (c.avg_readability !== null) checks.push([scoreBand(c.avg_readability), 15]);
  if (c.avg_aeo !== null) checks.push([scoreBand(c.avg_aeo), 20]);
  return weighted(checks);
}

function technicalScore(t: Signals['technical']): number | null {
  const issueTotal = Object.values(t.issues).reduce((s, n) => s + n, 0);
  const checks: Array<[Band, number]> = [
    [bool(t.sitemap_cached), 15],
    [bool(t.robots_configured), 10],
    [bool(t.indexnow_enabled), 10],
    [lowCountBand(t.not_found_new, 5, 25), 20],
    [lowCountBand(issueTotal, 0, 10), 25],
    // Having managed redirects at all is a mild positive signal of upkeep.
    [t.redirects_active > 0 ? 'good' : 'ok', 20],
  ];
  return weighted(checks);
}

function authorityScore(a: Signals['authority']): number | null {
  if (!a.gsc_connected) {
    return null; // No data source — not scored.
  }
  const checks: Array<[Band, number]> = [
    [bool(a.property_selected), 20],
    [(a.clicks_28d ?? 0) > 0 ? 'good' : 'bad', 25],
    [(a.impressions_28d ?? 0) > 0 ? 'good' : 'bad', 20],
  ];
  // Average position: <=10 good, <=20 ok (0/unknown = bad).
  const pos = a.avg_position ?? 0;
  checks.push([pos > 0 && pos <= 10 ? 'good' : pos > 0 && pos <= 20 ? 'ok' : 'bad', 35]);
  return weighted(checks);
}

function geoScore(g: Signals['geo']): number | null {
  const checks: Array<[Band, number]> = [
    [bool(g.org_name_set), 20],
    [bool(g.org_logo_set), 15],
    [g.social_count >= 2 ? 'good' : g.social_count === 1 ? 'ok' : 'bad', 15],
    [bool(g.local_business), 20],
  ];
  if (g.avg_aeo !== null) checks.push([scoreBand(g.avg_aeo), 30]);
  return weighted(checks);
}

export function score(signals: Signals): Scores {
  const content = contentScore(signals.content);
  const technical = technicalScore(signals.technical);
  const authority = authorityScore(signals.authority);
  const geo = geoScore(signals.geo);
  const ux = null; // No Core Web Vitals / field-data source yet.

  const present = [content, technical, authority, geo, ux].filter(
    (v): v is number => v !== null,
  );
  const overall =
    present.length > 0 ? Math.round(present.reduce((s, v) => s + v, 0) / present.length) : null;

  return { content, authority, technical, ux, geo, overall };
}
