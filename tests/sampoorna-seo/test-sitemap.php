<?php
/**
 * Tests for the paginated XML sitemap generator.
 *
 * @package Sampoorna\SEO
 */

use Sampoorna\SEO\Technical\Sitemap;
use Sampoorna\SEO\Meta\MetaStore;

class Sampoorna_Seo_Sitemap_Test extends WP_UnitTestCase {

	private function sitemap() {
		return Sitemap::instance();
	}

	public function test_core_sitemaps_disabled() {
		$this->assertFalse( apply_filters( 'wp_sitemaps_enabled', true ) );
	}

	public function test_robots_txt_advertises_sitemap() {
		$out = apply_filters( 'robots_txt', '', true );
		$this->assertStringContainsString( 'Sitemap:', $out );
		$this->assertStringContainsString( 'sitemap_index.xml', $out );
	}

	public function test_index_is_wellformed_and_lists_post_subsitemap() {
		self::factory()->post->create_many( 2, array( 'post_status' => 'publish' ) );

		$xml = $this->sitemap()->generate_index();

		$this->assertNotFalse( simplexml_load_string( $xml ), 'Index XML should be well-formed' );
		$this->assertStringContainsString( 'post-sitemap1.xml', $xml );
	}

	public function test_subtype_includes_published_excludes_noindex_and_drafts() {
		$keep      = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$noindexed = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$draft     = self::factory()->post->create( array( 'post_status' => 'draft' ) );

		MetaStore::save( $noindexed, array( 'robots_noindex' => '1' ) );

		$xml = $this->sitemap()->generate_subtype( 'post', 1 );

		$this->assertNotFalse( simplexml_load_string( $xml ) );
		$this->assertStringContainsString( get_permalink( $keep ), $xml );
		$this->assertStringNotContainsString( get_permalink( $noindexed ), $xml );
		$this->assertStringNotContainsString( get_permalink( $draft ), $xml );
	}

	public function test_pagination_splits_pages_and_out_of_range_is_empty() {
		update_option( 'show_on_front', 'page' ); // Suppress the home-URL prepend on page 1.
		add_filter( 'sampoorna_seo_sitemap_page_size', static function () {
			return 2;
		} );

		$ids = self::factory()->post->create_many( 3, array( 'post_status' => 'publish' ) );

		$providers = $this->sitemap()->providers();
		$post_prov = null;
		foreach ( $providers as $p ) {
			if ( 'post' === $p['subtype'] ) {
				$post_prov = $p;
			}
		}
		$this->assertNotNull( $post_prov );
		$this->assertSame( 2, $post_prov['pages'], '3 posts at page size 2 → 2 pages' );

		$page1 = $this->sitemap()->generate_subtype( 'post', 1 );
		$page2 = $this->sitemap()->generate_subtype( 'post', 2 );
		$this->assertNotFalse( simplexml_load_string( $page2 ) );

		// Page 2 holds exactly the overflow item; page 3 is out of range.
		$this->assertSame( 1, substr_count( $page2, '<loc>' ) );
		$this->assertSame( '', $this->sitemap()->generate_subtype( 'post', 3 ) );

		// Every created post appears across the two pages.
		foreach ( $ids as $id ) {
			$found = false !== strpos( $page1, get_permalink( $id ) ) || false !== strpos( $page2, get_permalink( $id ) );
			$this->assertTrue( $found, "Post {$id} should appear in some page" );
		}
	}

	public function test_generation_is_cached_in_transient() {
		self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$version = (int) get_option( Sitemap::OPT_VERSION, 1 );
		$key     = 'sseo_sm_' . md5( 'post|1|' . $version );

		$this->assertFalse( get_transient( $key ) );
		$this->sitemap()->generate_subtype( 'post', 1 );
		$this->assertIsString( get_transient( $key ), 'Rendered sub-sitemap should be cached' );
	}
}
