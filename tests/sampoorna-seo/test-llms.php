<?php
/**
 * Tests for the llms.txt / llms-full.txt generator.
 *
 * @package Sampoorna\SEO
 */

use Sampoorna\SEO\Geo\LlmsTxt;
use Sampoorna\SEO\Meta\MetaStore;

class Sampoorna_Seo_Llms_Test extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		update_option( LlmsTxt::OPT_ENABLED, 1 );
		// Force fresh generation each test.
		update_option( LlmsTxt::OPT_VERSION, (int) get_option( LlmsTxt::OPT_VERSION, 1 ) + 1 );
	}

	public function test_index_lists_published_post_with_heading() {
		$post = self::factory()->post->create(
			array(
				'post_title'   => 'Hyderabad SEO Guide',
				'post_status'  => 'publish',
				'post_content' => 'A useful guide to local SEO in Hyderabad and beyond.',
			)
		);
		$out = LlmsTxt::instance()->generate( 'index' );

		$this->assertStringContainsString( '# ', $out );           // site/org heading
		$this->assertStringContainsString( '## ', $out );          // a section
		$this->assertStringContainsString( 'Hyderabad SEO Guide', $out );
		$this->assertStringContainsString( '(' . get_permalink( $post ) . ')', $out );
	}

	public function test_noindexed_post_excluded() {
		$post = self::factory()->post->create(
			array(
				'post_title'  => 'Hidden Page Xyzzy',
				'post_status' => 'publish',
			)
		);
		MetaStore::save( $post, array( 'robots_noindex' => '1' ) );
		update_option( LlmsTxt::OPT_VERSION, (int) get_option( LlmsTxt::OPT_VERSION, 1 ) + 1 );

		$out = LlmsTxt::instance()->generate( 'index' );
		$this->assertStringNotContainsString( 'Hidden Page Xyzzy', $out );
	}

	public function test_full_includes_content() {
		self::factory()->post->create(
			array(
				'post_title'   => 'Content Post',
				'post_status'  => 'publish',
				'post_content' => 'The quick brown fox jumps over the lazy dog.',
			)
		);
		update_option( LlmsTxt::OPT_VERSION, (int) get_option( LlmsTxt::OPT_VERSION, 1 ) + 1 );

		$out = LlmsTxt::instance()->generate( 'full' );
		$this->assertStringContainsString( 'The quick brown fox jumps over the lazy dog.', $out );
	}

	public function test_description_prefers_meta_override() {
		$post = self::factory()->post->create(
			array(
				'post_title'   => 'Override Post',
				'post_status'  => 'publish',
				'post_content' => 'Body content that should not be used as the description.',
			)
		);
		MetaStore::save( $post, array( 'desc' => 'Explicit SEO description wins.' ) );
		update_option( LlmsTxt::OPT_VERSION, (int) get_option( LlmsTxt::OPT_VERSION, 1 ) + 1 );

		$out = LlmsTxt::instance()->generate( 'index' );
		$this->assertStringContainsString( 'Explicit SEO description wins.', $out );
		$this->assertStringNotContainsString( 'Body content that should not be used', $out );
	}

	public function test_disabled_returns_empty() {
		update_option( LlmsTxt::OPT_ENABLED, 0 );
		$this->assertSame( '', LlmsTxt::instance()->generate( 'index' ) );
	}

	public function test_unknown_variant_returns_empty() {
		$this->assertSame( '', LlmsTxt::instance()->generate( 'nope' ) );
	}
}
