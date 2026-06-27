<?php
/**
 * Tests the AEO/GEO custom JSON-LD field: control-plane-deployable schema that
 * folds into the connected @graph.
 *
 * @package Sampoorna\SEO
 */

use Sampoorna\SEO\Meta\MetaStore;
use Sampoorna\SEO\Schema\Custom;

class Sampoorna_Seo_Schema_Custom_Test extends WP_UnitTestCase {

	public function test_schema_jsonld_stores_valid_json_and_rejects_garbage() {
		$post = self::factory()->post->create();

		MetaStore::save( $post, array( 'schema_jsonld' => '[{"@type":"FAQPage","mainEntity":[]}]' ) );
		$this->assertStringContainsString( '"@type":"FAQPage"', MetaStore::get( $post, 'schema_jsonld' ) );

		// Non-JSON → sanitized to empty (the meta is removed, not corrupted).
		MetaStore::save( $post, array( 'schema_jsonld' => 'not json at all' ) );
		$this->assertSame( '', MetaStore::get( $post, 'schema_jsonld' ) );
	}

	public function test_custom_folds_deployed_nodes_into_the_graph_minus_context() {
		$post = self::factory()->post->create();
		MetaStore::save( $post, array(
			'schema_jsonld' => wp_json_encode( array(
				array( '@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array( array( '@type' => 'Question', 'name' => 'Q' ) ) ),
				array( '@context' => 'https://schema.org', '@type' => 'Article', 'headline' => 'H' ),
			) ),
		) );

		$nodes = Custom::instance()->add_nodes( array(), array( 'is_singular' => true, 'post' => get_post( $post ) ) );
		$this->assertCount( 2, $nodes );
		$this->assertSame( 'FAQPage', $nodes[0]['@type'] );
		$this->assertSame( 'Article', $nodes[1]['@type'] );
		$this->assertArrayNotHasKey( '@context', $nodes[0], 'the @graph carries a single @context' );
	}

	public function test_no_nodes_without_a_post_or_meta() {
		$this->assertSame( array( 'x' ), Custom::instance()->add_nodes( array( 'x' ), array( 'post' => null ) ) );
		$post = self::factory()->post->create();
		$this->assertSame( array(), Custom::instance()->add_nodes( array(), array( 'post' => get_post( $post ) ) ) );
	}
}
