<?php
/**
 * Tests for the readability + AEO content analysis.
 *
 * @package Sampoorna\SEO
 */

use Sampoorna\SEO\Content\Analyzer;

class Sampoorna_Seo_Analysis_Test extends WP_UnitTestCase {

	private function ids( $result ) {
		return wp_list_pluck( $result['checks'], 'id' );
	}

	public function test_readability_returns_bounded_score_and_checks() {
		$post_id = self::factory()->post->create(
			array(
				'post_content' => "<p>This is a short, clear sentence. It is easy to read.</p>\n<h2>A section</h2>\n<p>Another simple line here.</p>",
			)
		);
		$result = Analyzer::readability( $post_id );

		$this->assertIsInt( $result['score'] );
		$this->assertGreaterThanOrEqual( 0, $result['score'] );
		$this->assertLessThanOrEqual( 100, $result['score'] );
		$ids = $this->ids( $result );
		$this->assertContains( 'flesch', $ids );
		$this->assertContains( 'sentence_length', $ids );
		$this->assertContains( 'subheadings', $ids );
	}

	public function test_empty_content_does_not_fatal() {
		$post_id = self::factory()->post->create( array( 'post_content' => '' ) );
		$r       = Analyzer::readability( $post_id );
		$a       = Analyzer::aeo( $post_id );
		$this->assertIsInt( $r['score'] );
		$this->assertIsInt( $a['score'] );
	}

	public function test_aeo_rewards_question_heading_list_and_answer() {
		$rich = self::factory()->post->create(
			array(
				'post_content' =>
					"<h2>What is local SEO?</h2>\n" .
					"<p>Local SEO helps a business appear in nearby searches and map results, driving foot traffic and calls.</p>\n" .
					"<ul><li>Optimize your Google Business Profile</li><li>Earn local citations</li><li>Collect reviews</li></ul>\n" .
					"<h2>How do I start?</h2>\n<p>Begin by claiming your profile.</p>",
			)
		);
		$thin = self::factory()->post->create(
			array(
				'post_content' => '<p>' . str_repeat( 'Just a long wall of prose with no structure at all and nothing extractable here. ', 20 ) . '</p>',
			)
		);

		$rich_aeo = Analyzer::aeo( $rich );
		$thin_aeo = Analyzer::aeo( $thin );

		$this->assertGreaterThan( $thin_aeo['score'], $rich_aeo['score'] );

		$rich_status = array();
		foreach ( $rich_aeo['checks'] as $c ) {
			$rich_status[ $c['id'] ] = $c['status'];
		}
		$this->assertSame( 'good', $rich_status['question_heading'] );
		$this->assertSame( 'good', $rich_status['lists_or_tables'] );
		$this->assertSame( 'good', $rich_status['faq'] );
	}

	public function test_aeo_flags_missing_structure() {
		$thin = self::factory()->post->create(
			array( 'post_content' => '<p>' . str_repeat( 'plain prose ', 60 ) . '</p>' )
		);
		$status = array();
		foreach ( Analyzer::aeo( $thin )['checks'] as $c ) {
			$status[ $c['id'] ] = $c['status'];
		}
		$this->assertSame( 'bad', $status['question_heading'] );
		$this->assertSame( 'bad', $status['lists_or_tables'] );
	}
}
