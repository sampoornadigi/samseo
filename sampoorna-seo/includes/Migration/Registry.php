<?php
/**
 * Registry of available migration sources.
 *
 * Adding Rank Math / AIOSEO later is a one-line addition here.
 *
 * @package Sampoorna\SEO
 */

namespace Sampoorna\SEO\Migration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lists and looks up migration sources.
 */
class Registry {

	/**
	 * All known sources.
	 *
	 * @return Source[]
	 */
	public static function sources() {
		return array(
			new YoastSource(),
		);
	}

	/**
	 * Look up a source by slug.
	 *
	 * @param string $slug Source slug.
	 * @return Source|null
	 */
	public static function get( $slug ) {
		foreach ( self::sources() as $source ) {
			if ( $source->slug() === $slug ) {
				return $source;
			}
		}
		return null;
	}

	/**
	 * Sources whose data is present on this site.
	 *
	 * @return Source[]
	 */
	public static function detected() {
		return array_values( array_filter( self::sources(), static fn( $s ) => $s->is_present() ) );
	}
}
