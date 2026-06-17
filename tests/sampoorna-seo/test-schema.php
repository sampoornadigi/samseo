<?php
/**
 * Tests for the JSON-LD @graph schema engine.
 *
 * @package Sampoorna\SEO
 */

use Sampoorna\SEO\Schema\Graph;

class Sampoorna_Seo_Schema_Test extends WP_UnitTestCase {

	private function graph() {
		return Graph::instance()->build_graph();
	}

	/**
	 * Find the first node of a given @type in a graph.
	 *
	 * @param array  $graph Graph nodes.
	 * @param string $type  schema @type.
	 * @return array|null
	 */
	private function node( $graph, $type ) {
		foreach ( $graph as $n ) {
			if ( isset( $n['@type'] ) && $type === $n['@type'] ) {
				return $n;
			}
		}
		return null;
	}

	public function test_front_page_has_organization_and_website() {
		$this->go_to( home_url( '/' ) );
		$graph = $this->graph();
		$home  = home_url( '/' );

		$org = $this->node( $graph, 'Organization' );
		$ws  = $this->node( $graph, 'WebSite' );
		$this->assertNotNull( $org );
		$this->assertSame( $home . '#organization', $org['@id'] );
		$this->assertNotNull( $ws );
		$this->assertSame( $home . '#website', $ws['@id'] );
	}

	public function test_singular_post_graph_is_connected() {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->go_to( get_permalink( $post_id ) );
		$graph = $this->graph();
		$home  = home_url( '/' );

		$webpage = $this->node( $graph, 'WebPage' );
		$article = $this->node( $graph, 'Article' );
		$this->assertNotNull( $this->node( $graph, 'BreadcrumbList' ) );
		$this->assertNotNull( $this->node( $graph, 'Person' ) );
		$this->assertNotNull( $webpage );
		$this->assertNotNull( $article );

		// Connectivity via @id references.
		$this->assertSame( $home . '#organization', $article['publisher']['@id'] );
		$this->assertSame( $home . '#website', $webpage['isPartOf']['@id'] );
		$this->assertSame( $webpage['@id'], $article['mainEntityOfPage']['@id'] );
	}

	public function test_page_has_no_article() {
		$page_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);
		$this->go_to( get_permalink( $page_id ) );
		$graph = $this->graph();

		$this->assertNotNull( $this->node( $graph, 'WebPage' ) );
		$this->assertNull( $this->node( $graph, 'Article' ) );
	}

	public function test_no_fabricated_properties() {
		update_option( Graph::OPT_ORG_LOGO, '' );
		update_option( Graph::OPT_SOCIAL, array() );
		$this->go_to( home_url( '/' ) );
		$org = $this->node( $this->graph(), 'Organization' );

		$this->assertArrayNotHasKey( 'sameAs', $org );
		// No logo configured in the test environment → pruned.
		$this->assertArrayNotHasKey( 'logo', $org );
	}

	public function test_sameas_from_option() {
		update_option( Graph::OPT_SOCIAL, array( 'https://x.com/sampoorna', 'https://www.linkedin.com/company/sampoorna' ) );
		$this->go_to( home_url( '/' ) );
		$org = $this->node( $this->graph(), 'Organization' );

		$this->assertContains( 'https://x.com/sampoorna', $org['sameAs'] );
	}

	public function test_filter_can_add_nodes() {
		add_filter(
			'sampoorna_seo_schema_graph',
			static function ( $nodes ) {
				$nodes[] = array(
					'@type' => 'FAQPage',
					'@id'   => home_url( '/#faq' ),
					'name'  => 'FAQ',
				);
				return $nodes;
			}
		);
		$this->go_to( home_url( '/' ) );
		$this->assertNotNull( $this->node( $this->graph(), 'FAQPage' ) );
	}

	public function test_wp_head_outputs_one_valid_jsonld_block() {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->go_to( get_permalink( $post_id ) );

		ob_start();
		do_action( 'wp_head' );
		$head = ob_get_clean();

		$this->assertSame( 1, substr_count( $head, 'application/ld+json' ) );
		$this->assertSame( 1, preg_match( '#<script type="application/ld\+json">(.+?)</script>#s', $head, $m ) );
		$decoded = json_decode( $m[1], true );
		$this->assertIsArray( $decoded );
		$this->assertArrayHasKey( '@graph', $decoded );
	}
}
