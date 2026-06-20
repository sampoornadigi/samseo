/**
 * UX / Core Web Vitals scoring via the Google PageSpeed Insights API.
 *
 * Fetched centrally (the API key lives only on the control plane, never on
 * client sites) so the UX health dimension has a real data source. Prefers CrUX
 * *field* data (real users) and falls back to the Lighthouse *lab* performance
 * score; returns null when neither is available, so UX gracefully stays
 * "no data" rather than fabricating a number.
 */

const PSI_ENDPOINT = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';

export interface UxResult {
  /** 0–100 UX score, or null when there is no usable data. */
  score: number | null;
  /** Display detail merged into the snapshot's ux signal. */
  metrics: {
    lcp_ms?: number | null;
    inp_ms?: number | null;
    cls?: number | null;
    perf?: number | null;
    source?: 'field' | 'lab';
  } | null;
}

const EMPTY: UxResult = { score: null, metrics: null };

/** Weight a metric: 1 (good) / 0.5 (needs improvement) / 0 (poor). */
function weight(value: number, good: number, ok: number): number {
  return value <= good ? 1 : value <= ok ? 0.5 : 0;
}

interface PsiMetric {
  percentile?: number;
}
interface PsiShape {
  loadingExperience?: { metrics?: Record<string, PsiMetric> };
  lighthouseResult?: { categories?: { performance?: { score?: number | null } } };
}

/**
 * Derive a UX score + display metrics from a PageSpeed Insights response.
 * Pure (no network) so it is unit-testable against captured fixtures.
 */
export function uxFromPagespeed(json: PsiShape): UxResult {
  const field = json.loadingExperience?.metrics ?? {};
  const lcp = field.LARGEST_CONTENTFUL_PAINT_MS?.percentile;
  const inp =
    field.INTERACTION_TO_NEXT_PAINT?.percentile ??
    field.EXPERIMENTAL_INTERACTION_TO_NEXT_PAINT?.percentile;
  const clsRaw = field.CUMULATIVE_LAYOUT_SHIFT_SCORE?.percentile; // CLS * 100

  const weights: number[] = [];
  const metrics: NonNullable<UxResult['metrics']> = { source: 'field' };
  if (typeof lcp === 'number') {
    weights.push(weight(lcp, 2500, 4000));
    metrics.lcp_ms = lcp;
  }
  if (typeof inp === 'number') {
    weights.push(weight(inp, 200, 500));
    metrics.inp_ms = inp;
  }
  if (typeof clsRaw === 'number') {
    const cls = clsRaw / 100;
    weights.push(weight(cls, 0.1, 0.25));
    metrics.cls = cls;
  }

  if (weights.length > 0) {
    const score = Math.round((weights.reduce((s, w) => s + w, 0) / weights.length) * 100);
    return { score, metrics };
  }

  // No field data — fall back to the Lighthouse lab performance score.
  const perf = json.lighthouseResult?.categories?.performance?.score;
  if (typeof perf === 'number') {
    return { score: Math.round(perf * 100), metrics: { perf, source: 'lab' } };
  }
  return EMPTY;
}

/** Fetch + score a URL's UX via PSI. Best-effort: returns EMPTY on any failure. */
export async function fetchUxScore(url: string, key: string, strategy = 'mobile'): Promise<UxResult> {
  if (url === '') {
    return EMPTY;
  }
  const params = new URLSearchParams({ url, strategy, category: 'performance' });
  if (key !== '') {
    params.set('key', key);
  }
  try {
    const res = await fetch(`${PSI_ENDPOINT}?${params.toString()}`, {
      signal: AbortSignal.timeout(25000),
    });
    if (!res.ok) {
      return EMPTY;
    }
    return uxFromPagespeed((await res.json()) as PsiShape);
  } catch {
    return EMPTY;
  }
}
