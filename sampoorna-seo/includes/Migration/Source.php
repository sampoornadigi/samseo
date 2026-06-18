<?php
/**
 * A migration source: an incumbent SEO plugin we can import data from.
 *
 * Implementations are read-only adapters — they detect the source plugin's
 * data, count it, and translate a single post's SEO meta into our logical
 * MetaStore fields (token-normalized). They never write or delete anything;
 * the Migrator performs all writes into our own meta, leaving the source
 * untouched (so rollback = deactivate Sampoorna SEO).
 *
 * @package Sampoorna\SEO
 */

namespace Sampoorna\SEO\Migration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read-only adapter over an incumbent SEO plugin's per-post data.
 */
interface Source {

	/**
	 * Stable machine slug, e.g. 'yoast'.
	 *
	 * @return string
	 */
	public function slug();

	/**
	 * Human label, e.g. 'Yoast SEO'.
	 *
	 * @return string
	 */
	public function label();

	/**
	 * Whether this source's data is present on the site.
	 *
	 * @return bool
	 */
	public function is_present();

	/**
	 * Number of posts/pages carrying this source's SEO data.
	 *
	 * @return int
	 */
	public function count();

	/**
	 * Post IDs carrying source data, ascending, after a cursor.
	 *
	 * @param int $after_id Return IDs greater than this (0 = from the start).
	 * @param int $limit    Maximum IDs to return.
	 * @return int[]
	 */
	public function target_ids( $after_id, $limit );

	/**
	 * Read one post's source SEO meta as logical MetaStore fields.
	 *
	 * Returns only the fields the source actually has, token-normalized and
	 * ready for MetaStore::save. Keys are a subset of MetaStore::fields().
	 *
	 * @param int $post_id Post ID.
	 * @return array<string,string>
	 */
	public function read( $post_id );
}
