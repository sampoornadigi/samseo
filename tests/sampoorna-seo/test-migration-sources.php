<?php
/**
 * Tests for the Rank Math + AIOSEO migration sources (Phase 3 slice 2).
 *
 * @package Sampoorna\SEO
 */

use Sampoorna\SEO\Migration\RankMathSource;
use Sampoorna\SEO\Migration\AioseoSource;
use Sampoorna\SEO\Migration\TokenNormalizer;
use Sampoorna\SEO\Migration\Migrator;
use Sampoorna\SEO\Meta\MetaStore;

class Sampoorna_Seo_Migration_Sources_Test extends WP_UnitTestCase {

	/* ---------- TokenNormalizer ---------- */

	public function test_normalize_rankmath() {
		// Matching tokens pass through; differing ones remap; unknown stripped.
		$this->assertSame( '%title% %sep% %sitename%', TokenNormalizer::normalize_rankmath( '%title% %sep% %sitename%' ) );
		$this->assertSame( '%title%', TokenNormalizer::normalize_rankmath( '%seo_title%' ) );
		$this->assertSame( 'Buy now', TokenNormalizer::normalize_rankmath( 'Buy now %customfield(x)%' ) );
	}

	public function test_normalize_aioseo() {
		$this->assertSame( '%title% %sep% %sitename%', TokenNormalizer::normalize_aioseo( '#post_title #separator_sa #site_title' ) );
		$this->assertSame( 'Plain', TokenNormalizer::normalize_aioseo( 'Plain #unknown_tag' ) );
	}

	/* ---------- Rank Math ---------- */

	public function test_rankmath_read_and_import() {
		$id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		update_post_meta( $id, 'rank_math_title', '%title% %sep% %sitename%' );
		update_post_meta( $id, 'rank_math_description', 'RM description.' );
		update_post_meta( $id, 'rank_math_focus_keyword', 'primary kw,second kw' );
		update_post_meta( $id, 'rank_math_robots', array( 'noindex', 'nofollow' ) );

		$source = new RankMathSource();
		$this->assertTrue( $source->is_present() );
		$this->assertSame( 1, $source->count() );

		$fields = $source->read( $id );
		$this->assertSame( '%title% %sep% %sitename%', $fields['title'] );
		$this->assertSame( 'RM description.', $fields['desc'] );
		$this->assertSame( 'primary kw', $fields['focus_keyword'] );
		$this->assertSame( '1', $fields['robots_noindex'] );
		$this->assertSame( '1', $fields['robots_nofollow'] );

		Migrator::import( $source, 50, 0 );
		$this->assertSame( '%title% %sep% %sitename%', MetaStore::get( $id, 'title' ) );
		$this->assertSame( 'primary kw', MetaStore::get( $id, 'focus_keyword' ) );
		// Non-destructive: Rank Math meta is left intact.
		$this->assertSame( 'RM description.', get_post_meta( $id, 'rank_math_description', true ) );
		// Idempotent.
		$second = Migrator::import( $source, 50, 0 );
		$this->assertSame( 0, $second['written'] );
	}

	/* ---------- AIOSEO (pure mapping) ---------- */

	public function test_aioseo_map_row() {
		$out = AioseoSource::map_row(
			array(
				'post_id'             => 7,
				'title'               => '#post_title #separator_sa #site_title',
				'description'         => 'AIOSEO desc.',
				'canonical_url'       => 'https://example.test/c/',
				'keyphrases'          => '{"focus":{"keyphrase":"local seo"},"additional":[]}',
				'og_title'            => 'OG #post_title',
				'og_description'      => 'OG desc',
				'og_image_custom_url' => 'https://example.test/img.jpg',
				'robots_default'      => 0,
				'robots_noindex'      => 1,
				'robots_nofollow'     => 0,
			)
		);

		$this->assertSame( '%title% %sep% %sitename%', $out['title'] );
		$this->assertSame( 'AIOSEO desc.', $out['desc'] );
		$this->assertSame( 'https://example.test/c/', $out['canonical'] );
		$this->assertSame( 'local seo', $out['focus_keyword'] );
		$this->assertSame( 'OG %title%', $out['og_title'] );
		$this->assertSame( 'https://example.test/img.jpg', $out['og_image'] );
		$this->assertSame( '1', $out['robots_noindex'] );
		$this->assertArrayNotHasKey( 'robots_nofollow', $out );
	}

	public function test_aioseo_robots_default_ignored() {
		// robots_default = 1 means "use site default" — per-post robots ignored.
		$out = AioseoSource::map_row(
			array(
				'title'          => 'T',
				'robots_default' => 1,
				'robots_noindex' => 1,
			)
		);
		$this->assertArrayNotHasKey( 'robots_noindex', $out );
	}

	public function test_aioseo_absent_table_is_not_present() {
		// The wp_aioseo_posts table does not exist in the test DB.
		$this->assertFalse( ( new AioseoSource() )->is_present() );
		$this->assertSame( 0, ( new AioseoSource() )->count() );
		$this->assertSame( array(), ( new AioseoSource() )->target_ids( 0, 10 ) );
	}
}
