<?php
/**
 * Tests for the Phase 3 migration framework + Yoast source.
 *
 * @package Sampoorna\SEO
 */

use Sampoorna\SEO\Migration\YoastSource;
use Sampoorna\SEO\Migration\TokenNormalizer;
use Sampoorna\SEO\Migration\Migrator;
use Sampoorna\SEO\Meta\MetaStore;

class Sampoorna_Seo_Migration_Test extends WP_UnitTestCase {

	/**
	 * Create a post carrying Yoast post meta.
	 *
	 * @param array<string,string> $yoast meta_key => value (without the _yoast_wpseo_ prefix handled by caller).
	 * @return int
	 */
	private function yoast_post( array $yoast ) {
		$id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		foreach ( $yoast as $key => $value ) {
			update_post_meta( $id, $key, $value );
		}
		return $id;
	}

	public function test_token_normalizer() {
		$this->assertSame( '%title% %sep% %sitename%', TokenNormalizer::normalize_yoast( '%%title%% %%sep%% %%sitename%%' ) );
		// Unknown tokens are stripped (never copied raw).
		$this->assertSame( 'Read this', TokenNormalizer::normalize_yoast( 'Read this %%unknown_token%%' ) );
		// Plain strings pass through untouched.
		$this->assertSame( 'Plain title', TokenNormalizer::normalize_yoast( 'Plain title' ) );
	}

	public function test_yoast_detection_and_count() {
		$source = new YoastSource();
		$this->assertFalse( $source->is_present() );

		$this->yoast_post( array( '_yoast_wpseo_title' => 'Hello %%sep%% %%sitename%%' ) );
		$this->yoast_post( array( '_yoast_wpseo_metadesc' => 'A description.' ) );

		$this->assertTrue( $source->is_present() );
		$this->assertSame( 2, $source->count() );
	}

	public function test_yoast_read_maps_and_normalizes() {
		$id = $this->yoast_post(
			array(
				'_yoast_wpseo_title'                  => '%%title%% %%sep%% %%sitename%%',
				'_yoast_wpseo_metadesc'               => 'My meta description.',
				'_yoast_wpseo_canonical'              => 'https://example.test/c/',
				'_yoast_wpseo_focuskw'                => 'hyderabad seo',
				'_yoast_wpseo_opengraph-title'        => 'OG %%title%%',
				'_yoast_wpseo_meta-robots-noindex'    => '1',
				'_yoast_wpseo_meta-robots-nofollow'   => '1',
			)
		);

		$fields = ( new YoastSource() )->read( $id );

		$this->assertSame( '%title% %sep% %sitename%', $fields['title'] );
		$this->assertSame( 'My meta description.', $fields['desc'] );
		$this->assertSame( 'https://example.test/c/', $fields['canonical'] );
		$this->assertSame( 'hyderabad seo', $fields['focus_keyword'] );
		$this->assertSame( 'OG %title%', $fields['og_title'] );
		$this->assertSame( '1', $fields['robots_noindex'] );
		$this->assertSame( '1', $fields['robots_nofollow'] );
	}

	public function test_diff_classifies_add_and_skip_exists() {
		$id = $this->yoast_post(
			array(
				'_yoast_wpseo_title'    => 'Yoast title',
				'_yoast_wpseo_metadesc' => 'Yoast desc',
			)
		);
		// Pre-set our own title so it should be skipped, leaving desc to add.
		MetaStore::save( $id, array( 'title' => 'Existing Sampoorna title' ) );

		$diff = Migrator::diff( new YoastSource() );
		$this->assertSame( 1, $diff['counts']['add'] );
		$this->assertSame( 1, $diff['counts']['skip_exists'] );
	}

	public function test_import_is_non_destructive_and_idempotent() {
		$id = $this->yoast_post(
			array(
				'_yoast_wpseo_title'    => 'Yoast title',
				'_yoast_wpseo_metadesc' => 'Yoast desc',
			)
		);

		$first = Migrator::import( new YoastSource(), 50, 0 );
		$this->assertSame( 1, $first['written'] );

		// Our fields are now filled...
		$this->assertSame( 'Yoast title', MetaStore::get( $id, 'title' ) );
		$this->assertSame( 'Yoast desc', MetaStore::get( $id, 'desc' ) );
		// ...and the source meta is untouched (rollback = deactivate).
		$this->assertSame( 'Yoast title', get_post_meta( $id, '_yoast_wpseo_title', true ) );

		// Re-running fills nothing new (idempotent): values already present.
		$second = Migrator::import( new YoastSource(), 50, 0 );
		$this->assertSame( 0, $second['written'] );
	}

	public function test_import_does_not_overwrite_existing_value() {
		$id = $this->yoast_post( array( '_yoast_wpseo_title' => 'Yoast title' ) );
		MetaStore::save( $id, array( 'title' => 'Keep me' ) );

		Migrator::import( new YoastSource(), 50, 0 );
		$this->assertSame( 'Keep me', MetaStore::get( $id, 'title' ) );
	}

	public function test_verify_reports_matches_after_import() {
		$this->yoast_post(
			array(
				'_yoast_wpseo_title'    => 'Yoast title',
				'_yoast_wpseo_metadesc' => 'Yoast desc',
			)
		);
		Migrator::import( new YoastSource(), 50, 0 );

		$v = Migrator::verify( new YoastSource() );
		$this->assertSame( 0, $v['mismatch'] );
		$this->assertGreaterThanOrEqual( 2, $v['match'] );
	}
}
