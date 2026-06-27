<?php
/**
 * Tests the GSC search-opportunity mining (striking-distance + low-CTR) against
 * seeded performance data for a "Venora" property.
 *
 * @package Sampoorna\SEO
 */

use Sampoorna\SEO\Core\Database;

class Sampoorna_Seo_Gsc_Opportunities_Test extends WP_UnitTestCase {

	const PROPERTY = 'https://venora.example/';

	public function set_up() {
		parent::set_up();
		Database::create_tables();
		update_option( 'sampoorna_seo_property', self::PROPERTY );
		$today = gmdate( 'Y-m-d' );

		// Query-dimension rows (page_url=''): only the in-band, high-impression one qualifies.
		$this->seed( array( 'query' => 'modular kitchen venora', 'date' => $today, 'clicks' => 10, 'impressions' => 500, 'ctr' => 0.02, 'position' => 8.0 ) );   // striking distance ✓
		$this->seed( array( 'query' => 'venora interiors', 'date' => $today, 'clicks' => 200, 'impressions' => 800, 'ctr' => 0.25, 'position' => 2.0 ) );           // already top-5 ✗
		$this->seed( array( 'query' => 'cheap furniture', 'date' => $today, 'clicks' => 1, 'impressions' => 300, 'ctr' => 0.003, 'position' => 35.0 ) );             // too deep ✗
		$this->seed( array( 'query' => 'obscure term', 'date' => $today, 'clicks' => 0, 'impressions' => 20, 'ctr' => 0.0, 'position' => 9.0 ) );                    // too few impressions ✗

		// Page-dimension row (query=''): a high-impression, low-CTR page.
		$this->seed( array( 'page' => 'https://venora.example/kitchens', 'date' => $today, 'clicks' => 1, 'impressions' => 400, 'ctr' => 0.0025, 'position' => 9.0 ) );
	}

	private function seed( array $row ) {
		Database::upsert_row( self::PROPERTY, $row );
	}

	public function test_striking_distance_returns_only_in_band_high_impression_queries() {
		$rows    = Database::striking_distance_queries( self::PROPERTY, 28, 50, 5.0, 20.0, 30 );
		$labels  = array_column( $rows, 'label' );

		$this->assertContains( 'modular kitchen venora', $labels );
		$this->assertNotContains( 'venora interiors', $labels, 'already top-5 excluded' );
		$this->assertNotContains( 'cheap furniture', $labels, 'position > 20 excluded' );
		$this->assertNotContains( 'obscure term', $labels, 'below impression floor excluded' );
	}

	public function test_low_ctr_pages_surfaces_the_seen_but_unclicked_page() {
		$rows   = Database::low_ctr_pages( self::PROPERTY, 28, 100, 0.01, 20.0, 30 );
		$pages  = array_column( $rows, 'page_url' );
		$this->assertContains( 'https://venora.example/kitchens', $pages );
	}
}
