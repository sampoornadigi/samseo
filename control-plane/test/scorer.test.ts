import { describe, expect, it } from 'vitest';
import { score, type Signals } from '../src/score/scorer.js';

function baseSignals(over: Partial<Signals> = {}): Signals {
  return {
    content: {
      published: 0,
      missing_title: 0,
      missing_desc: 0,
      missing_focus: 0,
      sample_size: 0,
      avg_onpage: null,
      avg_readability: null,
      avg_aeo: null,
    },
    technical: {
      redirects_active: 0,
      not_found_new: 0,
      issues: {},
      robots_configured: false,
      indexnow_enabled: false,
      sitemap_cached: false,
    },
    authority: {
      gsc_connected: false,
      property_selected: false,
      clicks_28d: null,
      impressions_28d: null,
      avg_position: null,
    },
    geo: {
      org_name_set: false,
      org_logo_set: false,
      social_count: 0,
      local_business: false,
      avg_aeo: null,
    },
    ux: { available: false, mobile_issues: null },
    ...over,
  };
}

describe('scorer', () => {
  it('UX is null when no PageSpeed score is supplied', () => {
    const s = score(baseSignals());
    expect(s.ux).toBeNull();
  });

  it('UX reflects an injected PageSpeed score and joins the overall', () => {
    const signals = baseSignals({
      content: {
        published: 1,
        missing_title: 0,
        missing_desc: 0,
        missing_focus: 0,
        sample_size: 1,
        avg_onpage: 100,
        avg_readability: 100,
        avg_aeo: 100,
      },
    });
    const s = score(signals, 60);
    expect(s.ux).toBe(60);
    // ux now joins the overall mean of the non-null dimensions.
    const present = [s.content, s.technical, s.authority, s.geo, s.ux].filter(
      (v): v is number => v !== null,
    );
    expect(present).toContain(60);
    expect(s.overall).toBe(Math.round(present.reduce((a, b) => a + b, 0) / present.length));
  });

  it('authority is null when GSC is not connected', () => {
    expect(score(baseSignals()).authority).toBeNull();
  });

  it('content is null when there are no published posts', () => {
    expect(score(baseSignals()).content).toBeNull();
  });

  it('a bare, unconfigured site scores low', () => {
    const s = score(
      baseSignals({
        content: {
          published: 10,
          missing_title: 10,
          missing_desc: 10,
          missing_focus: 10,
          sample_size: 10,
          avg_onpage: 20,
          avg_readability: 20,
          avg_aeo: 10,
        },
      }),
    );
    expect(s.content).toBeLessThan(30);
    // Technical is middling for a bare site: unconfigured sitemap/robots/IndexNow
    // drag it down, but no 404s/issues yet is genuinely fine.
    expect(s.technical!).toBeLessThanOrEqual(60);
    expect(s.overall).not.toBeNull();
    expect(s.overall!).toBeLessThan(50);
  });

  it('a well-configured, connected site scores high', () => {
    const s = score(
      baseSignals({
        content: {
          published: 40,
          missing_title: 0,
          missing_desc: 0,
          missing_focus: 1,
          sample_size: 30,
          avg_onpage: 88,
          avg_readability: 80,
          avg_aeo: 85,
        },
        technical: {
          redirects_active: 3,
          not_found_new: 0,
          issues: {},
          robots_configured: true,
          indexnow_enabled: true,
          sitemap_cached: true,
        },
        authority: {
          gsc_connected: true,
          property_selected: true,
          clicks_28d: 1200,
          impressions_28d: 40000,
          avg_position: 8.4,
        },
        geo: {
          org_name_set: true,
          org_logo_set: true,
          social_count: 3,
          local_business: true,
          avg_aeo: 85,
        },
      }),
    );
    expect(s.content!).toBeGreaterThanOrEqual(80);
    expect(s.technical!).toBeGreaterThanOrEqual(80);
    expect(s.authority!).toBeGreaterThanOrEqual(80);
    expect(s.geo!).toBeGreaterThanOrEqual(80);
    expect(s.ux).toBeNull();
    expect(s.overall!).toBeGreaterThanOrEqual(80);
  });

  it('overall is the mean of non-null dimensions only', () => {
    const s = score(
      baseSignals({
        content: {
          published: 1,
          missing_title: 0,
          missing_desc: 0,
          missing_focus: 0,
          sample_size: 1,
          avg_onpage: 100,
          avg_readability: 100,
          avg_aeo: 100,
        },
        technical: {
          redirects_active: 1,
          not_found_new: 0,
          issues: {},
          robots_configured: true,
          indexnow_enabled: true,
          sitemap_cached: true,
        },
        geo: {
          org_name_set: true,
          org_logo_set: true,
          social_count: 3,
          local_business: true,
          avg_aeo: 100,
        },
      }),
    );
    // authority + ux are null; overall averages content/technical/geo only.
    const mean = Math.round((s.content! + s.technical! + s.geo!) / 3);
    expect(s.overall).toBe(mean);
  });
});
