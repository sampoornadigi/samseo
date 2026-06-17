<?php
/**
 * Tests for the Phase 0 meta engine: store, template engine, renderer, analyzer.
 *
 * @package Sampoorna\SEO
 */

use Sampoorna\SEO\Meta\MetaStore;
use Sampoorna\SEO\Meta\TemplateEngine;
use Sampoorna\SEO\Content\Analyzer;

class Sampoorna_Seo_Meta_Test extends WP_UnitTestCase {

	public function test_meta_classes_exist() {
		$this->assertTrue( class_exists( 'Sampoorna\SEO\Meta\MetaStore' ) );
		$this->assertTrue( class_exists( 'Sampoorna\SEO\Meta\TemplateEngine' ) );
		$this->assertTrue( class_exists( 'Sampoorna\SEO\Meta\Renderer' ) );
		$this->assertTrue( class_exists( 'Sampoorna\SEO\Content\Analyzer' ) );
		$this->assertTrue( class_exists( 'Sampoorna\SEO\Admin\MetaBox' ) );
	}

	public function test_metastore_save_get_round_trip() {
		$post_id = self::factory()->post->create();

		MetaStore::save(
			$post_id,
			array(
				'title'          => '  Custom Title  ',
				'desc'           => "Line\nbreak desc",
				'canonical'      => 'https://example.test/canonical/',
				'robots_noindex' => '1',
				'focus_keyword'  => 'hyderabad seo',
			)
		);

		$this->assertSame( 'Custom Title', MetaStore::get( $post_id, 'title' ) );
		$this->assertSame( '1', MetaStore::get( $post_id, 'robots_noindex' ) );
		$this->assertSame( 'https://example.test/canonical/', MetaStore::get( $post_id, 'canonical' ) );
		$this->assertSame( 'hyderabad seo', MetaStore::get( $post_id, 'focus_keyword' ) );

		// Empty value deletes the row.
		MetaStore::save( $post_id, array( 'title' => '' ) );
		$this->assertSame( '', MetaStore::get( $post_id, 'title' ) );
	}

	public function test_template_engine_resolves_and_strips() {
		$post_id = self::factory()->post->create( array( 'post_title' => 'Sample Post' ) );
		$post    = get_post( $post_id );

		$out = TemplateEngine::render(
			'%title% %sep% %sitename%',
			array(
				'post'  => $post,
				'title' => 'Sample Post',
			)
		);
		$this->assertStringContainsString( 'Sample Post', $out );
		$this->assertStringContainsString( get_bloginfo( 'name' ), $out );

		// Unknown tokens are stripped and whitespace tidied.
		$this->assertSame( 'Sample Post', TemplateEngine::render( '%title% %bogus_token%', array( 'title' => 'Sample Post' ) ) );
	}

	public function test_renderer_outputs_seo_tags_in_head() {
		$post_id = self::factory()->post->create(
			array(
				'post_title'   => 'Renderer Post',
				'post_status'  => 'publish',
				'post_content' => 'Some body content for the renderer test.',
			)
		);
		MetaStore::save(
			$post_id,
			array(
				'title' => 'My SEO Title',
				'desc'  => 'My meta description for the renderer test.',
			)
		);

		$this->go_to( get_permalink( $post_id ) );
		$this->assertTrue( is_singular() );

		ob_start();
		wp_head();
		$head = ob_get_clean();

		$this->assertStringContainsString( '<meta name="description" content="My meta description for the renderer test."', $head );
		$this->assertStringContainsString( 'property="og:title" content="My SEO Title"', $head );
		// Exactly one canonical tag (core's rel_canonical was removed).
		$this->assertSame( 1, substr_count( $head, 'rel="canonical"' ) );

		// Document title reflects the override.
		$this->assertStringContainsString( 'My SEO Title', wp_get_document_title() );
	}

	public function test_renderer_noindex_via_wp_robots() {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		MetaStore::save( $post_id, array( 'robots_noindex' => '1' ) );

		$this->go_to( get_permalink( $post_id ) );

		ob_start();
		wp_robots();
		$robots = ob_get_clean();

		$this->assertStringContainsString( 'noindex', $robots );
	}

	public function test_analyzer_returns_bounded_score() {
		$post_id = self::factory()->post->create(
			array(
				'post_title'   => 'Hyderabad SEO services guide for growing local businesses',
				'post_name'    => 'hyderabad-seo-services',
				'post_content' => str_repeat( 'Hyderabad SEO services help local businesses rank. ', 40 ),
			)
		);
		$meta = array(
			'title'         => 'Hyderabad SEO Services — Expert Local SEO in Hyderabad',
			'desc'          => 'Grow your Hyderabad business with expert local SEO services that improve rankings, traffic, and leads over time for you.',
			'focus_keyword' => 'hyderabad seo',
		);

		$result = Analyzer::analyze( $post_id, $meta );

		$this->assertIsInt( $result['score'] );
		$this->assertGreaterThanOrEqual( 0, $result['score'] );
		$this->assertLessThanOrEqual( 100, $result['score'] );
		$this->assertNotEmpty( $result['checks'] );
		// A well-optimized post should score reasonably well.
		$this->assertGreaterThan( 60, $result['score'] );

		$ids = wp_list_pluck( $result['checks'], 'id' );
		$this->assertContains( 'title_length', $ids );
		$this->assertContains( 'kw_in_title', $ids );
	}
}
