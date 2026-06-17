<?php
/**
 * Tests for the robots.txt editor and hreflang output.
 *
 * @package Sampoorna\SEO
 */

use Sampoorna\SEO\Technical\Robots;

class Sampoorna_Seo_Technical_Test extends WP_UnitTestCase {

	public function test_custom_robots_txt_replaces_default() {
		update_option( Robots::OPT_BODY, "User-agent: *\nDisallow: /private/" );
		$out = apply_filters( 'robots_txt', "User-agent: *\nDisallow:\n", true );
		$this->assertStringContainsString( 'Disallow: /private/', $out );
	}

	public function test_empty_robots_txt_keeps_default() {
		update_option( Robots::OPT_BODY, '' );
		$default = "User-agent: *\nDisallow:\n";
		$out     = apply_filters( 'robots_txt', $default, true );
		// Our filter leaves it unchanged; the Sitemap filter appends a Sitemap line.
		$this->assertStringContainsString( 'User-agent: *', $out );
	}

	public function test_robots_txt_untouched_when_not_public() {
		update_option( Robots::OPT_BODY, 'Disallow: /x/' );
		$out = apply_filters( 'robots_txt', 'DEFAULT', false );
		$this->assertStringNotContainsString( 'Disallow: /x/', $out );
	}

	public function test_hreflang_emitted_from_filter() {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		add_filter(
			'sampoorna_seo_hreflang_alternates',
			static function () {
				return array(
					'en'        => 'https://example.test/en/',
					'fr'        => 'https://example.test/fr/',
					'x-default' => 'https://example.test/en/',
				);
			}
		);

		$this->go_to( get_permalink( $post_id ) );
		ob_start();
		do_action( 'wp_head' );
		$head = ob_get_clean();

		$this->assertStringContainsString( 'hreflang="en"', $head );
		$this->assertStringContainsString( 'hreflang="fr"', $head );
		$this->assertStringContainsString( 'hreflang="x-default"', $head );
	}

	public function test_no_hreflang_without_alternates() {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->go_to( get_permalink( $post_id ) );
		ob_start();
		do_action( 'wp_head' );
		$head = ob_get_clean();
		$this->assertStringNotContainsString( 'rel="alternate" hreflang', $head );
	}
}
