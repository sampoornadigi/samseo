<?php
/**
 * HMAC request signer/verifier for the control-plane handshake.
 *
 * Implements the v1 signing contract shared by the site and the control plane:
 * an HMAC-SHA256 over a canonical (method, route, timestamp, body-hash) string.
 * This is the only code that builds or checks signatures.
 *
 * @package Sampoorna\SEO
 */

namespace Sampoorna\SEO\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds and verifies HMAC-SHA256 request signatures.
 */
class Signer {

	/**
	 * Build the canonical string that gets signed.
	 *
	 * Format (LF-joined): METHOD \n ROUTE \n TIMESTAMP \n sha256_hex(body).
	 *
	 * @param string $method    HTTP method.
	 * @param string $route     REST route path, e.g. /sampoorna-seo/v1/status.
	 * @param string $timestamp Unix timestamp (seconds) as a string.
	 * @param string $body      Raw request body ('' for no body).
	 * @return string
	 */
	public static function canonical( $method, $route, $timestamp, $body ) {
		return implode(
			"\n",
			array(
				strtoupper( (string) $method ),
				(string) $route,
				(string) $timestamp,
				hash( 'sha256', (string) $body ),
			)
		);
	}

	/**
	 * Sign a request, returning the value for the X-Sampoorna-Signature header.
	 *
	 * @param string $method    HTTP method.
	 * @param string $route     REST route path.
	 * @param string $timestamp Unix timestamp (seconds) as a string.
	 * @param string $body      Raw request body.
	 * @param string $secret    Per-site shared secret (hex).
	 * @return string Signature in the form "sha256=<hex>".
	 */
	public static function sign( $method, $route, $timestamp, $body, $secret ) {
		$hmac = hash_hmac( 'sha256', self::canonical( $method, $route, $timestamp, $body ), (string) $secret );
		return 'sha256=' . $hmac;
	}

	/**
	 * Constant-time verification of a provided signature.
	 *
	 * @param string $method    HTTP method.
	 * @param string $route     REST route path.
	 * @param string $timestamp Unix timestamp (seconds) as a string.
	 * @param string $body      Raw request body.
	 * @param string $signature Provided signature ("sha256=<hex>").
	 * @param string $secret    Per-site shared secret (hex).
	 * @return bool
	 */
	public static function verify( $method, $route, $timestamp, $body, $signature, $secret ) {
		if ( '' === (string) $secret || '' === (string) $signature ) {
			return false;
		}
		$expected = self::sign( $method, $route, $timestamp, $body, $secret );
		return hash_equals( $expected, (string) $signature );
	}
}
