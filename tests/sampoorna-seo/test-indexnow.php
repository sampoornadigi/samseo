<?php
/**
 * Tests for IndexNow key handling and submission.
 *
 * @package Sampoorna\SEO
 */

use Sampoorna\SEO\Technical\IndexNow;

class Sampoorna_Seo_IndexNow_Test extends WP_UnitTestCase {

	/** @var int */
	private $http_calls = 0;

	/** @var array */
	private $captured = array();

	public function set_up() {
		parent::set_up();
		$this->http_calls = 0;
		$this->captured   = array();
		delete_option( IndexNow::OPT_KEY );
		delete_option( IndexNow::OPT_ENABLED );
	}

	private function stub_http() {
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) {
				++$this->http_calls;
				$this->captured = array(
					'url'  => $url,
					'args' => $args,
				);
				return array(
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'body'     => '',
				);
			},
			10,
			3
		);
	}

	public function test_ensure_key_generates_hex() {
		$this->assertSame( '', IndexNow::key() );
		IndexNow::ensure_key();
		$this->assertSame( 32, strlen( IndexNow::key() ) );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{32}$/', IndexNow::key() );
	}

	public function test_disabled_by_default() {
		$this->assertFalse( IndexNow::enabled() );
	}

	public function test_submit_url_posts_to_indexnow() {
		IndexNow::ensure_key();
		$this->stub_http();

		IndexNow::instance()->submit_url( 'https://example.test/page/' );

		$this->assertSame( 1, $this->http_calls );
		$this->assertSame( IndexNow::ENDPOINT, $this->captured['url'] );
		$body = json_decode( $this->captured['args']['body'], true );
		$this->assertSame( IndexNow::key(), $body['key'] );
		$this->assertContains( 'https://example.test/page/', $body['urlList'] );
		$this->assertArrayHasKey( 'host', $body );
		$this->assertFalse( $this->captured['args']['blocking'] );
	}

	public function test_no_submit_when_no_key() {
		$this->stub_http();
		IndexNow::instance()->submit_url( 'https://example.test/page/' );
		$this->assertSame( 0, $this->http_calls );
	}

	public function test_transition_does_not_submit_when_disabled() {
		IndexNow::ensure_key();
		update_option( IndexNow::OPT_ENABLED, 0 );
		$this->stub_http();

		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		wp_update_post(
			array(
				'ID'         => $post_id,
				'post_title' => 'Updated',
			)
		);

		$this->assertSame( 0, $this->http_calls, 'No submission while IndexNow is disabled' );
	}
}
