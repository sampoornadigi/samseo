import { describe, expect, it } from 'vitest';
import { changesFromKeys, type Finding } from '../src/repo/pipeline.js';

const FINDINGS: Finding[] = [
  { key: 'post:1:desc', type: 'post', id: 1, field: 'desc', current: '', suggested: 'Desc one', reason: 'Missing', label: 'One' },
  { key: 'post:2:og_title', type: 'post', id: 2, field: 'og_title', current: '', suggested: 'Title two', reason: 'Missing', label: 'Two' },
  { key: 'post:3:desc', type: 'post', id: 3, field: 'desc', current: '', suggested: 'Desc three', reason: 'Missing', label: 'Three' },
];

describe('changesFromKeys', () => {
  it('maps approved keys to a changeset', () => {
    const changes = changesFromKeys(FINDINGS, ['post:1:desc', 'post:3:desc']);
    expect(changes).toEqual([
      { type: 'post', id: 1, field: 'desc', value: 'Desc one' },
      { type: 'post', id: 3, field: 'desc', value: 'Desc three' },
    ]);
  });

  it('ignores unknown keys', () => {
    const changes = changesFromKeys(FINDINGS, ['post:9:desc', 'post:2:og_title']);
    expect(changes).toEqual([{ type: 'post', id: 2, field: 'og_title', value: 'Title two' }]);
  });

  it('returns empty for no keys', () => {
    expect(changesFromKeys(FINDINGS, [])).toEqual([]);
  });
});
