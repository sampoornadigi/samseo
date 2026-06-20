import { describe, expect, it } from 'vitest';
import { uxFromPagespeed } from '../src/services/pagespeed.js';

describe('uxFromPagespeed', () => {
  it('scores 100 from all-good CrUX field data', () => {
    const r = uxFromPagespeed({
      loadingExperience: {
        metrics: {
          LARGEST_CONTENTFUL_PAINT_MS: { percentile: 2000 },
          INTERACTION_TO_NEXT_PAINT: { percentile: 150 },
          CUMULATIVE_LAYOUT_SHIFT_SCORE: { percentile: 5 }, // CLS 0.05
        },
      },
    });
    expect(r.score).toBe(100);
    expect(r.metrics?.source).toBe('field');
    expect(r.metrics?.cls).toBeCloseTo(0.05);
  });

  it('scores lower for poor field metrics', () => {
    const r = uxFromPagespeed({
      loadingExperience: {
        metrics: {
          LARGEST_CONTENTFUL_PAINT_MS: { percentile: 6000 }, // poor -> 0
          INTERACTION_TO_NEXT_PAINT: { percentile: 150 }, // good -> 1
          CUMULATIVE_LAYOUT_SHIFT_SCORE: { percentile: 30 }, // 0.30 poor -> 0
        },
      },
    });
    // (0 + 1 + 0) / 3 -> 33
    expect(r.score).toBe(33);
  });

  it('falls back to the Lighthouse lab score when no field data', () => {
    const r = uxFromPagespeed({
      lighthouseResult: { categories: { performance: { score: 0.82 } } },
    });
    expect(r.score).toBe(82);
    expect(r.metrics?.source).toBe('lab');
  });

  it('returns null when neither field nor lab data is present', () => {
    expect(uxFromPagespeed({}).score).toBeNull();
    expect(uxFromPagespeed({}).metrics).toBeNull();
  });
});
