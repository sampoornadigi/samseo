<?php
/**
 * Tests for the control-plane HMAC handshake: signer, keys, signed REST routes.
 *
 * @package Sampoorna\SEO
 */

use Sampoorna\SEO\Security\Signer;
use Sampoorna\SEO\Security\Crypto;
use Sampoorna\SEO\ControlPlane\Keys;
use Sampoorna\SEO\ControlPlane\Handshake;

class Sampoorna_Seo_Handshake_Test extends WP_UnitTestCase {

	const STATUS_ROUTE    = '/sampoorna-seo/v1/status';
	const HANDSHAKE_ROUTE = '/sampoorna-seo/v1/handshake';

	public function set_up() {
		parent::set_up();
		Keys::rotate();
		// Fresh REST server with the plugin's routes registered.
		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init' );
	}

	public function test_signer_round_trip_and_tamper() {
		$secret = 'deadbeef';
		$sig    = Signer::sign( 'GET', self::STATUS_ROUTE, '1718600000', '', $secret );

		$this->assertStringStartsWith( 'sha256=', $sig );
		$this->assertTrue( Signer::verify( 'GET', self::STATUS_ROUTE, '1718600000', '', $sig, $secret ) );
		// Tampered timestamp / body / secret all fail.
		$this->assertFalse( Signer::verify( 'GET', self::STATUS_ROUTE, '1718600001', '', $sig, $secret ) );
		$this->assertFalse( Signer::verify( 'GET', self::STATUS_ROUTE, '1718600000', 'x', $sig, $secret ) );
		$this->assertFalse( Signer::verify( 'GET', self::STATUS_ROUTE, '1718600000', '', $sig, 'wrong' ) );
	}

	public function test_keys_rotate_and_encrypted_at_rest() {
		$secret = Keys::secret();
		$this->assertSame( 64, strlen( $secret ), 'Secret is 32 bytes hex' );
		$this->assertTrue( Keys::is_configured() );

		// Stored option is ciphertext, not the plaintext secret.
		$stored = get_option( Keys::OPT_SECRET );
		$this->assertNotSame( $secret, $stored );
		$this->assertSame( $secret, Crypto::decrypt( $stored ) );

		$old_id = Keys::key_id();
		Keys::rotate();
		$this->assertNotSame( $old_id, Keys::key_id(), 'key id changes on rotate' );
		$this->assertNotSame( $secret, Keys::secret(), 'secret changes on rotate' );
	}

	public function test_status_with_valid_signature_returns_descriptor() {
		$response = $this->dispatch_signed( 'GET', self::STATUS_ROUTE );
		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertSame( 'sampoorna-seo', $data['name'] );
		$this->assertSame( Keys::key_id(), $data['key_id'] );
		$this->assertArrayHasKey( 'plugin_version', $data );
		$this->assertTrue( $data['modules']['meta'] );
	}

	public function test_status_without_signature_is_unauthorized() {
		$response = rest_get_server()->dispatch( new \WP_REST_Request( 'GET', self::STATUS_ROUTE ) );
		$this->assertSame( 401, $response->get_status() );
	}

	public function test_status_with_expired_timestamp_is_unauthorized() {
		$response = $this->dispatch_signed( 'GET', self::STATUS_ROUTE, '', array( 'ts' => (string) ( time() - 600 ) ) );
		$this->assertSame( 401, $response->get_status() );
	}

	public function test_status_with_bad_signature_is_unauthorized() {
		$response = $this->dispatch_signed( 'GET', self::STATUS_ROUTE, '', array( 'sig' => 'sha256=000' ) );
		$this->assertSame( 401, $response->get_status() );
	}

	public function test_handshake_post_with_body_ok_and_body_tamper_rejected() {
		$body = (string) wp_json_encode( array( 'plane_id' => 'cp-123' ) );

		$ok = $this->dispatch_signed( 'POST', self::HANDSHAKE_ROUTE, $body );
		$this->assertSame( 200, $ok->get_status() );
		$this->assertSame( 'ok', $ok->get_data()['handshake'] );
		$this->assertSame( 'cp-123', $ok->get_data()['plane_id'] );

		// Sign over the original body but send a different body → reject.
		$ts  = (string) time();
		$sig = Signer::sign( 'POST', self::HANDSHAKE_ROUTE, $ts, $body, Keys::secret() );
		$req = new \WP_REST_Request( 'POST', self::HANDSHAKE_ROUTE );
		$req->set_header( 'Content-Type', 'application/json' );
		$req->set_body( (string) wp_json_encode( array( 'plane_id' => 'tampered' ) ) );
		$req->set_header( 'X-Sampoorna-Key-Id', Keys::key_id() );
		$req->set_header( 'X-Sampoorna-Timestamp', $ts );
		$req->set_header( 'X-Sampoorna-Signature', $sig );
		$tampered = rest_get_server()->dispatch( $req );
		$this->assertSame( 401, $tampered->get_status() );
	}

	/**
	 * Dispatch a correctly-signed request, allowing per-test overrides.
	 *
	 * @param string               $method HTTP method.
	 * @param string               $route  REST route.
	 * @param string               $body   Raw body.
	 * @param array<string,string> $opts   Optional 'ts'/'key_id'/'sig' overrides.
	 * @return \WP_REST_Response
	 */
	private function dispatch_signed( $method, $route, $body = '', $opts = array() ) {
		$ts     = isset( $opts['ts'] ) ? $opts['ts'] : (string) time();
		$key_id = isset( $opts['key_id'] ) ? $opts['key_id'] : Keys::key_id();
		$sig    = isset( $opts['sig'] ) ? $opts['sig'] : Signer::sign( $method, $route, $ts, $body, Keys::secret() );

		$req = new \WP_REST_Request( $method, $route );
		if ( '' !== $body ) {
			$req->set_header( 'Content-Type', 'application/json' );
			$req->set_body( $body );
		}
		$req->set_header( 'X-Sampoorna-Key-Id', $key_id );
		$req->set_header( 'X-Sampoorna-Timestamp', $ts );
		$req->set_header( 'X-Sampoorna-Signature', $sig );
		return rest_get_server()->dispatch( $req );
	}
}
