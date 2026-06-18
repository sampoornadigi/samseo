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
		// Tidy whitespace left by stripped tokens.
		return trim( (string) preg_replace( '/\s{2,}/', ' ', $value ) );
	}
}
