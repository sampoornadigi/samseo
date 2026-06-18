/**
 * HMAC request signer/verifier — the Node side of the v1 handshake contract.
 *
 * Byte-for-byte port of the plugin's Security\Signer (PHP). Any divergence
 * breaks authentication in one direction, so this mirrors it exactly:
 *
 *   canonical = METHOD \n ROUTE \n TIMESTAMP \n sha256_hex(body)   (LF-joined)
 *   signature = "sha256=" + hmac_sha256_hex(canonical, secret)
 *
 * ROUTE is the WordPress REST route without the /wp-json prefix
 * (e.g. /sampoorna-seo/v1/status), matching WP_REST_Request::get_route().
 */

import { createHash, createHmac, timingSafeEqual } from 'node:crypto';

export function canonical(method: string, route: string, timestamp: string, body: string): string {
  return [
    method.toUpperCase(),
    route,
    timestamp,
    createHash('sha256').update(body, 'utf8').digest('hex'),
  ].join('\n');
}

export function sign(
  method: string,
  route: string,
  timestamp: string,
  body: string,
  secret: string,
): string {
  const hmac = createHmac('sha256', secret).update(canonical(method, route, timestamp, body), 'utf8').digest('hex');
  return `sha256=${hmac}`;
}

export function verify(
  method: string,
  route: string,
  timestamp: string,
  body: string,
  signature: string,
  secret: string,
): boolean {
  if (secret === '' || signature === '') {
    return false;
  }
  const expected = sign(method, route, timestamp, body, secret);
  const a = Buffer.from(expected, 'utf8');
  const b = Buffer.from(signature, 'utf8');
  // timingSafeEqual throws on length mismatch; guard first (and length itself is not secret).
  if (a.length !== b.length) {
    return false;
  }
  return timingSafeEqual(a, b);
}
