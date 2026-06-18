/**
 * Cross-language parity tests for the v1 handshake signer.
 *
 * The golden signatures below were produced by the plugin's PHP Security\Signer
 * for the exact same inputs (see plan §Verification):
 *
 *   docker compose exec -T tooling php -r '
 *     define("ABSPATH","/tmp/");
 *     require ".../Security/Signer.php"; use Sampoorna\SEO\Security\Signer;
 *     echo Signer::sign("GET","/sampoorna-seo/v1/status","1700000000","",$secret);'
 *
 * If these ever diverge, authentication between the plane and a site is broken.
 */

import { describe, expect, it } from 'vitest';
import { canonical, sign, verify } from '../src/crypto/signer.js';

const SECRET = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

describe('signer v1 parity', () => {
  it('matches the PHP signature for a GET with an empty body', () => {
    expect(sign('GET', '/sampoorna-seo/v1/status', '1700000000', '', SECRET)).toBe(
      'sha256=e7bfd2caeab70fc521348d453e2932f1de2e7b661f284331b90d550c3233ecc9',
    );
  });

  it('matches the PHP signature for a POST with a body', () => {
    const body = '{"name":"sampoorna-seo","x":1}';
    expect(sign('POST', '/sites/announce', '1700000000', body, SECRET)).toBe(
      'sha256=363c8c55e028a4a3fff34369841c6ea89985d28f133cc7855cb6a3c88185bad9',
    );
  });

  it('lower-cases nothing but upper-cases the method in the canonical string', () => {
    expect(canonical('get', '/r', '1', '')).toBe(
      'GET\n/r\n1\ne3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
    );
  });
});

describe('signer verify', () => {
  it('accepts a signature it produced', () => {
    const sig = sign('GET', '/sampoorna-seo/v1/status', '1700000000', '', SECRET);
    expect(verify('GET', '/sampoorna-seo/v1/status', '1700000000', '', sig, SECRET)).toBe(true);
  });

  it('rejects a tampered signature', () => {
    const sig = sign('GET', '/sampoorna-seo/v1/status', '1700000000', '', SECRET);
    const tampered = sig.slice(0, -1) + (sig.endsWith('0') ? '1' : '0');
    expect(verify('GET', '/sampoorna-seo/v1/status', '1700000000', '', tampered, SECRET)).toBe(false);
  });

  it('rejects when the body differs', () => {
    const sig = sign('POST', '/sites/announce', '1700000000', '{"a":1}', SECRET);
    expect(verify('POST', '/sites/announce', '1700000000', '{"a":2}', sig, SECRET)).toBe(false);
  });

  it('rejects empty inputs', () => {
    expect(verify('GET', '/r', '1', '', '', SECRET)).toBe(false);
    expect(verify('GET', '/r', '1', '', 'sha256=deadbeef', '')).toBe(false);
  });
});
