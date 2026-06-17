<?php
/**
 * Tests for the AI service layer (AiClient), using a stubbed HTTP transport.
 *
 * @package Sampoorna\SEO
 */

use Sampoorna\SEO\Ai\AiClient;
use Sampoorna\SEO\Security\Crypto;

class Sampoorna_Seo_Ai_Test extends WP_UnitTestCase {

	/** @var int Number of times the stubbed transport was hit. */
	private $http_calls = 0;

	/** @var array Last captured request (url + args). */
	private $captured = array();

	public function set_up() {
		parent::set_up();
		$this->http_calls = 0;
		$this->captured   = array();
	}

	/**
	 * Stub the Anthropic Messages API. Returns a 200 whose text block is the
	 * structured-output JSON, unless $raw_text is provided (malformed cases).
	 *
	 * @param string      $title    Title to return.
	 * @param string      $desc     Meta description to return.
	 * @param string|null $raw_text Override the text block verbatim.
	 * @return void
	 */
	private function stub_http( $title = 'Generated SEO Title', $desc = 'A generated meta description from the stub.', $raw_text = null ) {
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( $title, $desc, $raw_text ) {
				++$this->http_calls;
				$this->captured = array(
					'url'  => $url,
					'args' => $args,
				);
				$text = null === $raw_text
					? (string) wp_json_encode(
						array(
							'title'            => $title,
							'meta_description' => $desc,
						)
					)
					: $raw_text;
				return array(
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'headers'  => array(),
					'body'     => (string) wp_json_encode(
						array(
							'content' => array(
								array(
									'type' => 'text',
									'text' => $text,
								),
							),
						)
					),
				);
			},
			10,
			3
		);
	}

	private function set_key() {
		update_option( AiClient::OPT_API_KEY, Crypto::encrypt( 'sk-ant-test-key' ), false );
	}

	public function test_is_configured_reflects_stored_key() {
		$this->assertFalse( AiClient::is_configured() );
		$this->set_key();
		$this->assertTrue( AiClient::is_configured() );
	}

	public function test_generate_returns_error_without_key() {
		$post_id = self::factory()->post->create();
		$result  = AiClient::generate_title_meta( $post_id );
		$this->assertWPError( $result );
		$this->assertSame( 'sampoorna_seo_ai_unconfigured', $result->get_error_code() );
	}

	public function test_generate_parses_structured_output() {
		$this->set_key();
		$this->stub_http( 'Best Hyderabad SEO Services', 'Grow your Hyderabad business with expert local SEO.' );
		$post_id = self::factory()->post->create( array( 'post_content' => 'Local SEO services in Hyderabad.' ) );

		$result = AiClient::generate_title_meta( $post_id, 'hyderabad seo' );

		$this->assertIsArray( $result );
		$this->assertSame( 'Best Hyderabad SEO Services', $result['title'] );
		$this->assertSame( 'Grow your Hyderabad business with expert local SEO.', $result['description'] );
	}

	public function test_request_targets_messages_api_with_headers_and_schema() {
		$this->set_key();
		$this->stub_http();
		$post_id = self::factory()->post->create();

		AiClient::generate_title_meta( $post_id );

		$this->assertSame( AiClient::ENDPOINT, $this->captured['url'] );
		$headers = $this->captured['args']['headers'];
		$this->assertArrayHasKey( 'x-api-key', $headers );
		$this->assertSame( AiClient::API_VERSION, $headers['anthropic-version'] );

		$body = json_decode( $this->captured['args']['body'], true );
		$this->assertSame( 'claude-haiku-4-5', $body['model'] );
		$this->assertSame( 'json_schema', $body['output_config']['format']['type'] );
	}

	public function test_identical_request_is_cached() {
		$this->set_key();
		$this->stub_http();
		$post_id = self::factory()->post->create( array( 'post_content' => 'Stable content for caching.' ) );

		AiClient::generate_title_meta( $post_id, 'kw' );
		AiClient::generate_title_meta( $post_id, 'kw' );

		$this->assertSame( 1, $this->http_calls, 'Second identical call should be served from the content-hash cache.' );
	}

	public function test_malformed_response_is_error_not_fatal() {
		$this->set_key();
		$this->stub_http( '', '', 'this is not json' );
		$post_id = self::factory()->post->create();

		$result = AiClient::generate_title_meta( $post_id );
		$this->assertWPError( $result );
	}
}
