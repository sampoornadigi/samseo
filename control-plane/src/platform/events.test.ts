import { test, expect } from 'vitest';
import { makeEvent, EVENT_SOURCE, EventType } from './events.js';

test('makeEvent builds a well-formed envelope', () => {
  const e = makeEvent(EventType.LeadCaptured, 'ten_1', { emailSha256: 'abc', landingPage: '/p' });
  expect(e.id).toMatch(/^evt_/);
  expect(e.type).toBe('seo.lead.captured');
  expect(e.version).toBe(1);
  expect(e.tenantId).toBe('ten_1');
  expect(e.source).toBe(EVENT_SOURCE);
  expect(e.data).toEqual({ emailSha256: 'abc', landingPage: '/p' });
  expect(Number.isNaN(Date.parse(e.occurredAt))).toBe(false);
});

test('event ids are unique', () => {
  expect(makeEvent(EventType.LeadCaptured, 't', {}).id).not.toBe(makeEvent(EventType.LeadCaptured, 't', {}).id);
});
