<?php
/**
 * Site health signals for the control plane.
 *
 * Collects raw, deterministic SEO signals (counts, booleans, sampled content
 * averages) that the control plane turns into multi-dimensional health scores.
 * This class never scores — scoring lives on the plane so the rubric can be
 * tuned centrally. No fabrication: a signal with no data source is null.
 *
 * @package Sampoorna\SEO
 */

namespace Sampoorna\SEO\ControlPlane;

use Sampoorna\SEO\Meta\MetaStore;
use Sampoorna\SEO\Content\Analyzer;
use Sampoorna\SEO\Core\Database;
use Sampoorna\SEO\Technical\Robots;
use Sampoorna\SEO\Technical\IndexNow;
use Sampoorna\SEO\Technical\Sitemap;
use Sampoorna\SEO\Schema\Graph;
use Sampoorna\SEO\Schema\LocalBusiness;
use Sampoorna\SEO\Integrations\GSC\OAuth;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Aggregates raw site health signals for the control plane.
 */
class Metrics {

	/** Transient caching the (heavy) sampled content averages. */
	const SAMPLE_TRANSIENT = 'sampoorna_seo_metrics_sample';

	/** Sample size for the content/AEO averages. */
	const SAMPLE_SIZE = 30;

	/** Sample cache lifetime in seconds. */
	const SAMPLE_TTL = HOUR_IN_SECONDS;

	/**
	 * Collect the full signals payload.
	 *
	 * @return array<string,mixed>
	 */
	public static function collect() {
		$sample = self::content_sample();
		$issues = Database::issue_type_counts();

		return array(
			'schema'       => 1,
			'generated_at' => time(),
			'content'      => array(
				'published'       => self::count_published(),
				'missing_title'   => self::count_missing( MetaStore::KEY_TITLE ),
				'missing_desc'    => self::count_missing( MetaStore::KEY_DESC ),
				'missing_focus'   => self::count_missing( MetaStore::KEY_FOCUS_KW ),
				'sample_size'     => $sample['size'],
				'avg_onpage'      => $sample['onpage'],
				'avg_readability' => $sample['readability'],
				'avg_aeo'         => $sample['aeo'],
			),
			'technical'    => array(
				'redirects_active'  => count( Database::active_redirects() ),
				'not_found_new'     => count(
					Database::get_not_found(
						array(
							'status' => 'new',
							'limit'  => 1000,
						)
					)
				),
				'issues'            => array_map( 'intval', $issues ),
				'robots_configured' => '' !== trim( (string) get_option( Robots::OPT_BODY, '' ) ),
				'indexnow_enabled'  => (bool) get_option( IndexNow::OPT_ENABLED, false ),
				'sitemap_cached'    => (int) get_option( Sitemap::OPT_VERSION, 1 ) > 1,
			),
			'authority'    => self::authority(),
			'geo'          => array(
				'org_name_set'   => '' !== trim( (string) get_option( Graph::OPT_ORG_NAME, '' ) ),
				'org_logo_set'   => '' !== trim( (string) get_option( Graph::OPT_ORG_LOGO, '' ) ),
				'social_count'   => count( (array) get_option( Graph::OPT_SOCIAL, array() ) ),
				'local_business' => ! empty( (array) get_option( LocalBusiness::OPT_LOCAL, array() ) ),
				'avg_aeo'        => $sample['aeo'],
			),
			'ux'           => array(
				'available'     => false,
				'mobile_issues' => isset( $issues['mobile'] ) ? (int) $issues['mobile'] : null,
			),
		);
	}

	/**
	 * Authority signals from Google Search Console (null when not connected).
	 *
	 * @return array<string,mixed>
	 */
	private static function authority() {
		$oauth     = OAuth::instance();
		$connected = $oauth->is_connected();
		$property  = $oauth->selected_property();

		$out = array(
			'gsc_connected'     => $connected,
			'property_selected' => '' !== $property,
			'clicks_28d'        => null,
			'impressions_28d'   => null,
			'avg_position'      => null,
		);

		if ( $connected && '' !== $property ) {
			$cur                    = Database::compare_windows( $property, 28 );
			$out['clicks_28d']      = (int) $cur['current']['clicks'];
			$out['impressions_28d'] = (int) $cur['current']['impressions'];
			$out['avg_position']    = (float) $cur['current']['position'];
		}

		return $out;
	}

	/**
	 * Count published posts/pages, optionally filtered by a meta_query.
	 *
	 * @param array<int,array<string,mixed>>|null $meta_query Optional meta_query clauses.
	 * @return int
	 */
	private static function count_published( $meta_query = null ) {
		$args = array(
			'post_type'      => array( 'post', 'page' ),
			'post_status'    => 'publish',
			'has_password'   => false,
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => false,
			'cache_results'  => false,
		);
		if ( null !== $meta_query ) {
			$args['meta_query'] = $meta_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Bounded admin/control-plane metrics aggregation, not a front-end query.
		}
		$query = new \WP_Query( $args );
		return (int) $query->found_posts;
	}

	/**
	 * Count published posts/pages with no value for a given meta key.
	 *
	 * @param string $meta_key Post-meta key.
	 * @return int
	 */
	private static function count_missing( $meta_key ) {
		return self::count_published(
			array(
				array(
					'key'     => $meta_key,
					'compare' => 'NOT EXISTS',
				),
			)
		);
	}

	/**
	 * Sampled on-page/readability/AEO averages over recent published content.
	 *
	 * Cached in a transient because the Analyzer parses each post's HTML.
	 *
	 * @return array{size:int,onpage:int|null,readability:int|null,aeo:int|null}
	 */
	private static function content_sample() {
		$cached = get_transient( self::SAMPLE_TRANSIENT );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$ids = get_posts(
			array(
				'post_type'        => array( 'post', 'page' ),
				'post_status'      => 'publish',
				'numberposts'      => self::SAMPLE_SIZE,
				'orderby'          => 'date',
				'order'            => 'DESC',
				'fields'           => 'ids',
				'suppress_filters' => false,
			)
		);

		$onpage = array();
		$read   = array();
		$aeo    = array();
		foreach ( (array) $ids as $id ) {
			$id       = (int) $id;
			$meta     = MetaStore::all( $id );
			$onpage[] = Analyzer::analyze( $id, $meta )['score'];
			$read[]   = Analyzer::readability( $id )['score'];
			$aeo[]    = Analyzer::aeo( $id )['score'];
		}

		$sample = array(
			'size'        => count( $ids ),
			'onpage'      => self::avg( $onpage ),
			'readability' => self::avg( $read ),
			'aeo'         => self::avg( $aeo ),
		);
		set_transient( self::SAMPLE_TRANSIENT, $sample, self::SAMPLE_TTL );
		return $sample;
	}

	/**
	 * Integer mean of a list of scores, or null when empty.
	 *
	 * @param int[] $values Scores.
	 * @return int|null
	 */
	private static function avg( array $values ) {
		if ( empty( $values ) ) {
			return null;
		}
		return (int) round( array_sum( $values ) / count( $values ) );
	}
}
