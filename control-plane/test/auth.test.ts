import { describe, expect, it } from 'vitest';
import { hashPassword, verifyPassword } from '../src/crypto/password.js';
import { makeToken, readToken } from '../src/auth/session.js';

describe('password hashing', () => {
  it('verifies a correct password and rejects a wrong one', () => {
    const stored = hashPassword('correct horse battery');
    expect(verifyPassword('correct horse battery', stored)).toBe(true);
    expect(verifyPassword('wrong', stored)).toBe(false);
  });

  it('produces a salted hash (different each time, both valid)', () => {
    const a = hashPassword('same');
    const b = hashPassword('same');
    expect(a).not.toBe(b);
    expect(verifyPassword('same', a)).toBe(true);
    expect(verifyPassword('same', b)).toBe(true);
  });

  it('rejects a malformed stored value', () => {
    expect(verifyPassword('x', 'not-a-valid-hash')).toBe(false);
  });
});

describe('session token', () => {
  it('round-trips username + role', () => {
    const u = readToken(makeToken('alice', 'admin'));
    expect(u).toEqual({ username: 'alice', role: 'admin' });
  });

  it('rejects a tampered token', () => {
    const t = makeToken('alice', 'admin');
    const tampered = t.slice(0, -1) + (t.endsWith('a') ? 'b' : 'a');
    expect(readToken(tampered)).toBeNull();
  });

  it('rejects a payload swapped under a stale signature', () => {
    // Re-encode the payload to escalate role; the HMAC no longer matches.
    const [, sig] = makeToken('alice', 'viewer').split('.');
    const forgedBody = Buffer.from(JSON.stringify({ u: 'alice', r: 'admin', exp: 9999999999 })).toString('base64url');
    expect(readToken(`${forgedBody}.${sig}`)).toBeNull();
  });

  it('rejects garbage', () => {
    expect(readToken('')).toBeNull();
    expect(readToken('abc')).toBeNull();
  });
});
