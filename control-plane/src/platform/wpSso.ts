/**
 * wp-admin SSO deep-link (P-D15 polish). Mints a short-lived, HMAC-signed token so
 * a platform user who clicks "SEO" lands authenticated in a site's wp-admin —
 * reusing the same per-site shared secret that already secures the handshake
 * (crypto/signer.ts), so no new trust is introduced.
 *
 * The WP plugin exposes a receiver (e.g. /wp-json/sampoorna-seo/v1/sso) that
 * verifies `sig = hmac_sha256_hex(token, secret)`, base64url-decodes the token,
 * checks `exp`, then logs the user in and 302s to wp-admin.
 */

import { createHmac } from 'node:crypto';

export interface SsoClaims {
  u: string; // username
  role: string;
  exp: number; // unix seconds
}

export function encodeSsoToken(claims: SsoClaims): string {
  return Buffer.from(JSON.stringify(claims), 'utf8').toString('base64url');
}

export function decodeSsoToken(token: string): SsoClaims {
  return JSON.parse(Buffer.from(token, 'base64url').toString('utf8')) as SsoClaims;
}

export function signSsoToken(token: string, secret: string): string {
  return createHmac('sha256', secret).update(token, 'utf8').digest('hex');
}

/** Build the full wp-admin SSO deep-link URL for a site. */
export function buildWpSsoUrl(
  baseUrl: string,
  user: { username: string; role: string },
  secret: string,
  nowSec: number,
  ttlSec = 120,
): string {
  const token = encodeSsoToken({ u: user.username, role: user.role, exp: nowSec + ttlSec });
  const sig = signSsoToken(token, secret);
  const base = baseUrl.replace(/\/+$/, '');
  return `${base}/wp-json/sampoorna-seo/v1/sso?token=${encodeURIComponent(token)}&sig=${sig}`;
}
