<?php
/**
 * Tests for the per-term SEO meta store.
 *
 * @package Sampoorna\SEO
 */

use Sampoorna\SEO\Meta\TermMeta;

class Sampoorna_Seo_Term_Meta_Test extends WP_UnitTestCase {

	public function test_save_get_all_round_trip() {
		$term_id = self::factory()->category->create();

		TermMeta::save(
			$term_id,
			array(
				'title'          => '  Term Title  ',
				'desc'           => "Line\nbreak desc",
				'canonical'      => 'https://example.test/cat/',
				'robots_noindex' => '1',
			)
		);

		$this->assertSame( 'Term Title', TermMeta::get( $term_id, 'title' ) );
		$this->assertSame( '1', TermMeta::get( $term_id, 'robots_noindex' ) );
		$this->assertSame( 'https://example.test/cat/', TermMeta::get( $term_id, 'canonical' ) );

		$all = TermMeta::all( $term_id );
		$this->assertSame( 'Term Title', $all['title'] );
		$this->assertSame( '', $all['og_title'] );
	}

	public function test_empty_value_deletes() {
		$term_id = self::factory()->category->create();
		TermMeta::save( $term_id, array( 'title' => 'X' ) );
		$this->assertSame( 'X', TermMeta::get( $term_id, 'title' ) );

		TermMeta::save( $term_id, array( 'title' => '' ) );
		$this->assertSame( '', TermMeta::get( $term_id, 'title' ) );
		$this->assertSame( '', get_term_meta( $term_id, '_sampoorna_seo_title', true ) );
	}

	public function test_unknown_field_ignored() {
		$term_id = self::factory()->category->create();
		TermMeta::save( $term_id, array( 'not_a_field' => 'nope' ) );
		$this->assertSame( '', TermMeta::get( $term_id, 'not_a_field' ) );
	}
}
