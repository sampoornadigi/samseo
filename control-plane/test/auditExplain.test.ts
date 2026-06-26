import { test, expect } from 'vitest';
import { parseAuditSummary } from '../src/services/auditExplain.js';

test('parses a clean JSON summary', () => {
  const s = parseAuditSummary(JSON.stringify({
    headline: 'Titles and descriptions need work.',
    actions: [{ title: 'Add page titles', why: 'Google shows these.', fix: 'Write a title per page.', severity: 'high' }],
  }));
  expect(s?.headline).toBe('Titles and descriptions need work.');
  expect(s?.actions).toHaveLength(1);
  expect(s?.actions[0].severity).toBe('high');
});

test('strips code fences and defaults an unknown severity to medium', () => {
  const s = parseAuditSummary('```json\n{"headline":"Hi","actions":[{"title":"Do X","severity":"urgent"}]}\n```');
  expect(s?.actions[0].severity).toBe('medium');
  expect(s?.actions[0].title).toBe('Do X');
});

test('caps the action list at 8 and drops untitled actions', () => {
  const actions = Array.from({ length: 12 }, (_, i) => ({ title: i < 2 ? '' : `A${i}`, severity: 'low' }));
  const s = parseAuditSummary(JSON.stringify({ headline: 'h', actions }));
  expect(s!.actions.length).toBeLessThanOrEqual(8);
  expect(s!.actions.every((a) => a.title)).toBe(true);
});

test('returns null on junk', () => {
  expect(parseAuditSummary('not json at all')).toBeNull();
  expect(parseAuditSummary('')).toBeNull();
});
