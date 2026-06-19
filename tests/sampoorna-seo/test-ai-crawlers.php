<?php
/**
 * Tests for AI-crawler access checking + engagement logging.
 *
 * @package Sampoorna\SEO
 */

use Sampoorna\SEO\Geo\AiBots;
use Sampoorna\SEO\Geo\AiAccess;
use Sampoorna\SEO\Core\Database;

class Sampoorna_Seo_Ai_Crawlers_Test extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		Database::create_tables();
	}

	/* ---------- AiBots ---------- */

	public function test_bot_match() {
		$this->assertSame( 'gptbot', AiBots::match( 'Mozilla/5.0 (compatible; GPTBot/1.1; +https://openai.com/gptbot)' ) );
		$this->assertSame( 'claudebot', AiBots::match( 'Mozilla/5.0 (compatible; ClaudeBot/1.0; +claude)' ) );
		$this->assertSame( 'perplexitybot', AiBots::match( 'PerplexityBot/1.0' ) );
		$this->assertSame( '', AiBots::match( 'Mozilla/5.0 (Windows NT 10.0) Chrome/120' ) );
		$this->assertSame( '', AiBots::match( '' ) );
	}

	public function test_bot_label() {
		$this->assertSame( 'GPTBot', AiBots::label( 'gptbot' ) );
		$this->assertSame( 'unknown', AiBots::label( 'unknown' ) );
	}

	/* ---------- AiAccess::evaluate (robots parsing) ---------- */

	public function test_allowed_by_default_wp_robots() {
		$robots = "User-agent: *\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\n";
		$this->assertTrue( AiAccess::evaluate( $robots, 'GPTBot' )['allowed'] );
	}

	public function test_blocked_by_wildcard_disallow_all() {
		$robots = "User-agent: *\nDisallow: /\n";
		$this->assertFalse( AiAccess::evaluate( $robots, 'GPTBot' )['allowed'] );
	}

	public function test_bot_specific_block_overrides_wildcard_allow() {
		$robots = "User-agent: *\nDisallow:\n\nUser-agent: GPTBot\nDisallow: /\n";
		$res    = AiAccess::evaluate( $robots, 'GPTBot' );
		$this->assertFalse( $res['allowed'] );
		$this->assertSame( 'gptbot', $res['via'] );
		// A different bot still follows the permissive wildcard group.
		$this->assertTrue( AiAccess::evaluate( $robots, 'CCBot' )['allowed'] );
	}

	public function test_empty_disallow_means_allow_all() {
		$robots = "User-agent: *\nDisallow:\n";
		$this->assertTrue( AiAccess::evaluate( $robots, 'CCBot' )['allowed'] );
	}

	public function test_report_covers_all_bots() {
		$report = AiAccess::report();
		$this->assertCount( count( AiBots::all() ), $report );
		foreach ( $report as $row ) {
			$this->assertArrayHasKey( 'allowed', $row );
			$this->assertIsBool( $row['allowed'] );
		}
	}

	/* ---------- Engagement log ---------- */

	public function test_record_ai_hit_upserts_and_counts() {
		Database::record_ai_hit( 'gptbot', 'https://example.test/a/' );
		Database::record_ai_hit( 'gptbot', 'https://example.test/b/' );
		Database::record_ai_hit( 'claudebot', 'https://example.test/c/' );

		$hits   = Database::ai_hits();
		$by_bot = array();
		foreach ( $hits as $row ) {
			$by_bot[ $row['bot'] ] = $row;
		}

		$this->assertSame( 2, (int) $by_bot['gptbot']['hits'] );
		$this->assertSame( 'https://example.test/b/', $by_bot['gptbot']['last_url'] );
		$this->assertSame( 1, (int) $by_bot['claudebot']['hits'] );
	}
}
