<?php
/**
 * Template-token normalizer for migration.
 *
 * Incumbent plugins use their own template-variable syntaxes (Yoast/Rank Math
 * `%%title%%` / `%title%`, AIOSEO `#post_title`). We never copy those raw —
 * we translate the ones our Meta\TemplateEngine understands into its canonical
 * `%token%` form and strip the rest, so migrated templates render correctly.
 *
 * @package Sampoorna\SEO
 */

namespace Sampoorna\SEO\Migration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Translates source template tokens into canonical Sampoorna tokens.
 */
class TokenNormalizer {

	/**
	 * Yoast `%%token%%` (inner name) => canonical Sampoorna token.
	 *
	 * Only tokens Meta\TemplateEngine resolves are mapped; anything else is
	 * stripped by normalize_yoast().
	 *
	 * @var array<string,string>
	 */
	const YOAST_MAP = array(
		'title'            => '%title%',
		'sitename'         => '%sitename%',
		'sitedesc'         => '%tagline%',
		'tagline'          => '%tagline%',
		'sep'              => '%sep%',
		'excerpt'          => '%excerpt%',
		'excerpt_only'     => '%excerpt%',
		'category'         => '%category%',
		'primary_category' => '%category%',
		'page'             => '%page%',
		'currentyear'      => '%currentyear%',
		'searchphrase'     => '%searchphrase%',
	);

	/**
	 * Canonical Sampoorna tokens (the vocabulary Meta\TemplateEngine resolves).
	 * Any `%token%` outside this set is stripped during normalization.
	 *
	 * @var string[]
	 */
	const CANONICAL = array(
		'title',
		'sitename',
		'tagline',
		'sep',
		'excerpt',
		'category',
		'page',
		'currentyear',
		'searchphrase',
		'archive_title',
	);

	/**
	 * Rank Math single-`%token%` names that differ from ours => canonical token.
	 * Tokens that already match (title, sep, sitename, …) pass through.
	 *
	 * @var array<string,string>
	 */
	const RANKMATH_MAP = array(
		'seo_title'   => '%title%',
		'pagetitle'   => '%title%',
		'pagenumber'  => '%page%',
		'sitedesc'    => '%tagline%',
		'term'        => '%category%',
		'category'    => '%category%',
		'currentyear' => '%currentyear%',
	);

	/**
	 * AIOSEO `#token` name => canonical Sampoorna token. Unknown `#tokens` stripped.
	 *
	 * @var array<string,string>
	 */
	const AIOSEO_MAP = array(
		'post_title'     => '%title%',
		'site_title'     => '%sitename%',
		'separator_sa'   => '%sep%',
		'tagline'        => '%tagline%',
		'post_excerpt'   => '%excerpt%',
		'current_year'   => '%currentyear%',
		'search_term'    => '%searchphrase%',
		'taxonomy_title' => '%category%',
		'category_title' => '%category%',
	);

	/**
	 * Normalize a Yoast template string.
	 *
	 * @param string $value Raw Yoast value (may contain `%%token%%`).
	 * @return string
	 */
	public static function normalize_yoast( $value ) {
		$value = (string) $value;
		if ( false === strpos( $value, '%%' ) ) {
			return $value;
		}
		$value = (string) preg_replace_callback(
			'/%%([a-z0-9_-]+)%%/i',
			static function ( $m ) {
				$key = strtolower( $m[1] );
				return isset( self::YOAST_MAP[ $key ] ) ? self::YOAST_MAP[ $key ] : '';
			},
			$value
		);
		return self::tidy( $value );
	}

	/**
	 * Normalize a Rank Math template string (single `%token%` syntax).
	 *
	 * Remaps the tokens that differ from ours, then strips any `%token%` not in
	 * the canonical vocabulary (never copies raw).
	 *
	 * @param string $value Raw Rank Math value.
	 * @return string
	 */
	public static function normalize_rankmath( $value ) {
		$value = (string) $value;
		if ( false === strpos( $value, '%' ) ) {
			return $value;
		}
		// Match simple `%token%` and arg tokens like `%customfield(x)%`.
		$value = (string) preg_replace_callback(
			'/%([a-z0-9_]+(?:\([^)%]*\))?)%/i',
			static function ( $m ) {
				$key = strtolower( $m[1] );
				if ( isset( self::RANKMATH_MAP[ $key ] ) ) {
					return self::RANKMATH_MAP[ $key ];
				}
				return in_array( $key, self::CANONICAL, true ) ? '%' . $key . '%' : '';
			},
			$value
		);
		return self::tidy( $value );
	}

	/**
	 * Normalize an AIOSEO template string (`#token` syntax).
	 *
	 * @param string $value Raw AIOSEO value.
	 * @return string
	 */
	public static function normalize_aioseo( $value ) {
		$value = (string) $value;
		if ( false === strpos( $value, '#' ) ) {
			return $value;
		}
		$value = (string) preg_replace_callback(
			'/#([a-z0-9_]+)/i',
			static function ( $m ) {
				$key = strtolower( $m[1] );
				return isset( self::AIOSEO_MAP[ $key ] ) ? self::AIOSEO_MAP[ $key ] : '';
			},
			$value
		);
		return self::tidy( $value );
	}

	/**
	 * Collapse whitespace left by stripped tokens and trim.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private static function tidy( $value ) {
		return trim( (string) preg_replace( '/\s{2,}/', ' ', $value ) );
	}
}
