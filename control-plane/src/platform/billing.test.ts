import { test, expect, afterEach, vi } from 'vitest';
import { reportUsage } from './billing.js';

afterEach(() => {
  vi.unstubAllGlobals();
  delete process.env.PLATFORM_BILLING_URL;
  delete process.env.PLATFORM_SERVICE_TOKEN;
});

test('skips when the platform billing URL/token are not configured', async () => {
  const r = await reportUsage({ tenantId: 'ten_1', type: 'seo_audit', usageId: 'u' });
  expect(r.skipped).toBe(true);
});

test('skips when no tenantId is available', async () => {
  process.env.PLATFORM_BILLING_URL = 'http://billing';
  process.env.PLATFORM_SERVICE_TOKEN = 'tok';
  const r = await reportUsage({ tenantId: null, type: 'seo_audit', usageId: 'u' });
  expect(r.skipped).toBe(true);
});

test('POSTs the metering body with a bearer token when configured', async () => {
  process.env.PLATFORM_BILLING_URL = 'http://billing';
  process.env.PLATFORM_SERVICE_TOKEN = 'tok';
  let captured: { url: string; opts: RequestInit } | undefined;
  vi.stubGlobal('fetch', async (url: string, opts: RequestInit) => {
    captured = { url, opts };
    return { ok: true, status: 200, json: async () => ({}) } as Response;
  });

  const r = await reportUsage({ tenantId: 'ten_9', type: 'seo_audit', usageId: 'u1', units: 3 });
  expect(r.ok).toBe(true);
  expect(captured?.url).toBe('http://billing/billing/usage');
  expect((captured?.opts.headers as Record<string, string>).authorization).toBe('Bearer tok');
  expect(JSON.parse(captured?.opts.body as string)).toEqual({
    product: 'seo',
    type: 'seo_audit',
    units: 3,
    usageId: 'u1',
    tenantId: 'ten_9',
  });
});
