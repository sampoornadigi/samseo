import { test, expect } from 'vitest';
import { buildWpSsoUrl, decodeSsoToken, signSsoToken } from './wpSso.js';

test('builds a verifiable, time-bound wp-admin SSO deep-link', () => {
  const now = 1_780_000_000;
  const url = buildWpSsoUrl('https://acme.example/', { username: 'asha', role: 'admin' }, 's3cret', now, 120);

  const u = new URL(url);
  expect(u.origin + u.pathname).toBe('https://acme.example/wp-json/sampoorna-seo/v1/sso');
  const token = u.searchParams.get('token')!;
  const sig = u.searchParams.get('sig')!;

  // signature matches an independent HMAC of the token with the shared secret
  expect(sig).toBe(signSsoToken(token, 's3cret'));
  // token carries the user + a future expiry
  const claims = decodeSsoToken(token);
  expect(claims.u).toBe('asha');
  expect(claims.role).toBe('admin');
  expect(claims.exp).toBe(now + 120);
});

test('a tampered token no longer matches the signature', () => {
  const url = buildWpSsoUrl('https://acme.example', { username: 'asha', role: 'admin' }, 's3cret', 1_780_000_000);
  const sig = new URL(url).searchParams.get('sig')!;
  const forged = encodeURIComponent(Buffer.from(JSON.stringify({ u: 'evil', role: 'admin', exp: 9e9 })).toString('base64url'));
  expect(signSsoToken(forged, 's3cret')).not.toBe(sig);
});
