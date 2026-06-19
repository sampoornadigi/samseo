<?php
/**
 * Per-term SEO meta store.
 *
 * The term-meta twin of MetaStore: same discrete `_sampoorna_seo_*` keys, same
 * logical fields and sanitization (reused from MetaStore), but stored as term
 * meta. Used to render SEO on category/tag/custom-taxonomy archives and as the
 * target for term migration from Yoast/Rank Math/AIOSEO.
 *
 * @package Sampoorna\SEO
 */

namespace Sampoorna\SEO\Meta;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads/writes the plugin's per-term SEO meta fields.
 */
class TermMeta {

	/**
	 * Read a single SEO field for a term.
	 *
	 * @param int    $term_id Term ID.
	 * @param string $field   Logical field name.
	 * @return string Empty string when unset/unknown.
	 */
	public static function get( $term_id, $field ) {
		$fields = MetaStore::fields();
		if ( ! isset( $fields[ $field ] ) ) {
			return '';
		}
		return (string) get_term_meta( (int) $term_id, $fields[ $field ], true );
	}

	/**
	 * Read all SEO fields for a term.
	 *
	 * @param int $term_id Term ID.
	 * @return array<string,string> Logical field name => value.
	 */
	public static function all( $term_id ) {
		$out = array();
		foreach ( MetaStore::fields() as $field => $key ) {
			$out[ $field ] = (string) get_term_meta( (int) $term_id, $key, true );
		}
		return $out;
	}

	/**
	 * Sanitize and persist a set of SEO fields for a term.
	 *
	 * Empty values delete the meta row to keep the table tidy.
	 *
	 * @param int                 $term_id Term ID.
	 * @param array<string,mixed> $values  Logical field name => raw value.
	 * @return void
	 */
	public static function save( $term_id, array $values ) {
		$term_id = (int) $term_id;
		$fields  = MetaStore::fields();
		foreach ( $values as $field => $raw ) {
			if ( ! isset( $fields[ $field ] ) ) {
				continue;
			}
			$clean = MetaStore::sanitize( $field, $raw );
			if ( '' === $clean ) {
				delete_term_meta( $term_id, $fields[ $field ] );
			} else {
				update_term_meta( $term_id, $fields[ $field ], $clean );
			}
		}
	}
}
