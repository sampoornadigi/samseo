<?php
/**
 * Tests for the control-plane health-signals aggregator + /metrics route.
 *
 * @package Sampoorna\SEO
 */

use Sampoorna\SEO\ControlPlane\Metrics;
use Sampoorna\SEO\ControlPlane\Keys;
use Sampoorna\SEO\Meta\MetaStore;
use Sampoorna\SEO\Security\Signer;

class Sampoorna_Seo_Metrics_Test extends WP_UnitTestCase {

	const METRICS_ROUTE = '/sampoorna-seo/v1/metrics';

	public function set_up() {
		parent::set_up();
		Keys::rotate();
		delete_transient( Metrics::SAMPLE_TRANSIENT );
		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init' );
	}

	public function test_collect_returns_all_dimensions() {
		$m = Metrics::collect();
		foreach ( array( 'schema', 'generated_at', 'content', 'technical', 'authority', 'geo', 'ux' ) as $key ) {
			$this->assertArrayHasKey( $key, $m );
		}
		$this->assertFalse( $m['ux']['available'] );
		$this->assertFalse( $m['authority']['gsc_connected'] );
		// With GSC disconnected, traffic figures are null (no fabrication).
		$this->assertNull( $m['authority']['clicks_28d'] );
	}

	public function test_missing_meta_counts() {
		// One fully-tagged post, two bare posts.
		$tagged = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		MetaStore::save(
			$tagged,
			array(
				'title'         => 'A good SEO title here',
				'desc'          => 'A meta description long enough to be useful for the snippet preview.',
				'focus_keyword' => 'widgets',
			)
		);
		self::factory()->post->create( array( 'post_status' => 'publish' ) );
		self::factory()->post->create( array( 'post_status' => 'publish' ) );

		delete_transient( Metrics::SAMPLE_TRANSIENT );
		$c = Metrics::collect()['content'];

		$this->assertGreaterThanOrEqual( 3, $c['published'] );
		// Two of the three created posts have no title/desc/focus meta.
		$this->assertGreaterThanOrEqual( 2, $c['missing_title'] );
		$this->assertGreaterThanOrEqual( 2, $c['missing_desc'] );
		$this->assertGreaterThanOrEqual( 2, $c['missing_focus'] );
	}

	public function test_sample_averages_are_bounded_or_null() {
		self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => '<h2>What is it?</h2><p>A short, clear answer for readers.</p><ul><li>one</li><li>two</li></ul>',
			)
		);
		delete_transient( Metrics::SAMPLE_TRANSIENT );
		$c = Metrics::collect()['content'];

		foreach ( array( 'avg_onpage', 'avg_readability', 'avg_aeo' ) as $k ) {
			if ( null !== $c[ $k ] ) {
				$this->assertIsInt( $c[ $k ] );
				$this->assertGreaterThanOrEqual( 0, $c[ $k ] );
				$this->assertLessThanOrEqual( 100, $c[ $k ] );
			}
		}
	}

	public function test_technical_signals_present() {
		$t = Metrics::collect()['technical'];
		foreach ( array( 'redirects_active', 'not_found_new', 'issues', 'robots_configured', 'indexnow_enabled', 'sitemap_cached' ) as $k ) {
			$this->assertArrayHasKey( $k, $t );
		}
		$this->assertIsInt( $t['redirects_active'] );
		$this->assertIsArray( $t['issues'] );
	}

	public function test_metrics_route_requires_signature() {
		$res = rest_get_server()->dispatch( new \WP_REST_Request( 'GET', self::METRICS_ROUTE ) );
		$this->assertSame( 401, $res->get_status() );
	}

	public function test_metrics_route_with_valid_signature() {
		$ts  = (string) time();
		$sig = Signer::sign( 'GET', self::METRICS_ROUTE, $ts, '', Keys::secret() );
		$req = new \WP_REST_Request( 'GET', self::METRICS_ROUTE );
		$req->set_header( 'X-Sampoorna-Key-Id', Keys::key_id() );
		$req->set_header( 'X-Sampoorna-Timestamp', $ts );
		$req->set_header( 'X-Sampoorna-Signature', $sig );

		$res = rest_get_server()->dispatch( $req );
		$this->assertSame( 200, $res->get_status() );
		$data = $res->get_data();
		$this->assertSame( 1, $data['schema'] );
		$this->assertArrayHasKey( 'content', $data );
		$this->assertArrayHasKey( 'technical', $data );
	}
}
