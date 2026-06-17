<?php
/**
 * AJAX tests for the editor meta box score-recompute endpoint.
 *
 * @package Sampoorna\SEO
 *
 * @group ajax
 */

use Sampoorna\SEO\Admin\MetaBox;

class Sampoorna_Seo_MetaBox_Ajax_Test extends WP_Ajax_UnitTestCase {

	public function set_up() {
		parent::set_up();
		// The singleton registers its hooks once on construction; WP_UnitTestCase
		// backs up and restores hooks per test, so re-add the handler each time.
		add_action( 'wp_ajax_sampoorna_seo_score', array( MetaBox::instance(), 'ajax_score' ) );
	}

	public function test_score_endpoint_returns_three_score_blocks() {
		$this->_setRole( 'administrator' );
		$post_id = self::factory()->post->create( array( 'post_content' => '' ) );

		$_POST['action']        = 'sampoorna_seo_score';
		$_POST['nonce']         = wp_create_nonce( MetaBox::SCORE_NONCE_ACTION );
		$_POST['post_id']       = $post_id;
		$_POST['title']         = 'Local SEO guide for Hyderabad businesses';
		$_POST['desc']          = 'A concise meta description about local SEO that sits comfortably within the recommended length window for snippets.';
		$_POST['focus_keyword'] = 'local seo';
		$_POST['content']       = '<h2>What is local SEO?</h2><p>Local SEO helps a business appear in nearby searches and map results.</p><ul><li>Profile</li><li>Citations</li></ul><h2>How?</h2><p>Start now.</p>';
		$_REQUEST['nonce']      = $_POST['nonce'];

		try {
			$this->_handleAjax( 'sampoorna_seo_score' );
		} catch ( WPAjaxDieContinueException $e ) {
			unset( $e );
		}

		$res = json_decode( $this->_last_response, true );
		$this->assertTrue( $res['success'] );
		$this->assertCount( 3, $res['data']['scores'] );

		$block = $res['data']['scores'][0];
		$this->assertArrayHasKey( 'label', $block );
		$this->assertArrayHasKey( 'score', $block );
		$this->assertArrayHasKey( 'band', $block );
		$this->assertIsArray( $block['checks'] );
		$this->assertContains( $block['band'], array( 'good', 'ok', 'bad' ) );

		// The AEO block (3rd) should score well given the structured content override.
		$aeo = $res['data']['scores'][2];
		$this->assertGreaterThan( 0, $aeo['score'] );
	}

	public function test_score_endpoint_denies_users_without_edit_cap() {
		// A subscriber cannot edit another author's post.
		$author  = self::factory()->user->create( array( 'role' => 'author' ) );
		$post_id = self::factory()->post->create( array( 'post_author' => $author ) );
		$this->_setRole( 'subscriber' );

		$_POST['action']   = 'sampoorna_seo_score';
		$_POST['nonce']    = wp_create_nonce( MetaBox::SCORE_NONCE_ACTION );
		$_POST['post_id']  = $post_id;
		$_REQUEST['nonce'] = $_POST['nonce'];

		try {
			$this->_handleAjax( 'sampoorna_seo_score' );
		} catch ( WPAjaxDieContinueException $e ) {
			unset( $e );
		}

		$res = json_decode( $this->_last_response, true );
		$this->assertFalse( $res['success'] );
		$this->assertSame( 'Permission denied.', $res['data']['message'] );
	}
}
