<?php
/**
 * Tests for AI-referral source matching + logging.
 *
 * @package Sampoorna\SEO
 */

use Sampoorna\SEO\Geo\AiReferrals;
use Sampoorna\SEO\Core\Database;

class Sampoorna_Seo_Ai_Referrals_Test extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		Database::create_tables();
	}

	public function test_match_sources() {
		$this->assertSame( 'chatgpt', AiReferrals::match( 'https://chatgpt.com/c/abc' ) );
		$this->assertSame( 'chatgpt', AiReferrals::match( 'https://chat.openai.com/' ) );
		$this->assertSame( 'perplexity', AiReferrals::match( 'https://www.perplexity.ai/search' ) );
		$this->assertSame( 'gemini', AiReferrals::match( 'https://gemini.google.com/app' ) );
		$this->assertSame( '', AiReferrals::match( 'https://www.google.com/search?q=x' ) );
		$this->assertSame( '', AiReferrals::match( '' ) );
	}

	public function test_label() {
		$this->assertSame( 'Perplexity', AiReferrals::label( 'perplexity' ) );
		$this->assertSame( 'nope', AiReferrals::label( 'nope' ) );
	}

	public function test_record_referral_upserts() {
		Database::record_ai_referral( 'chatgpt', 'https://example.test/landing/' );
		Database::record_ai_referral( 'chatgpt', 'https://example.test/other/' );
		Database::record_ai_referral( 'perplexity', 'https://example.test/p/' );

		$rows   = Database::ai_referrals();
		$by_src = array();
		foreach ( $rows as $r ) {
			$by_src[ $r['source'] ] = $r;
		}
		$this->assertSame( 2, (int) $by_src['chatgpt']['hits'] );
		$this->assertSame( 'https://example.test/other/', $by_src['chatgpt']['last_url'] );
		$this->assertSame( 1, (int) $by_src['perplexity']['hits'] );
	}
}
