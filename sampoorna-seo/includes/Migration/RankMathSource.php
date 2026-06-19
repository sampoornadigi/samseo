<?php
/**
 * Rank Math migration source (post/page meta).
 *
 * Reads Rank Math's `rank_math_*` post meta and maps it to our logical
 * MetaStore fields. Detection/counting work straight off `postmeta`, so the
 * Rank Math plugin need not be active.
 *
 * @package Sampoorna\SEO
 */

namespace Sampoorna\SEO\Migration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Imports Rank Math per-post data.
 */
class RankMathSource implements Source {

	/**
	 * Rank Math meta key => logical MetaStore field. Robots/focus handled apart.
	 *
	 * @var array<string,string>
	 */
	const MAP = array(
		'rank_math_title'                => 'title',
		'rank_math_description'          => 'desc',
		'rank_math_canonical_url'        => 'canonical',
		'rank_math_facebook_title'       => 'og_title',
		'rank_math_facebook_description' => 'og_desc',
		'rank_math_facebook_image'       => 'og_image',
	);

	/** Keys whose values carry `%tokens%` needing normalization. */
	const TOKENIZED = array( 'title', 'desc', 'og_title', 'og_desc' );

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function slug() {
		return 'rankmath';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function label() {
		return 'Rank Math';
	}

	/**
	 * All Rank Math post-meta keys this source reads.
	 *
	 * @return string[]
	 */
	private function keys() {
		return array_merge(
			array_keys( self::MAP ),
			array( 'rank_math_focus_keyword', 'rank_math_robots' )
		);
	}

	/**
	 * Whether Rank Math data is present.
	 *
	 * @return bool
	 */
	public function is_present() {
		return $this->count() > 0;
	}

	/**
	 * Number of posts carrying Rank Math data.
	 *
	 * @return int
	 */
	public function count() {
		global $wpdb;
		$keys         = $this->keys();
		$placeholders = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
		$sql          = "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key IN ({$placeholders}) AND meta_value <> ''";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Postmeta scan; $placeholders is a built %s list, $keys bound via prepare().
		$count = $wpdb->get_var( $wpdb->prepare( $sql, $keys ) );
		return (int) $count;
	}

	/**
	 * Post IDs carrying Rank Math data, ascending, after a cursor.
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
	 * Read one post's Rank Math meta as normalized logical fields.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string,string>
	 */
	public function read( $post_id ) {
		return $this->read_object( (int) $post_id, false );
	}

	/**
	 * Read Rank Math meta for a post or term (same `rank_math_*` keys).
	 *
	 * @param int  $object_id Post or term ID.
	 * @param bool $is_term   Whether to read term meta.
	 * @return array<string,string>
	 */
	private function read_object( $object_id, $is_term ) {
		$get = static function ( $key ) use ( $object_id, $is_term ) {
			return $is_term ? get_term_meta( $object_id, $key, true ) : get_post_meta( $object_id, $key, true );
		};
		$out = array();

		foreach ( self::MAP as $meta_key => $field ) {
			$value = (string) $get( $meta_key );
			if ( '' === $value ) {
				continue;
			}
			if ( in_array( $field, self::TOKENIZED, true ) ) {
				$value = TokenNormalizer::normalize_rankmath( $value );
			}
			if ( '' !== $value ) {
				$out[ $field ] = $value;
			}
		}

		// Focus keyword is a comma-separated list; the first is primary.
		$focus = (string) $get( 'rank_math_focus_keyword' );
		if ( '' !== $focus ) {
			$parts = explode( ',', $focus );
			$first = trim( (string) $parts[0] );
			if ( '' !== $first ) {
				$out['focus_keyword'] = $first;
			}
		}

		// Robots is a serialized array of directives.
		$robots = $get( 'rank_math_robots' );
		if ( is_array( $robots ) ) {
			if ( in_array( 'noindex', $robots, true ) ) {
				$out['robots_noindex'] = '1';
			}
			if ( in_array( 'nofollow', $robots, true ) ) {
				$out['robots_nofollow'] = '1';
			}
		}

		return $out;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return int
	 */
	public function term_count() {
		global $wpdb;
		$keys         = $this->keys();
		$placeholders = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
		$sql          = "SELECT COUNT(DISTINCT term_id) FROM {$wpdb->termmeta} WHERE meta_key IN ({$placeholders}) AND meta_value <> ''";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Termmeta scan; $placeholders is a built %s list, $keys bound via prepare().
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $keys ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $after_id Return IDs greater than this.
	 * @param int $limit    Maximum IDs to return.
	 * @return int[]
	 */
	public function term_ids( $after_id, $limit ) {
		global $wpdb;
		$keys         = $this->keys();
		$placeholders = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
		$params       = array_merge( $keys, array( (int) $after_id, (int) $limit ) );
		$sql          = "SELECT DISTINCT term_id FROM {$wpdb->termmeta} WHERE meta_key IN ({$placeholders}) AND meta_value <> '' AND term_id > %d ORDER BY term_id ASC LIMIT %d";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Termmeta scan; $placeholders is a built %s list, all values bound via prepare().
		$ids = $wpdb->get_col( $wpdb->prepare( $sql, $params ) );
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $term_id Term ID.
	 * @return array<string,string>
	 */
	public function read_term( $term_id ) {
		return $this->read_object( (int) $term_id, true );
	}
}
