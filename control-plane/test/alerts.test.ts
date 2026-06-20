import { describe, expect, it } from 'vitest';
import { classify } from '../src/services/alerts.js';

describe('alert classify', () => {
  it('is unreachable when the site could not be reached', () => {
    expect(classify({ reachable: false, overall: 90 }, 50)).toBe('unreachable');
  });

  it('is low when overall is below the threshold', () => {
    expect(classify({ reachable: true, overall: 40 }, 50)).toBe('low');
  });

  it('is ok at or above the threshold', () => {
    expect(classify({ reachable: true, overall: 50 }, 50)).toBe('ok');
    expect(classify({ reachable: true, overall: 85 }, 50)).toBe('ok');
  });

  it('is ok when reachable but overall is unknown', () => {
    expect(classify({ reachable: true, overall: null }, 50)).toBe('ok');
  });
});
