/**
 * At-rest encryption for site shared secrets.
 *
 * The HMAC secret is the trust anchor between the plane and each site, so it is
 * never stored in plaintext. We encrypt with AES-256-GCM under CP_VAULT_KEY
 * (mirroring the plugin's Security\Crypto at-rest pattern). The plane's vault
 * format is independent of the plugin's — the secret arrives as plaintext at
 * enrollment and is re-encrypted here.
 *
 * Stored format (base64): [12-byte IV][16-byte auth tag][ciphertext].
 */

import { createCipheriv, createDecipheriv, randomBytes } from 'node:crypto';
import { config } from '../config.js';

const IV_LEN = 12;
const TAG_LEN = 16;

function key(): Buffer {
  const k = Buffer.from(config.vaultKey, 'hex');
  if (k.length !== 32) {
    throw new Error('CP_VAULT_KEY must be 64 hex chars (32 bytes).');
  }
  return k;
}

export function encrypt(plaintext: string): string {
  const iv = randomBytes(IV_LEN);
  const cipher = createCipheriv('aes-256-gcm', key(), iv);
  const ct = Buffer.concat([cipher.update(plaintext, 'utf8'), cipher.final()]);
  const tag = cipher.getAuthTag();
  return Buffer.concat([iv, tag, ct]).toString('base64');
}

export function decrypt(blob: string): string {
  const raw = Buffer.from(blob, 'base64');
  const iv = raw.subarray(0, IV_LEN);
  const tag = raw.subarray(IV_LEN, IV_LEN + TAG_LEN);
  const ct = raw.subarray(IV_LEN + TAG_LEN);
  const decipher = createDecipheriv('aes-256-gcm', key(), iv);
  decipher.setAuthTag(tag);
  return Buffer.concat([decipher.update(ct), decipher.final()]).toString('utf8');
}
