<?php
/**
 * Rule-based fix-suggestions engine.
 *
 * Suggestions are derived deterministically from (a) open issues found by the
 * URL Inspection crawler, (b) title/meta length checks against the post's own
 * SEO fields, and (c) low-CTR / high-impression pages from performance data.
 *
 * Suggestions are advisory: the admin reviews and applies them. The plugin
 * never edits content automatically.
 *
 * @package Sampoorna\SEO
 */

namespace Sampoorna\SEO\Integrations\GSC;

use Sampoorna\SEO\Core\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rule-based fix-suggestions engine.
 */
class Suggestions {

	const TITLE_MIN = 30;
	const TITLE_MAX = 60;
	const DESC_MIN  = 70;
	const DESC_MAX  = 160;

	const OPT_LAST_RUN = 'sampoorna_seo_suggestions_last_run';

	/**
	 * Singleton instance.
	 *
	 * @var Suggestions|null
	 */
	private static $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return Suggestions
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register admin-post handlers.
	 */
	private function __construct() {
		add_action( 'admin_post_sampoorna_seo_generate_suggestions', array( $this, 'handle_generate' ) );
		add_action( 'admin_post_sampoorna_seo_suggestion_bulk', array( $this, 'handle_bulk' ) );
	}

	/* ---------- Actions ---------- */

