/**
 * Password hashing for control-plane operators (scrypt, Node built-in).
 *
 * Stored format: `saltHex:derivedHex`. Verification is constant-time.
 */

import { randomBytes, scryptSync, timingSafeEqual } from 'node:crypto';

const KEYLEN = 64;

export function hashPassword(password: string): string {
  const salt = randomBytes(16);
  const dk = scryptSync(password, salt, KEYLEN);
  return `${salt.toString('hex')}:${dk.toString('hex')}`;
}

export function verifyPassword(password: string, stored: string): boolean {
  const [saltHex, hashHex] = stored.split(':');
  if (!saltHex || !hashHex) {
    return false;
  }
  const expected = Buffer.from(hashHex, 'hex');
  const actual = scryptSync(password, Buffer.from(saltHex, 'hex'), KEYLEN);
  return expected.length === actual.length && timingSafeEqual(expected, actual);
}
