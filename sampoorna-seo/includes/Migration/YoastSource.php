<?php
/**
 * Yoast SEO migration source (post/page meta).
 *
 * Reads Yoast's `_yoast_wpseo_*` post meta and maps it to our logical MetaStore
 * fields. Detection and counting work straight off `postmeta`, so the Yoast
 * plugin need not be active to migrate a site that previously used it.
 *
 * @package Sampoorna\SEO
 */

namespace Sampoorna\SEO\Migration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Imports Yoast SEO per-post data.
 */
class YoastSource implements Source {

	/**
	 * Yoast meta key => logical MetaStore field. Robots keys are handled apart.
	 *
	 * @var array<string,string>
	 */
	const MAP = array(
		'_yoast_wpseo_title'                 => 'title',
		'_yoast_wpseo_metadesc'              => 'desc',
		'_yoast_wpseo_canonical'             => 'canonical',
		'_yoast_wpseo_focuskw'               => 'focus_keyword',
		'_yoast_wpseo_opengraph-title'       => 'og_title',
		'_yoast_wpseo_opengraph-description' => 'og_desc',
		'_yoast_wpseo_opengraph-image'       => 'og_image',
	);

	/** Yoast keys whose values carry `%%tokens%%` needing normalization. */
	const TOKENIZED = array( 'title', 'desc', 'og_title', 'og_desc' );

	/**
	 * {@inheritDoc}
	 */
	public function slug() {
		return 'yoast';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label() {
		return 'Yoast SEO';
	}

	/**
	 * All Yoast post-meta keys this source reads.
	 *
	 * @return string[]
	 */
	private function keys() {
		return array_merge(
			array_keys( self::MAP ),
			array( '_yoast_wpseo_meta-robots-noindex', '_yoast_wpseo_meta-robots-nofollow' )
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_present() {
		return $this->count() > 0;
	}

	/**
	 * Number of posts carrying Yoast SEO data.
	 *
	 * @return int
	 */
	public function count() {
		global $wpdb;
		$keys         = $this->keys();
		$placeholders = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
		$sql          = "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key IN ({$placeholders}) AND meta_value <> ''";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Postmeta scan; $placeholders is a built %s list, $keys are bound via prepare().
		$count = $wpdb->get_var( $wpdb->prepare( $sql, $keys ) );
		return (int) $count;
	}

	/**
	 * Post IDs carrying Yoast data, ascending, after a cursor.
	 *
	 * @param int $after_id Return IDs greater than this.
	 * @param int $limit    Maximum IDs to return.
	 * @return int[]
	 */
	public function target_ids( $after_id, $limit ) {
		global $wpdb;
		$keys         = $this->keys();
		$placeholders = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
		$params       = array_merge( $keys, array( (int) $after_id, (int) $limit ) );
		$sql          = "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key IN ({$placeholders}) AND meta_value <> '' AND post_id > %d ORDER BY post_id ASC LIMIT %d";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Postmeta scan; $placeholders is a built %s list, all values bound via prepare().
		$ids = $wpdb->get_col( $wpdb->prepare( $sql, $params ) );
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Read one post's Yoast meta as normalized logical fields.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string,string>
	 */
	public function read( $post_id ) {
		$post_id = (int) $post_id;
		$out     = array();

		foreach ( self::MAP as $meta_key => $field ) {
			$value = (string) get_post_meta( $post_id, $meta_key, true );
			if ( '' === $value ) {
				continue;
			}
			if ( in_array( $field, self::TOKENIZED, true ) ) {
				$value = TokenNormalizer::normalize_yoast( $value );
			}
			if ( '' !== $value ) {
				$out[ $field ] = $value;
			}
		}

		// Yoast robots: '1' = noindex / nofollow; '2' or absent = default.
		if ( '1' === (string) get_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', true ) ) {
			$out['robots_noindex'] = '1';
		}
		if ( '1' === (string) get_post_meta( $post_id, '_yoast_wpseo_meta-robots-nofollow', true ) ) {
			$out['robots_nofollow'] = '1';
		}

		return $out;
	}

	/**
	 * Yoast stores term SEO in the `wpseo_taxonomy_meta` OPTION (not termmeta):
	 * [ taxonomy => [ term_id => { wpseo_title, wpseo_desc, ... } ] ]. This is
	 * the documented §4.9 trap.
	 *
	 * @return array<int,array<string,mixed>> term_id => field map.
	 */
	private function term_store() {
		$opt = get_option( 'wpseo_taxonomy_meta', array() );
		if ( ! is_array( $opt ) ) {
			return array();
		}
		$by_term = array();
		foreach ( $opt as $terms ) {
			if ( ! is_array( $terms ) ) {
				continue;
			}
			foreach ( $terms as $term_id => $fields ) {
				if ( is_array( $fields ) && ! empty( $fields ) ) {
					$by_term[ (int) $term_id ] = $fields;
				}
			}
		}
		return $by_term;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return int
	 */
	public function term_count() {
		return count( $this->term_store() );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $after_id Return IDs greater than this.
	 * @param int $limit    Maximum IDs to return.
	 * @return int[]
	 */
	public function term_ids( $after_id, $limit ) {
		$ids = array_keys( $this->term_store() );
		sort( $ids, SORT_NUMERIC );
		$out = array();
		foreach ( $ids as $id ) {
			if ( $id > (int) $after_id ) {
				$out[] = (int) $id;
			}
			if ( count( $out ) >= (int) $limit ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $term_id Term ID.
	 * @return array<string,string>
	 */
	public function read_term( $term_id ) {
		$store = $this->term_store();
		$row   = isset( $store[ (int) $term_id ] ) ? $store[ (int) $term_id ] : array();
		if ( empty( $row ) ) {
			return array();
		}

		$map       = array(
			'wpseo_title'                 => 'title',
			'wpseo_desc'                  => 'desc',
			'wpseo_canonical'             => 'canonical',
			'wpseo_opengraph-title'       => 'og_title',
			'wpseo_opengraph-description' => 'og_desc',
			'wpseo_opengraph-image'       => 'og_image',
		);
		$tokenized = array( 'title', 'desc', 'og_title', 'og_desc' );

		$out = array();
		foreach ( $map as $src => $field ) {
			$value = isset( $row[ $src ] ) ? (string) $row[ $src ] : '';
			if ( '' === $value ) {
				continue;
			}
			if ( in_array( $field, $tokenized, true ) ) {
				$value = TokenNormalizer::normalize_yoast( $value );
			}
			if ( '' !== $value ) {
				$out[ $field ] = $value;
			}
		}

		// Term noindex is the string 'noindex' (vs 'index'/'default').
		if ( isset( $row['wpseo_noindex'] ) && 'noindex' === (string) $row['wpseo_noindex'] ) {
			$out['robots_noindex'] = '1';
		}

		return $out;
	}
}
