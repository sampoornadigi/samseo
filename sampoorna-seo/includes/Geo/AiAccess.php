<?php
/**
 * AI-crawler access checker.
 *
 * Determines whether each AI bot (Geo\AiBots) is allowed to crawl the site root
 * per the effective robots.txt. Robots-only: server- or Cloudflare-level blocks
 * are out of scope (a later slice). The robots parser is intentionally minimal
 * — it evaluates the site root ("/") against the most specific matching
 * User-agent group, which is what matters for "is this bot blocked".
 *
 * @package Sampoorna\SEO
 */

namespace Sampoorna\SEO\Geo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Evaluates AI-bot access against the effective robots.txt.
 */
class AiAccess {

	/**
	 * Build the effective robots.txt body (mirrors WordPress core do_robots()).
	 *
	 * @return string
	 */
	public static function effective_robots() {
		$public = (int) get_option( 'blog_public' );
		if ( $public ) {
			$output = "User-agent: *\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\n";
		} else {
			$output = "User-agent: *\nDisallow: /\n";
		}
		/** This filter is documented in wp-includes/functions.php (do_robots). */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Re-applying WordPress core's robots_txt filter to compute the effective body.
		return (string) apply_filters( 'robots_txt', $output, $public );
	}

	/**
	 * Per-bot access report for the current effective robots.txt.
	 *
	 * @return array<int,array{key:string,label:string,token:string,allowed:bool,via:string}>
	 */
	public static function report() {
		$robots = self::effective_robots();
		$out    = array();
		foreach ( AiBots::all() as $key => $info ) {
			$res   = self::evaluate( $robots, $info['token'] );
			$out[] = array(
				'key'     => $key,
				'label'   => $info['label'],
				'token'   => $info['token'],
				'allowed' => $res['allowed'],
				'via'     => $res['via'],
			);
		}
		return $out;
	}

	/**
	 * Evaluate whether a bot may crawl "/" given a robots.txt body.
	 *
	 * @param string $robots    Robots.txt body.
	 * @param string $bot_token The bot's user-agent token.
	 * @return array{allowed:bool,via:string} via = the group token that decided.
	 */
	public static function evaluate( $robots, $bot_token ) {
		$groups = self::parse( $robots );
		$token  = strtolower( (string) $bot_token );

		// Choose the most specific matching group: exact/substring token match,
		// longest wins; else the wildcard group.
		$chosen     = null;
		$chosen_len = -1;
		foreach ( $groups as $ua => $rules ) {
			if ( '*' === $ua ) {
				continue;
			}
			if ( ( false !== strpos( $token, $ua ) || false !== strpos( $ua, $token ) ) && strlen( $ua ) > $chosen_len ) {
				$chosen     = $rules;
				$chosen_len = strlen( $ua );
				$via        = $ua;
			}
		}
		if ( null === $chosen ) {
			if ( isset( $groups['*'] ) ) {
				$chosen = $groups['*'];
				$via    = '*';
			} else {
				return array(
					'allowed' => true,
					'via'     => 'no rules',
				);
			}
		}

		// Decide "/" by longest matching path; tie favours Allow.
		$best_len   = -1;
		$best_allow = true;
		foreach ( $chosen as $rule ) {
			$path = $rule['path'];
			// A rule applies to "/" only when its path is a non-empty prefix of "/"
			// (i.e. "/"). An empty Disallow/Allow path imposes no restriction.
			if ( '' === $path || 0 !== strpos( '/', $path ) ) {
				continue;
			}
			$len = strlen( $path );
			if ( $len > $best_len || ( $len === $best_len && $rule['allow'] ) ) {
				$best_len   = $len;
				$best_allow = $rule['allow'];
			}
		}

		return array(
			'allowed' => $best_allow,
			'via'     => isset( $via ) ? $via : '*',
		);
	}

	/**
	 * Parse robots.txt into groups: user-agent token (lowercase) => rules.
	 *
	 * Consecutive User-agent lines share the rules that follow them.
	 *
	 * @param string $robots Robots.txt body.
	 * @return array<string,array<int,array{allow:bool,path:string}>>
	 */
	private static function parse( $robots ) {
		$groups    = array();
		$current   = array();
		$expecting = false; // True right after a User-agent line (still collecting agents).

		foreach ( preg_split( '/\r\n|\r|\n/', (string) $robots ) as $line ) {
			$line = trim( (string) $line );
			if ( '' === $line || 0 === strpos( $line, '#' ) ) {
				continue;
			}
			$pos = strpos( $line, ':' );
			if ( false === $pos ) {
				continue;
			}
			$field = strtolower( trim( substr( $line, 0, $pos ) ) );
			$value = trim( substr( $line, $pos + 1 ) );

			if ( 'user-agent' === $field ) {
				if ( ! $expecting ) {
					$current = array();
				}
				$ua = strtolower( $value );
				if ( ! isset( $groups[ $ua ] ) ) {
					$groups[ $ua ] = array();
				}
				$current[] = $ua;
				$expecting = true;
			} elseif ( 'disallow' === $field || 'allow' === $field ) {
				$expecting = false;
				$rule      = array(
					'allow' => ( 'allow' === $field ),
					'path'  => $value,
				);
				foreach ( $current as $ua ) {
					$groups[ $ua ][] = $rule;
				}
			} else {
				$expecting = false;
			}
		}

		return $groups;
	}
}
