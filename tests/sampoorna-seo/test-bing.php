<?php
/**
 * Tests for Bing Webmaster URL submission (payload/endpoint + gating).
 *
 * @package Sampoorna\SEO
 */

use Sampoorna\SEO\Technical\BingSubmit;

class Sampoorna_Seo_Bing_Test extends WP_UnitTestCase {

	public function test_payload_has_site_and_url() {
		$p = BingSubmit::payload( 'https://example.com/', 'https://example.com/page/' );
		$this->assertSame( 'https://example.com/', $p['siteUrl'] );
		$this->assertSame( 'https://example.com/page/', $p['url'] );
	}

	public function test_endpoint_carries_the_api_key() {
		$url = BingSubmit::endpoint( 'abc123' );
		$this->assertStringStartsWith( BingSubmit::ENDPOINT, $url );
		$this->assertStringContainsString( 'apikey=abc123', $url );
	}

	public function test_is_configured_requires_enabled_and_key() {
		$this->assertFalse( BingSubmit::is_configured() );

		update_option( BingSubmit::OPT_ENABLED, 1 );
		$this->assertFalse( BingSubmit::is_configured() ); // No key yet.

		update_option( BingSubmit::OPT_KEY, \Sampoorna\SEO\Security\Crypto::encrypt( 'k-123' ) );
		$this->assertTrue( BingSubmit::is_configured() );
		$this->assertSame( 'k-123', BingSubmit::api_key() );
	}
}