	/**
	 * Handle the "generate suggestions" admin-post request.
	 *
	 * @return void
	 */
	public function handle_generate() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'sampoorna_seo_generate_suggestions' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'sampoorna-seo' ) );
		}
		$n = $this->generate();
		wp_safe_redirect( admin_url( 'admin.php?page=sampoorna-seo-suggestions&sampoorna_seo_notice=suggestions_generated&n=' . (int) $n ) );
		exit;
	}

	/**
	 * Handle bulk apply/dismiss/reset actions on suggestions.
	 *
	 * @return void
	 */
	public function handle_bulk() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'sampoorna_seo_suggestion_bulk' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'sampoorna-seo' ) );
		}
		$ids    = isset( $_POST['sugg'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['sugg'] ) ) : array();
		$action = isset( $_POST['bulk_action'] ) ? sanitize_key( $_POST['bulk_action'] ) : '';
		if ( $ids && in_array( $action, array( 'apply', 'dismiss', 'reset' ), true ) ) {
			$map = array(
				'apply'   => 'applied',
				'dismiss' => 'dismissed',
				'reset'   => 'new',
			);
			Database::set_suggestion_status( $ids, $map[ $action ] );
		}
		$status = isset( $_POST['cur_status'] ) ? sanitize_key( $_POST['cur_status'] ) : 'new';
		wp_safe_redirect( admin_url( 'admin.php?page=sampoorna-seo-suggestions&status=' . $status . '&sampoorna_seo_notice=suggestions_updated' ) );
		exit;
	}

	/* ---------- Generation ---------- */

	/**
	 * Build suggestions from issues, content, and performance.
	 *
	 * @return int Number of suggestions written/refreshed.
	 */
	public function generate() {
		$property = OAuth::instance()->selected_property();
		$count    = 0;

		$count += $this->from_issues();
		$count += $this->from_content();
		if ( $property ) {
			$count += $this->from_low_ctr( $property );
		}

		update_option( self::OPT_LAST_RUN, current_time( 'mysql' ), false );
		return $count;
	}

	/**
	 * Turn open inspection issues into actionable suggestions.
	 *
	 * @return int
	 */
	private function from_issues() {
		$issues = Database::get_issues(
			array(
				'status' => 'open',
				'limit'  => 2000,
			)
		);
		$n      = 0;

		foreach ( $issues as $iss ) {
			$details = json_decode( (string) $iss['details_json'], true );
			$details = is_array( $details ) ? $details : array();
			$url     = $iss['url'];
			$post_id = url_to_postid( $url );

			switch ( $iss['type'] ) {
				case 'canonical':
					$this->save(
						array(
							'post_id'         => $post_id,
							'url'             => $url,
							'type'            => 'canonical',
							'priority'        => 'high',
							'current_value'   => $details['user_canonical'] ?? '',
							'suggested_value' => $details['google_canonical'] ?? '',
							'recommendation'  => __( 'Google indexed a different canonical than you declared. Align your canonical tag, or confirm the Google-chosen URL is correct.', 'sampoorna-seo' ),
						)
					);
					++$n;
					break;

				case 'indexing':
					$this->save(
						array(
							'post_id'        => $post_id,
							'url'            => $url,
							'type'           => 'indexing',
							'priority'       => 'high',
							'current_value'  => $details['coverage_state'] ?? '',
							'recommendation' => __( 'Page is not indexed. Check robots/noindex, add internal links, ensure it is in the sitemap, then request indexing in Search Console.', 'sampoorna-seo' ),
						)
					);
					++$n;
					break;

				case 'mobile':
					$this->save(
						array(
							'post_id'        => $post_id,
							'url'            => $url,
							'type'           => 'mobile',
							'priority'       => 'medium',
							'current_value'  => implode( '; ', (array) ( $details['issues'] ?? array() ) ),
							'recommendation' => __( 'Fix the reported mobile usability problems (tap targets, viewport, font size).', 'sampoorna-seo' ),
						)
					);
					++$n;
					break;

				case 'schema':
					$this->save(
						array(
							'post_id'        => $post_id,
							'url'            => $url,
							'type'           => 'schema',
							'priority'       => 'medium',
							'current_value'  => implode( '; ', array_slice( (array) ( $details['issues'] ?? array() ), 0, 10 ) ),
							'recommendation' => __( 'Correct the structured-data errors so the page is eligible for rich results.', 'sampoorna-seo' ),
						)
					);
					++$n;
					break;
			}
		}
		return $n;
	}

	/**
	 * Check title and meta-description length on published content.
	 *
	 * @return int
	 */
	private function from_content() {
		$ids = get_posts(
			array(
				'post_type'      => array( 'post', 'page' ),
				'post_status'    => 'publish',
				// phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- Intentional bounded batch ceiling for a one-shot admin audit pass; fetching IDs only.
				'posts_per_page' => 2000,
				'fields'         => 'ids',
			)
		);
		$n = 0;

		foreach ( $ids as $pid ) {
			$url   = get_permalink( $pid );
			$title = $this->seo_title( $pid );
			$desc  = $this->seo_description( $pid );

			$tlen = mb_strlen( $title );
			if ( '' === $title ) {
				$this->save(
					array(
						'post_id'         => $pid,
						'url'             => $url,
						'type'            => 'title',
						'priority'        => 'high',
						'current_value'   => '',
						'suggested_value' => $this->truncate( get_the_title( $pid ), self::TITLE_MAX ),
						'recommendation'  => __( 'No SEO title set. Add a descriptive title of 30–60 characters.', 'sampoorna-seo' ),
					)
				);
				++$n;
			} elseif ( $tlen > self::TITLE_MAX ) {
				$this->save(
					array(
						'post_id'         => $pid,
						'url'             => $url,
						'type'            => 'title',
						'priority'        => 'medium',
						'current_value'   => $title,
						'suggested_value' => $this->truncate( $title, self::TITLE_MAX ),
						/* translators: 1: current title length in characters, 2: recommended maximum length. */
						'recommendation'  => sprintf( __( 'Title is %1$d chars; trim to about %2$d so it is not truncated in search results.', 'sampoorna-seo' ), $tlen, self::TITLE_MAX ),
					)
				);
				++$n;
			} elseif ( $tlen < self::TITLE_MIN ) {
				$this->save(
					array(
						'post_id'        => $pid,
						'url'            => $url,
						'type'           => 'title',
						'priority'       => 'low',
						'current_value'  => $title,
						/* translators: 1: current title length in characters, 2: recommended minimum length, 3: recommended maximum length. */
						'recommendation' => sprintf( __( 'Title is only %1$d chars; expand toward %2$d–%3$d to use the available space.', 'sampoorna-seo' ), $tlen, self::TITLE_MIN, self::TITLE_MAX ),
					)
				);
				++$n;
			}

			$dlen = mb_strlen( $desc );
			if ( '' === $desc ) {
				$this->save(
					array(
						'post_id'         => $pid,
						'url'             => $url,
						'type'            => 'meta',
						'priority'        => 'medium',
						'current_value'   => '',
						'suggested_value' => $this->make_description( $pid ),
						'recommendation'  => __( 'No meta description. Add a 70–160 character summary including the main keyword.', 'sampoorna-seo' ),
					)
				);
				++$n;
			} elseif ( $dlen > self::DESC_MAX ) {
				$this->save(
					array(
						'post_id'         => $pid,
						'url'             => $url,
						'type'            => 'meta',
						'priority'        => 'low',
						'current_value'   => $desc,
						'suggested_value' => $this->truncate( $desc, self::DESC_MAX ),
						/* translators: 1: current meta description length in characters, 2: recommended maximum length. */
						'recommendation'  => sprintf( __( 'Meta description is %1$d chars; trim to about %2$d.', 'sampoorna-seo' ), $dlen, self::DESC_MAX ),
					)
				);
				++$n;
			} elseif ( $dlen < self::DESC_MIN ) {
				$this->save(
					array(
						'post_id'        => $pid,
						'url'            => $url,
						'type'           => 'meta',
						'priority'       => 'low',
						'current_value'  => $desc,
						/* translators: 1: current meta description length in characters, 2: recommended minimum length, 3: recommended maximum length. */
						'recommendation' => sprintf( __( 'Meta description is only %1$d chars; expand toward %2$d–%3$d.', 'sampoorna-seo' ), $dlen, self::DESC_MIN, self::DESC_MAX ),
					)
				);
				++$n;
			}
		}
		return $n;
	}

	/**
	 * Flag low-CTR pages and attach the page's top query (from the API).
	 *
	 * @param string $property Property URL.
	 * @return int
	 */
	private function from_low_ctr( $property ) {
		$pages = Database::low_ctr_pages( $property, 28, 100, 0.01, 20.0, 20 );
		$end   = gmdate( 'Y-m-d', strtotime( '-2 days' ) );
		$start = gmdate( 'Y-m-d', strtotime( '-30 days', strtotime( $end ) ) );
		$n     = 0;

		foreach ( $pages as $p ) {
			$url     = $p['page_url'];
			$queries = Api::top_queries_for_page( $property, $url, $start, $end, 3 );
			$top     = '';
			if ( ! is_wp_error( $queries ) && ! empty( $queries ) ) {
				$labels = array();
				foreach ( $queries as $q ) {
					$labels[] = $q['query'];
				}
				$top = implode( ', ', array_filter( $labels ) );
			}

			$ctr = round( (float) $p['ctr'] * 100, 2 );
			$pos = round( (float) $p['position'], 1 );

			$this->save(
				array(
					'post_id'         => url_to_postid( $url ),
					'url'             => $url,
					'type'            => 'ctr',
					'priority'        => 'medium',
					/* translators: 1: click-through rate percentage, 2: average position, 3: number of impressions. */
					'current_value'   => sprintf( __( 'CTR %1$s%% at avg position %2$s on %3$s impressions', 'sampoorna-seo' ), $ctr, $pos, number_format_i18n( (int) $p['impressions'] ) ),
					'suggested_value' => $top,
					'recommendation'  => '' !== $top
						/* translators: %s: comma-separated list of top search queries. */
						? sprintf( __( 'High impressions but low CTR. Rewrite the title/meta to better match top queries: %s.', 'sampoorna-seo' ), $top )
						: __( 'High impressions but low CTR. Make the title/meta more compelling and aligned with search intent.', 'sampoorna-seo' ),
				)
			);
			++$n;
		}
		return $n;
	}

	/* ---------- Helpers ---------- */

	/**
	 * Persist a single suggestion via the DB layer.
	 *
	 * @param array $s Suggestion fields.
	 * @return void
	 */
	private function save( array $s ) {
		Database::upsert_suggestion( $s );
	}

	/**
	 * Resolve the SEO title from common SEO plugins, falling back to the post title.
	 *
	 * @param int $pid Post ID.
	 * @return string
	 */
	private function seo_title( $pid ) {
		$candidates = array(
			get_post_meta( $pid, '_yoast_wpseo_title', true ),
			get_post_meta( $pid, 'rank_math_title', true ),
			get_post_meta( $pid, '_aioseo_title', true ),
		);
		foreach ( $candidates as $val ) {
			$val = is_string( $val ) ? trim( $val ) : '';
			// Skip template strings like %%title%% — they can't be length-checked here.
			if ( '' !== $val && false === strpos( $val, '%%' ) && false === strpos( $val, '#' ) ) {
				return $val;
			}
		}
		return (string) get_the_title( $pid );
	}

	/**
	 * Resolve the meta description from common SEO plugins.
	 *
	 * @param int $pid Post ID.
	 * @return string Empty string when none is set.
	 */
	private function seo_description( $pid ) {
		$candidates = array(
			get_post_meta( $pid, '_yoast_wpseo_metadesc', true ),
			get_post_meta( $pid, 'rank_math_description', true ),
			get_post_meta( $pid, '_aioseo_description', true ),
		);
		foreach ( $candidates as $val ) {
			$val = is_string( $val ) ? trim( $val ) : '';
			if ( '' !== $val && false === strpos( $val, '%%' ) ) {
				return $val;
			}
		}
		// Fall back to a manual excerpt only (don't invent one here).
		$excerpt = get_post_field( 'post_excerpt', $pid );
		return is_string( $excerpt ) ? trim( $excerpt ) : '';
	}

	/**
	 * Build a candidate meta description from the post content.
	 *
	 * @param int $pid Post ID.
	 * @return string
	 */
	private function make_description( $pid ) {
		$content = get_post_field( 'post_content', $pid );
		$text    = wp_strip_all_tags( strip_shortcodes( (string) $content ) );
		$text    = preg_replace( '/\s+/', ' ', $text );
		$text    = trim( (string) $text );
		return $this->truncate( $text, self::DESC_MAX );
	}

	/**
	 * Truncate to a max length on a word boundary, adding an ellipsis.
	 *
	 * @param string $text Text.
	 * @param int    $max  Max characters.
	 * @return string
	 */
	private function truncate( $text, $max ) {
		$text = trim( (string) $text );
		if ( mb_strlen( $text ) <= $max ) {
			return $text;
		}
		$cut = mb_substr( $text, 0, $max - 1 );
		$sp  = mb_strrpos( $cut, ' ' );
		if ( $sp && $sp > (int) ( $max * 0.6 ) ) {
			$cut = mb_substr( $cut, 0, $sp );
		}
		return rtrim( $cut ) . '…';
	}

	/**
	 * Get the timestamp of the last suggestions run.
	 *
	 * @return string MySQL datetime, or empty string if never run.
	 */
	public static function last_run() {
		return (string) get_option( self::OPT_LAST_RUN, '' );
	}
}
