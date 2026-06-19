<?php
/**
 * All In One SEO (AIOSEO) migration source.
 *
 * Unlike Yoast/Rank Math, AIOSEO v4 stores per-post SEO data in its own custom
 * table `{$wpdb->prefix}aioseo_posts` (one row per post), not in postmeta. The
 * row→field mapping is split into a pure map_row() so it is unit-testable
 * without the table present.
 *
 * @package Sampoorna\SEO
 */

namespace Sampoorna\SEO\Migration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Imports AIOSEO per-post data from its custom table.
 */
class AioseoSource implements Source {

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function slug() {
		return 'aioseo';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function label() {
		return 'All In One SEO';
	}

	/**
	 * The AIOSEO posts table name.
	 *
	 * @return string
	 */
	private function table() {
		global $wpdb;
		return $wpdb->prefix . 'aioseo_posts';
	}

	/**
	 * The AIOSEO terms table name.
	 *
	 * @return string
	 */
	private function terms_table() {
		global $wpdb;
		return $wpdb->prefix . 'aioseo_terms';
	}

	/**
	 * Whether the AIOSEO posts table exists.
	 *
	 * @return bool
	 */
	private function table_exists() {
		return $this->exists( $this->table() );
	}

	/**
	 * Whether a given table exists.
	 *
	 * @param string $table Table name.
	 * @return bool
	 */
	private function exists( $table ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema check for an optional third-party table.
		return (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
	}

	/**
	 * Whether AIOSEO data is present.
	 *
	 * @return bool
	 */
	public function is_present() {
		return $this->table_exists() && $this->count() > 0;
	}

	/**
	 * Number of posts carrying AIOSEO data.
	 *
	 * @return int
	 */
	public function count() {
		if ( ! $this->table_exists() ) {
			return 0;
		}
		global $wpdb;
		$table = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Optional AIOSEO table; name from $wpdb->prefix, no dynamic values.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE post_id > 0" );
	}

	/**
	 * Post IDs carrying AIOSEO data, ascending, after a cursor.
	 *
	 * @param int $after_id Return IDs greater than this.
	 * @param int $limit    Maximum IDs to return.
	 * @return int[]
	 */
	public function target_ids( $after_id, $limit ) {
		if ( ! $this->table_exists() ) {
			return array();
		}
		global $wpdb;
		$table = $this->table();
		$sql   = "SELECT DISTINCT post_id FROM {$table} WHERE post_id > %d ORDER BY post_id ASC LIMIT %d";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Optional AIOSEO table; name from $wpdb->prefix, values bound via prepare().
		$ids = $wpdb->get_col( $wpdb->prepare( $sql, (int) $after_id, (int) $limit ) );
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Read one post's AIOSEO row as normalized logical fields.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string,string>
	 */
	public function read( $post_id ) {
		if ( ! $this->table_exists() ) {
			return array();
		}
		global $wpdb;
		$table = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Optional AIOSEO table; name from $wpdb->prefix, value bound via prepare().
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE post_id = %d", (int) $post_id ), ARRAY_A );
		return is_array( $row ) ? self::map_row( $row ) : array();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return int
	 */
	public function term_count() {
		$table = $this->terms_table();
		if ( ! $this->exists( $table ) ) {
			return 0;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Optional AIOSEO table; name from $wpdb->prefix, no dynamic values.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE term_id > 0" );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $after_id Return IDs greater than this.
	 * @param int $limit    Maximum IDs to return.
	 * @return int[]
	 */
	public function term_ids( $after_id, $limit ) {
		$table = $this->terms_table();
		if ( ! $this->exists( $table ) ) {
			return array();
		}
		global $wpdb;
		$sql = "SELECT DISTINCT term_id FROM {$table} WHERE term_id > %d ORDER BY term_id ASC LIMIT %d";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Optional AIOSEO table; name from $wpdb->prefix, values bound via prepare().
		$ids = $wpdb->get_col( $wpdb->prepare( $sql, (int) $after_id, (int) $limit ) );
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $term_id Term ID.
	 * @return array<string,string>
	 */
	public function read_term( $term_id ) {
		$table = $this->terms_table();
		if ( ! $this->exists( $table ) ) {
			return array();
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Optional AIOSEO table; name from $wpdb->prefix, value bound via prepare().
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE term_id = %d", (int) $term_id ), ARRAY_A );
		return is_array( $row ) ? self::map_row( $row ) : array();
	}

	/**
	 * Map an AIOSEO table row to normalized logical fields (pure).
	 *
	 * @param array<string,mixed> $row Row from {prefix}aioseo_posts.
	 * @return array<string,string>
	 */
	public static function map_row( array $row ) {
		$out = array();

		$text = array(
			'title'          => 'title',
			'description'    => 'desc',
			'og_title'       => 'og_title',
			'og_description' => 'og_desc',
		);
		foreach ( $text as $col => $field ) {
			$value = TokenNormalizer::normalize_aioseo( (string) ( $row[ $col ] ?? '' ) );
			if ( '' !== $value ) {
				$out[ $field ] = $value;
			}
		}

		$canonical = (string) ( $row['canonical_url'] ?? '' );
		if ( '' !== $canonical ) {
			$out['canonical'] = $canonical;
		}

		$og_image = (string) ( $row['og_image_custom_url'] ?? '' );
		if ( '' === $og_image ) {
			$og_image = (string) ( $row['og_image_url'] ?? '' );
		}
		if ( '' !== $og_image ) {
			$out['og_image'] = $og_image;
		}

		$focus = self::focus_from_keyphrases( (string) ( $row['keyphrases'] ?? '' ) );
		if ( '' !== $focus ) {
			$out['focus_keyword'] = $focus;
		}

		// Robots: only meaningful when the post overrides the site default.
		if ( 0 === (int) ( $row['robots_default'] ?? 1 ) ) {
			if ( 1 === (int) ( $row['robots_noindex'] ?? 0 ) ) {
				$out['robots_noindex'] = '1';
			}
			if ( 1 === (int) ( $row['robots_nofollow'] ?? 0 ) ) {
				$out['robots_nofollow'] = '1';
			}
		}

		return $out;
	}

	/**
	 * Extract the focus keyphrase from AIOSEO's keyphrases JSON.
	 *
	 * @param string $json Raw keyphrases JSON.
	 * @return string
	 */
	private static function focus_from_keyphrases( $json ) {
		if ( '' === $json ) {
			return '';
		}
		$data = json_decode( $json, true );
		if ( is_array( $data ) && isset( $data['focus']['keyphrase'] ) ) {
			return trim( (string) $data['focus']['keyphrase'] );
		}
		return '';
	}
}
