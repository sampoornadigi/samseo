<?php
/**
 * Tests the single-plugin platform embeds: AdSync analytics + CRM chat widget
 * injection, and that the keys are control-plane-templatable.
 *
 * @package Sampoorna\SEO
 */

use Sampoorna\SEO\Platform\Embeds;
use Sampoorna\SEO\ControlPlane\Settings;

class Sampoorna_Seo_Embeds_Test extends WP_UnitTestCase {

	private function render() {
		ob_start();
		Embeds::instance()->output();
		return (string) ob_get_clean();
	}

	public function test_nothing_injected_when_no_keys() {
		$this->assertSame( '', trim( $this->render() ) );
		$this->assertSame( array( 'analytics' => false, 'widget' => false ), Embeds::active() );
	}

	public function test_analytics_script_injected_with_default_base() {
		update_option( Embeds::OPT_ANALYTICS_KEY, 'ak_test123' );
		$html = $this->render();
		$this->assertStringContainsString( 'platform.sampoornadigi.in/analytics/sdk.js', $html );
		$this->assertStringContainsString( 'data-key="ak_test123"', $html );
		$this->assertTrue( Embeds::active()['analytics'] );
	}

	public function test_widget_script_injected_with_key_and_api_base() {
		update_option( Embeds::OPT_WIDGET_KEY, 'wgt_abc' );
		$html = $this->render();
		$this->assertStringContainsString( '/embed/sampoorna-widget.js', $html );
		$this->assertStringContainsString( 'data-widget-key="wgt_abc"', $html );
		$this->assertStringContainsString( 'data-api-base="https://app.sampoornadigi.in"', $html );
	}

	public function test_custom_base_overrides_default() {
		update_option( Embeds::OPT_ANALYTICS_KEY, 'ak_x' );
		update_option( Embeds::OPT_ANALYTICS_BASE, 'https://ads.example.com/' );
		$html = $this->render();
		$this->assertStringContainsString( 'https://ads.example.com/analytics/sdk.js', $html );
		$this->assertStringNotContainsString( 'platform.sampoornadigi.in', $html );
	}

	public function test_embed_keys_are_control_plane_templatable() {
		foreach ( array( 'analytics_key', 'analytics_base', 'widget_key', 'widget_base' ) as $field ) {
			$this->assertTrue( Settings::has( $field ), "$field must be plane-templatable" );
		}
		// Round-trip through the same path the control plane /apply uses.
		Settings::write( 'widget_key', 'wgt_pushed' );
		$this->assertSame( 'wgt_pushed', Settings::read( 'widget_key' ) );
		$this->assertSame( 'wgt_pushed', get_option( Embeds::OPT_WIDGET_KEY ) );
	}
}
