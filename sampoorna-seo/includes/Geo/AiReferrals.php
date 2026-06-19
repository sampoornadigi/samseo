<?php
/**
 * Known AI answer-engine referrers.
 *
 * Maps the hostnames that send referral traffic from AI chat/answer engines
 * (ChatGPT, Perplexity, Gemini, Copilot, Claude, …) so Geo\CrawlerLog can log
 * how much human traffic a client receives *from* AI surfaces — distinct from
 * the bots that crawl the site (Geo\AiBots).
 *
 * @package Sampoorna\SEO
 */

namespace Sampoorna\SEO\Geo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides the AI-referrer source list + matching.
 */
class AiReferrals {

	/**
	 * Sources: key => { label, hosts[] } (host substrings, matched on the referrer host).
	 *
	 * @return array<string,array{label:string,hosts:string[]}>
	 */
	public static function all() {
		return array(
			'chatgpt'    => array(
				'label' => 'ChatGPT',
				'hosts' => array( 'chatgpt.com', 'chat.openai.com' ),
			),
			'perplexity' => array(
				'label' => 'Perplexity',
				'hosts' => array( 'perplexity.ai' ),
			),
			'gemini'     => array(
				'label' => 'Gemini',
				'hosts' => array( 'gemini.google.com', 'bard.google.com' ),
			),
			'copilot'    => array(
				'label' => 'Microsoft Copilot',
				'hosts' => array( 'copilot.microsoft.com' ),
			),
			'claude'     => array(
				'label' => 'Claude',
				'hosts' => array( 'claude.ai' ),
			),
			'you'        => array(
				'label' => 'You.com',
				'hosts' => array( 'you.com' ),
			),
		);
	}

	/**
	 * Match a referrer URL to a source key, or '' when none.
	 *
	 * @param string $referer Referrer URL.
	 * @return string Source key or empty string.
	 */
	public static function match( $referer ) {
		$referer = (string) $referer;
		if ( '' === $referer ) {
			return '';
		}
		$host = strtolower( (string) wp_parse_url( $referer, PHP_URL_HOST ) );
		if ( '' === $host ) {
			return '';
		}
		foreach ( self::all() as $key => $info ) {
			foreach ( $info['hosts'] as $needle ) {
				if ( false !== strpos( $host, $needle ) ) {
					return $key;
				}
			}
		}
		return '';
	}

	/**
	 * Human label for a source key (falls back to the key).
	 *
	 * @param string $key Source key.
	 * @return string
	 */
	public static function label( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ]['label'] : (string) $key;
	}
}
