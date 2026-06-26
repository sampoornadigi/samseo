import { test, expect } from 'vitest';
import { resolveSeoSso, crmRoleToCpRole, clientUsername, SsoDenied } from './sso-user.js';

function fakeDeps(seed: { sites?: Record<string, number[]>; revoked?: Set<string> } = {}) {
  const users = new Map<string, number>();
  let nextId = 1;
  const calls = { ensureUser: [] as Array<{ username: string; role: string }>, setUserSites: [] as Array<{ userId: number; siteIds: number[] }> };
  const deps = {
    async ensureUser(username: string, role: string) {
      calls.ensureUser.push({ username, role });
      if (!users.has(username)) users.set(username, nextId++);
    },
    async idForUsername(username: string) {
      return users.get(username) ?? null;
    },
    async siteIdsForTenant(tid: string) {
      return seed.sites?.[tid] ?? [];
    },
    async setUserSites(userId: number, siteIds: number[]) {
      calls.setUserSites.push({ userId, siteIds });
    },
    async isTenantRevoked(tid: string) {
      return seed.revoked?.has(tid) ?? false;
    },
  };
  return { deps, calls };
}

test('crmRoleToCpRole maps platform roles to control-plane roles', () => {
  expect(crmRoleToCpRole('super_admin')).toBe('admin');
  expect(crmRoleToCpRole('client_admin')).toBe('client');
  expect(crmRoleToCpRole('client_agent')).toBe('client');
  expect(crmRoleToCpRole('nonsense')).toBe(null);
  expect(crmRoleToCpRole(undefined)).toBe(null);
});

test('super_admin → unscoped admin session, no provisioning', async () => {
  const { deps, calls } = fakeDeps();
  const r = await resolveSeoSso({ role: 'super_admin', userId: 'u1' }, deps);
  expect(r).toEqual({ username: 'u1', role: 'admin' });
  expect(calls.ensureUser).toHaveLength(0);
  expect(calls.setUserSites).toHaveLength(0);
});

test('client_admin → scoped client session limited to the tenant sites', async () => {
  const { deps, calls } = fakeDeps({ sites: { ten_a: [3, 7] } });
  const r = await resolveSeoSso({ role: 'client_admin', tenantId: 'ten_a', entitlements: ['seo', 'crm'] }, deps);
  expect(r.role).toBe('client');
  expect(r.username).toBe(clientUsername('ten_a'));
  expect(calls.ensureUser[0]).toMatchObject({ role: 'client' });
  expect(calls.setUserSites[0].siteIds).toEqual([3, 7]);
});

test('client_agent with no mapped sites → client session scoped to empty (not a 403)', async () => {
  const { deps, calls } = fakeDeps({ sites: {} });
  const r = await resolveSeoSso({ role: 'client_agent', tenantId: 'ten_b', entitlements: ['seo'] }, deps);
  expect(r.role).toBe('client');
  expect(calls.setUserSites[0].siteIds).toEqual([]);
});

test('denies a client without the seo entitlement', async () => {
  const { deps } = fakeDeps();
  await expect(
    resolveSeoSso({ role: 'client_admin', tenantId: 'ten_a', entitlements: ['crm'] }, deps),
  ).rejects.toBeInstanceOf(SsoDenied);
});

test('denies a client token with no tenant', async () => {
  const { deps } = fakeDeps();
  await expect(
    resolveSeoSso({ role: 'client_admin', entitlements: ['seo'] }, deps),
  ).rejects.toBeInstanceOf(SsoDenied);
});

test('denies an unknown role', async () => {
  const { deps } = fakeDeps();
  await expect(resolveSeoSso({ role: 'whatever' }, deps)).rejects.toBeInstanceOf(SsoDenied);
});

test('denies a client whose tenant SSO has been revoked (suspended/archived)', async () => {
  const { deps } = fakeDeps({ revoked: new Set(['ten_a']) });
  await expect(
    resolveSeoSso({ role: 'client_admin', tenantId: 'ten_a', entitlements: ['seo'] }, deps),
  ).rejects.toBeInstanceOf(SsoDenied);
});
