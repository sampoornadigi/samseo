<?php
/**
 * AI-crawler engagement logging.
 *
 * Records when a known AI bot (Geo\AiBots) actually requests a front-end URL —
 * per-bot hit count, last URL, and first/last-seen — so the agency can see
 * which answer engines are crawling a client and how often. A DB write happens
 * only when the request UA matches a bot (rare), so there's no cost on normal
 * traffic.
 *
 * @package Sampoorna\SEO
 */

namespace Sampoorna\SEO\Geo;

use Sampoorna\SEO\Core\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Logs AI-bot front-end requests.
 */
class CrawlerLog {

	/**
	 * Singleton instance.
	 *
	 * @var CrawlerLog|null
	 */
	private static $instance = null;

	/**
	 * Retrieve the singleton instance.
	 *
	 * @return CrawlerLog
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Wire the front-end logging hook.
	 */
	private function __construct() {
		// Late on template_redirect so our priority-0 endpoints (sitemap/llms) have run.
		add_action( 'template_redirect', array( $this, 'maybe_log' ), 99 );
	}

	/**
	 * Log the current request when it comes from a known AI bot.
	 *
	 * @return void
	 */
	public function maybe_log() {
		if ( is_admin() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		$url = $this->current_url();

		$ua  = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		$bot = AiBots::match( $ua );
		if ( '' !== $bot ) {
			Database::record_ai_hit( $bot, $url );
			return; // A bot request is not also a human referral.
		}

		// Human visit referred from an AI answer engine.
		$ref = isset( $_SERVER['HTTP_REFERER'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';
		$src = AiReferrals::match( $ref );
		if ( '' !== $src ) {
			Database::record_ai_referral( $src, $url );
		}
	}

	/**
	 * The current request URL (scheme + host + path), bounded in length.
	 *
	 * @return string
	 */
	private function current_url() {
		$host = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$url  = ( is_ssl() ? 'https://' : 'http://' ) . $host . $uri;
		return substr( (string) esc_url_raw( $url ), 0, 512 );
	}
}
