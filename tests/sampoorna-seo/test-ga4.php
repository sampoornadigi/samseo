<?php
/**
 * Tests for the GA4 Analytics reader (report parsing + readiness gating).
 *
 * @package Sampoorna\SEO
 */

use Sampoorna\SEO\Integrations\GA4\Analytics;

class Sampoorna_Seo_Ga4_Test extends WP_UnitTestCase {

	public function test_parse_report_maps_named_metrics() {
		$data = array(
			'metricHeaders' => array(
				array( 'name' => 'sessions' ),
				array( 'name' => 'totalUsers' ),
				array( 'name' => 'screenPageViews' ),
				array( 'name' => 'conversions' ),
			),
			'rows'          => array(
				array(
					'metricValues' => array(
						array( 'value' => '1500' ),
						array( 'value' => '1200' ),
						array( 'value' => '3400' ),
						array( 'value' => '12' ),
					),
				),
			),
		);
		$s = Analytics::parse_report( $data );
		$this->assertSame( 1500, $s['sessions'] );
		$this->assertSame( 1200, $s['users'] );
		$this->assertSame( 3400, $s['views'] );
		$this->assertSame( 12.0, $s['conversions'] );
	}

	public function test_parse_report_respects_header_order() {
		$data = array(
			'metricHeaders' => array(
				array( 'name' => 'conversions' ),
				array( 'name' => 'sessions' ),
			),
			'rows'          => array(
				array( 'metricValues' => array( array( 'value' => '5' ), array( 'value' => '99' ) ) ),
			),
		);
		$s = Analytics::parse_report( $data );
		$this->assertSame( 99, $s['sessions'] );
		$this->assertSame( 5.0, $s['conversions'] );
	}

	public function test_parse_report_zeros_for_no_rows() {
		$s = Analytics::parse_report( array( 'metricHeaders' => array( array( 'name' => 'sessions' ) ), 'rows' => array() ) );
		$this->assertSame( 0, $s['sessions'] );
		$this->assertSame( 0.0, $s['conversions'] );
	}

	public function test_property_strips_non_digits() {
		update_option( Analytics::OPT_PROPERTY, 'properties/123-456' );
		$this->assertSame( '123456', Analytics::instance()->property() );
	}

	public function test_is_ready_false_without_connection() {
		update_option( Analytics::OPT_PROPERTY, '123456789' );
		// No OAuth token is stored in the test environment, so GA4 is not ready.
		$this->assertFalse( Analytics::instance()->is_ready() );
	}
}
