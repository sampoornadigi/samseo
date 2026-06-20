<?php
/**
 * Tests for the Google Indexing API service-account JWT auth.
 *
 * @package Sampoorna\SEO
 */

use Sampoorna\SEO\Technical\IndexingApi;

class Sampoorna_Seo_Indexing_Api_Test extends WP_UnitTestCase {

	/**
	 * Generate a throwaway RSA keypair for signing tests.
	 *
	 * @return array{0:string,1:string} [ private PEM, public PEM ]
	 */
	private function keypair() {
		$res = openssl_pkey_new(
			array(
				'private_key_bits' => 2048,
				'private_key_type' => OPENSSL_KEYTYPE_RSA,
			)
		);
		openssl_pkey_export( $res, $private );
		$details = openssl_pkey_get_details( $res );
		return array( $private, $details['key'] );
	}

	/**
	 * URL-safe base64 decode (mirror of IndexingApi::base64url).
	 *
	 * @param string $data Encoded value.
	 * @return string
	 */
	private static function b64url_decode( $data ) {
		return (string) base64_decode( strtr( $data, '-_', '+/' ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding a JWT segment in a test.
	}

	public function test_base64url_is_unpadded_and_urlsafe() {
		$encoded = IndexingApi::base64url( "\xfb\xff\xfe" );
		$this->assertStringNotContainsString( '=', $encoded );
		$this->assertStringNotContainsString( '+', $encoded );
		$this->assertStringNotContainsString( '/', $encoded );
	}

	public function test_claims_have_indexing_scope_and_hour_expiry() {
		$claim = IndexingApi::claims(
			array(
				'client_email' => 'svc@proj.iam.gserviceaccount.com',
				'private_key'  => '',
				'token_uri'    => 'https://oauth2.googleapis.com/token',
			),
			1000
		);
		$this->assertSame( 'svc@proj.iam.gserviceaccount.com', $claim['iss'] );
		$this->assertSame( 'https://www.googleapis.com/auth/indexing', $claim['scope'] );
		$this->assertSame( 'https://oauth2.googleapis.com/token', $claim['aud'] );
		$this->assertSame( 1000, $claim['iat'] );
		$this->assertSame( 4600, $claim['exp'] );
	}

	public function test_encode_jwt_produces_a_verifiable_rs256_token() {
		list( $private, $public ) = $this->keypair();
		$claim = IndexingApi::claims(
			array(
				'client_email' => 'svc@proj',
				'private_key'  => $private,
				'token_uri'    => '',
			),
			2000
		);
		$jwt = IndexingApi::encode_jwt( $claim, $private );
		$this->assertIsString( $jwt );

		$parts = explode( '.', $jwt );
		$this->assertCount( 3, $parts );

		$signing_input = $parts[0] . '.' . $parts[1];
		$signature     = self::b64url_decode( $parts[2] );
		$this->assertSame( 1, openssl_verify( $signing_input, $signature, $public, OPENSSL_ALGO_SHA256 ) );

		$payload = json_decode( self::b64url_decode( $parts[1] ), true );
		$this->assertSame( 'svc@proj', $payload['iss'] );
		$this->assertSame( 'https://www.googleapis.com/auth/indexing', $payload['scope'] );
		// Empty token_uri falls back to the default audience.
		$this->assertSame( 'https://oauth2.googleapis.com/token', $payload['aud'] );
	}

	public function test_is_configured_false_without_credentials() {
		$this->assertFalse( IndexingApi::instance()->is_configured() );
	}
}
