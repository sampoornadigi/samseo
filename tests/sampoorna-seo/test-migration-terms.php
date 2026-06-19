<?php
/**
 * Tests for term/taxonomy migration (Yoast/RM/AIOSEO) + term rendering.
 *
 * @package Sampoorna\SEO
 */

use Sampoorna\SEO\Migration\YoastSource;
use Sampoorna\SEO\Migration\RankMathSource;
use Sampoorna\SEO\Migration\AioseoSource;
use Sampoorna\SEO\Migration\Migrator;
use Sampoorna\SEO\Meta\TermMeta;

class Sampoorna_Seo_Migration_Terms_Test extends WP_UnitTestCase {

	public function tear_down() {
		delete_option( 'wpseo_taxonomy_meta' );
		parent::tear_down();
	}

	/* ---------- Yoast: wpseo_taxonomy_meta option (the trap) ---------- */

	public function test_yoast_term_read_from_option() {
		$cat = self::factory()->category->create();
		update_option(
			'wpseo_taxonomy_meta',
			array(
				'category' => array(
					$cat => array(
						'wpseo_title'   => '%%term_title%% %%sep%% %%sitename%%',
						'wpseo_desc'    => 'Category description.',
						'wpseo_noindex' => 'noindex',
					),
				),
			)
		);

		$source = new YoastSource();
		$this->assertSame( 1, $source->term_count() );
		$this->assertContains( $cat, $source->term_ids( 0, 50 ) );

		$fields = $source->read_term( $cat );
		// %%term_title%% is unknown to our normalizer → stripped; %%sep%%/%%sitename%% map.
		$this->assertSame( '%sep% %sitename%', $fields['title'] );
		$this->assertSame( 'Category description.', $fields['desc'] );
		$this->assertSame( '1', $fields['robots_noindex'] );

		Migrator::import( $source, 50, 0, 'term' );
		$this->assertSame( 'Category description.', TermMeta::get( $cat, 'desc' ) );
		$this->assertSame( '1', TermMeta::get( $cat, 'robots_noindex' ) );
		// Non-destructive: the Yoast option is untouched.
		$opt = get_option( 'wpseo_taxonomy_meta' );
		$this->assertSame( 'noindex', $opt['category'][ $cat ]['wpseo_noindex'] );
		// Idempotent.
		$second = Migrator::import( $source, 50, 0, 'term' );
		$this->assertSame( 0, $second['written'] );
	}

	/* ---------- Rank Math: termmeta ---------- */

	public function test_rankmath_term_read_from_termmeta() {
		$cat = self::factory()->category->create();
		update_term_meta( $cat, 'rank_math_title', '%title% %sep% %sitename%' );
		update_term_meta( $cat, 'rank_math_description', 'RM term desc.' );
		update_term_meta( $cat, 'rank_math_robots', array( 'noindex' ) );

		$source = new RankMathSource();
		$this->assertSame( 1, $source->term_count() );

		$fields = $source->read_term( $cat );
		$this->assertSame( '%title% %sep% %sitename%', $fields['title'] );
		$this->assertSame( 'RM term desc.', $fields['desc'] );
		$this->assertSame( '1', $fields['robots_noindex'] );

		Migrator::import( $source, 50, 0, 'term' );
		$this->assertSame( 'RM term desc.', TermMeta::get( $cat, 'desc' ) );
		// Source termmeta intact.
		$this->assertSame( 'RM term desc.', get_term_meta( $cat, 'rank_math_description', true ) );
	}

	/* ---------- AIOSEO: aioseo_terms table (reuses map_row) ---------- */

	public function test_aioseo_term_absent_table() {
		$this->assertSame( 0, ( new AioseoSource() )->term_count() );
		$this->assertSame( array(), ( new AioseoSource() )->term_ids( 0, 10 ) );
	}

	/* ---------- Renderer term archive output ---------- */

	public function test_renderer_outputs_term_meta_on_category_archive() {
		$cat = self::factory()->category->create();
		// A post in the category so the archive is not a 404.
		$post = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		wp_set_object_terms( $post, array( $cat ), 'category' );

		TermMeta::save(
			$cat,
			array(
				'title'          => 'Custom Cat Title',
				'desc'           => 'Custom cat description.',
				'canonical'      => 'https://example.test/custom-cat/',
				'robots_noindex' => '1',
			)
		);

		$this->go_to( get_term_link( $cat, 'category' ) );
		$this->assertTrue( is_category() );

		$renderer = \Sampoorna\SEO\Meta\Renderer::instance();

		ob_start();
		$renderer->output();
		$head = ob_get_clean();
		$this->assertStringContainsString( 'Custom cat description.', $head );
		$this->assertStringContainsString( 'https://example.test/custom-cat/', $head );

		$this->assertSame( 'Custom Cat Title', $renderer->filter_title( 'fallback' ) );

		$robots = $renderer->filter_robots( array() );
		$this->assertTrue( ! empty( $robots['noindex'] ) );
	}
}
