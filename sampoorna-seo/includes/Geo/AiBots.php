<?php
/**
 * Canonical list of AI crawlers / answer-engine bots.
 *
 * Shared by the robots access checker (Geo\AiAccess) and the crawler-engagement
 * logger (Geo\CrawlerLog). Each entry's `token` is the user-agent product token
 * matched case-insensitively against a request UA (for logging) and against
 * robots.txt User-agent groups (for the access check). Some tokens
 * (Google-Extended, Applebot-Extended) are robots.txt training-control tokens
 * that never appear as a live request UA — they still matter for the access
 * check.
 *
 * @package Sampoorna\SEO
 */

namespace Sampoorna\SEO\Geo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides the canonical AI-bot list.
 */
class AiBots {

	/**
	 * All known AI bots: key => { label, token, vendor }.
	 *
	 * @return array<string,array{label:string,token:string,vendor:string}>
	 */
	public static function all() {
		return array(
			'gptbot'            => array(
				'label'  => 'GPTBot',
				'token'  => 'GPTBot',
				'vendor' => 'OpenAI',
			),
			'chatgpt_user'      => array(
				'label'  => 'ChatGPT-User',
				'token'  => 'ChatGPT-User',
				'vendor' => 'OpenAI',
			),
			'oai_searchbot'     => array(
				'label'  => 'OAI-SearchBot',
				'token'  => 'OAI-SearchBot',
				'vendor' => 'OpenAI',
			),
			'claudebot'         => array(
				'label'  => 'ClaudeBot',
				'token'  => 'ClaudeBot',
				'vendor' => 'Anthropic',
			),
			'anthropic_ai'      => array(
				'label'  => 'anthropic-ai',
				'token'  => 'anthropic-ai',
				'vendor' => 'Anthropic',
			),
			'perplexitybot'     => array(
				'label'  => 'PerplexityBot',
				'token'  => 'PerplexityBot',
				'vendor' => 'Perplexity',
			),
			'google_extended'   => array(
				'label'  => 'Google-Extended',
				'token'  => 'Google-Extended',
				'vendor' => 'Google',
			),
			'applebot_extended' => array(
				'label'  => 'Applebot-Extended',
				'token'  => 'Applebot-Extended',
				'vendor' => 'Apple',
			),
			'ccbot'             => array(
				'label'  => 'CCBot',
				'token'  => 'CCBot',
				'vendor' => 'Common Crawl',
			),
			'bytespider'        => array(
				'label'  => 'Bytespider',
				'token'  => 'Bytespider',
				'vendor' => 'ByteDance',
			),
			'amazonbot'         => array(
				'label'  => 'Amazonbot',
				'token'  => 'Amazonbot',
				'vendor' => 'Amazon',
			),
			'meta_external'     => array(
				'label'  => 'Meta-ExternalAgent',
				'token'  => 'Meta-ExternalAgent',
				'vendor' => 'Meta',
			),
		);
	}

	/**
	 * Match a request user-agent string to a bot key, or '' when none.
	 *
	 * @param string $user_agent Request User-Agent header.
	 * @return string Bot key or empty string.
	 */
	public static function match( $user_agent ) {
		$user_agent = (string) $user_agent;
		if ( '' === $user_agent ) {
			return '';
		}
		foreach ( self::all() as $key => $info ) {
			if ( false !== stripos( $user_agent, $info['token'] ) ) {
				return $key;
			}
		}
		return '';
	}

	/**
	 * Human label for a bot key (falls back to the key).
	 *
	 * @param string $key Bot key.
	 * @return string
	 */
	public static function label( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ]['label'] : (string) $key;
	}
}
