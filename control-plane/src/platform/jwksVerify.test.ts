import { test, expect } from 'vitest';
import { generateKeyPair, SignJWT, type KeyLike } from 'jose';
import { verifyPlatformJwt } from './jwksVerify.js';

async function sign(privateKey: KeyLike, claims: Record<string, unknown> = {}, issuer = 'sampoorna-identity') {
  return new SignJWT({ role: 'client_admin', ent: ['seo'], ...claims })
    .setProtectedHeader({ alg: 'RS256' })
    .setIssuer(issuer)
    .setSubject('prn_1')
    .setExpirationTime('1h')
    .sign(privateKey);
}

test('verifies an RS256 platform token and maps tid + ent onto the principal', async () => {
  const { publicKey, privateKey } = await generateKeyPair('RS256');
  const token = await sign(privateKey, { tid: 'ten_42', ent: ['seo', 'crm'] });

  const principal = await verifyPlatformJwt(token, publicKey);
  expect(principal.tenantId).toBe('ten_42');
  expect(principal.role).toBe('client_admin');
  expect(principal.entitlements).toEqual(['seo', 'crm']);
});

test('rejects a token signed by the wrong key', async () => {
  const a = await generateKeyPair('RS256');
  const b = await generateKeyPair('RS256');
  const token = await sign(a.privateKey, { tid: 'ten_1' });
  await expect(verifyPlatformJwt(token, b.publicKey)).rejects.toThrow();
});

test('rejects a token from the wrong issuer', async () => {
  const { publicKey, privateKey } = await generateKeyPair('RS256');
  const token = await sign(privateKey, { tid: 'ten_1' }, 'someone-else');
  await expect(verifyPlatformJwt(token, publicKey)).rejects.toThrow();
});

test('defaults entitlements to an empty array when absent', async () => {
  const { publicKey, privateKey } = await generateKeyPair('RS256');
  const token = await new SignJWT({ role: 'client_admin', tid: 'ten_1' })
    .setProtectedHeader({ alg: 'RS256' })
    .setIssuer('sampoorna-identity')
    .setSubject('prn_1')
    .setExpirationTime('1h')
    .sign(privateKey);
  const principal = await verifyPlatformJwt(token, publicKey);
  expect(principal.entitlements).toEqual([]);
});
