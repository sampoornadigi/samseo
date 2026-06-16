<?php
/**
 * URL Inspection crawler.
 *
 * Seeds a queue from published content + top performing pages, then drains it
 * on a throttled 15-minute cron, staying under the URL Inspection API limits
 * (2,000/day and 600/min per property). Each inspection is stored and turned
 * into issue rows.
 *
 * @package Sampoorna\SEO
 */

namespace Sampoorna\SEO\Integrations\GSC;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Sampoorna\SEO\Core\Database;

/**
 * Crawls published content and top performing pages through the URL Inspection API.
 */
class Inspector {

	const OPT_DAILY_COUNT = 'sampoorna_seo_inspect_count'; // Daily counter: ['date' => Y-m-d, 'n' => int].
	const OPT_BACKOFF     = 'sampoorna_seo_inspect_backoff'; // Timestamp until which to pause.
	const DAILY_CAP       = 1900; // Safety margin under Google's 2,000/day.
	const REINSPECT_DAYS  = 21;

	/**
	 * Singleton instance.
	 *
	 * @var Inspector|null
	 */
	private static $instance = null;

	/**
	 * Retrieve the singleton instance.
	 *
	 * @return Inspector
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Wire up cron, admin-post handlers, and the save_post hook.
	 */
	private function __construct() {
		add_action( SAMPOORNA_SEO_INSPECT_HOOK, array( $this, 'tick' ) );
		add_action( 'admin_post_sampoorna_seo_seed_queue', array( $this, 'handle_seed' ) );
		add_action( 'admin_post_sampoorna_seo_inspect_now', array( $this, 'handle_inspect_now' ) );
		// Re-queue a post when it's saved/updated.
		add_action( 'save_post', array( $this, 'on_save_post' ), 10, 3 );
	}

	/* ---------- Queue seeding ---------- */

	/**
	 * Admin-post handler that seeds the queue, then redirects back to the issues screen.
	 *
	 * @return void
	 */
	public function handle_seed() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'sampoorna_seo_seed_queue' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'sampoorna-seo' ) );
		}
		$count = $this->seed_queue();
		wp_safe_redirect( admin_url( 'admin.php?page=sampoorna-seo-issues&sampoorna_seo_notice=seeded&n=' . $count ) );
		exit;
	}

	/**
	 * Populate the queue with published post/page URLs and top performing pages.
	 *
	 * @return int Number of URLs queued or refreshed.
	 */
	public function seed_queue() {
		$urls = array();

		// Published posts and pages.
		$post_ids = get_posts(
			array(
				'post_type'      => array( 'post', 'page' ),
				'post_status'    => 'publish',
				// phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- Seeding the inspection queue intentionally enumerates all published content up to a bounded ceiling.
				'posts_per_page' => 5000,
				'fields'         => 'ids',
			)
		);
		foreach ( $post_ids as $pid ) {
			$urls[ get_permalink( $pid ) ] = (int) $pid;
		}

		// Top performing pages from synced data (may include URLs not in WP).
		$property = OAuth::instance()->selected_property();
		if ( $property ) {
			$top = Database::top_rows( $property, 'page', 90, 500 );
			foreach ( $top as $row ) {
				if ( ! empty( $row['label'] ) && ! isset( $urls[ $row['label'] ] ) ) {
					$urls[ $row['label'] ] = 0;
				}
			}
		}

		$n = 0;
		foreach ( $urls as $url => $pid ) {
			if ( $this->enqueue( $url, $pid, 5 ) ) {
				++$n;
			}
		}
		return $n;
	}

	/**
	 * Insert or refresh a queue entry.
	 *
	 * @param string $url      Absolute URL.
	 * @param int    $post_id  Associated post (0 if none).
	 * @param int    $priority Lower = sooner.
	 * @param bool   $force    Force due now.
	 * @return bool
	 */
	public function enqueue( $url, $post_id = 0, $priority = 5, $force = false ) {
		global $wpdb;
		$url = esc_url_raw( $url );
		if ( '' === $url ) {
			return false;
		}
		$hash  = md5( $url );
		$now   = current_time( 'mysql' );
		$next  = $force ? $now : $now;
		$table = Database::queue_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table requires a direct query.
		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix is safe; values are prepared.
				"INSERT INTO {$table} (url,url_hash,post_id,priority,status,attempts,next_due)
				 VALUES (%s,%s,%d,%d,'pending',0,%s)
				 ON DUPLICATE KEY UPDATE post_id=VALUES(post_id), priority=LEAST(priority,VALUES(priority)),
				 status='pending', next_due=IF(%d=1, VALUES(next_due), next_due)",
				$url,
				$hash,
				(int) $post_id,
				(int) $priority,
				$next,
				$force ? 1 : 0
			)
		);
		return true;
	}

	/**
	 * Re-queue a post when it is saved or updated.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object (unused; required by the save_post signature).
	 * @param bool     $update  Whether this is an update (unused; required by the save_post signature).
	 * @return void
	 */
	public function on_save_post( $post_id, $post, $update ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $post and $update are required by the save_post hook signature.
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( 'publish' !== get_post_status( $post_id ) ) {
			return;
		}
		if ( ! in_array( get_post_type( $post_id ), array( 'post', 'page' ), true ) ) {
			return;
		}
		// Higher priority + force due so updated content is re-checked soon.
		$this->enqueue( get_permalink( $post_id ), $post_id, 1, true );
	}

	/* ---------- Cron tick ---------- */

	/**
	 * Manual "Inspect now" — process a single batch immediately.
	 */
	public function handle_inspect_now() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'sampoorna_seo_inspect_now' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'sampoorna-seo' ) );
		}
		$this->tick();
		wp_safe_redirect( admin_url( 'admin.php?page=sampoorna-seo-issues&sampoorna_seo_notice=inspected' ) );
		exit;
	}

	/**
	 * Process one batch of queued URLs, respecting rate limits.
	 */
	public function tick() {
		$oauth = OAuth::instance();
		if ( ! $oauth->is_connected() ) {
			return;
		}
		$property = $oauth->selected_property();
		if ( '' === $property ) {
			return;
		}

		// Honor an active backoff window (set on 429).
		$backoff = (int) get_option( self::OPT_BACKOFF, 0 );
		if ( $backoff > time() ) {
			return;
		}

		$remaining = $this->daily_remaining();
		if ( $remaining <= 0 ) {
			return;
		}

		$batch = (int) get_option( 'sampoorna_seo_inspect_batch', 20 );
		$batch = max( 1, min( $batch, $remaining, 100 ) ); // also under 600/min.

		// Re-queue stale inspected URLs first (cadence), then take due pending.
		$this->requeue_stale();
		$urls = $this->claim_batch( $batch );

		foreach ( $urls as $row ) {
			$result = Api::inspect_url( $property, $row['url'] );

			if ( is_wp_error( $result ) ) {
				$status = (int) ( $result->get_error_data()['status'] ?? 0 );
				if ( 429 === $status ) {
					// Quota hit: back off until the next day's window.
					update_option( self::OPT_BACKOFF, strtotime( 'tomorrow' ), false );
					$this->release( $row['id'] );
					break;
				}
				$this->mark_failed( $row['id'] );
				continue;
			}

			$this->store_inspection( $property, $row['url'], $result );
			$this->derive_issues( $row['url'], $row['post_id'], $result );
			$this->mark_done( $row['id'] );
			$this->bump_daily_count();
		}
	}

	/* ---------- Queue mechanics ---------- */

	/**
	 * Claim a batch of due pending URLs ordered by priority.
	 *
	 * @param int $limit Maximum rows to return.
	 * @return array<int,array<string,mixed>> Matching rows as associative arrays.
	 */
	private function claim_batch( $limit ) {
		global $wpdb;
		$table = Database::queue_table();
		$now   = current_time( 'mysql' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table requires a direct query.
		return $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix is safe; values are prepared.
				"SELECT id,url,post_id FROM {$table}
				 WHERE status='pending' AND next_due<=%s
				 ORDER BY priority ASC, next_due ASC LIMIT %d",
				$now,
				$limit
			),
			ARRAY_A
		);
	}

	/**
	 * Re-queue inspected URLs whose last inspection is older than the re-inspect cadence.
	 *
	 * @return void
	 */
	private function requeue_stale() {
		global $wpdb;
		$table = Database::queue_table();
		$cut   = gmdate( 'Y-m-d H:i:s', strtotime( '-' . self::REINSPECT_DAYS . ' days' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table requires a direct query.
		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix is safe; values are prepared.
				"UPDATE {$table} SET status='pending', next_due=%s
				 WHERE status='done' AND last_inspected IS NOT NULL AND last_inspected < %s",
				current_time( 'mysql' ),
				$cut
			)
		);
	}

	/**
	 * Mark a queue row as successfully inspected.
	 *
	 * @param int $id Queue row ID.
	 * @return void
	 */
	private function mark_done( $id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			Database::queue_table(),
			array(
				'status'         => 'done',
				'last_inspected' => current_time( 'mysql' ),
			),
			array( 'id' => (int) $id )
		);
	}

	/**
	 * Increment the attempt counter for a row, flagging it as errored after 3 tries.
	 *
	 * @param int $id Queue row ID.
	 * @return void
	 */
	private function mark_failed( $id ) {
		global $wpdb;
		$table = Database::queue_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix is safe; values are prepared.
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET attempts=attempts+1, status=IF(attempts>=3,'error','pending') WHERE id=%d", (int) $id ) );
	}

	/**
	 * Return a claimed row to the pending state (e.g. after a backoff).
	 *
	 * @param int $id Queue row ID.
	 * @return void
	 */
	private function release( $id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update( Database::queue_table(), array( 'status' => 'pending' ), array( 'id' => (int) $id ) );
	}

	/* ---------- Rate limiting ---------- */

	/**
	 * Calculate how many inspections remain under the daily cap.
	 *
	 * @return int Remaining inspections allowed today.
	 */
	private function daily_remaining() {
		$rec   = get_option( self::OPT_DAILY_COUNT, array() );
		$today = gmdate( 'Y-m-d' );
		if ( ! is_array( $rec ) || ( $rec['date'] ?? '' ) !== $today ) {
			return self::DAILY_CAP;
		}
		return max( 0, self::DAILY_CAP - (int) $rec['n'] );
	}

	/**
	 * Increment today's inspection counter, resetting it on a new day.
	 *
	 * @return void
	 */
	private function bump_daily_count() {
		$today = gmdate( 'Y-m-d' );
		$rec   = get_option( self::OPT_DAILY_COUNT, array() );
		if ( ! is_array( $rec ) || ( $rec['date'] ?? '' ) !== $today ) {
			$rec = array(
				'date' => $today,
				'n'    => 0,
			);
		}
		++$rec['n'];
		update_option( self::OPT_DAILY_COUNT, $rec, false );
	}

	/* ---------- Storage ---------- */

	/**
	 * Persist a single URL inspection result, upserting on the URL key.
	 *
	 * @param string              $property Selected property URL.
	 * @param string              $url      Inspected URL.
	 * @param array<string,mixed> $r        Normalized inspection result.
	 * @return void
	 */
	private function store_inspection( $property, $url, array $r ) {
		global $wpdb;
		$crawl = ! empty( $r['last_crawl_time'] ) ? gmdate( 'Y-m-d H:i:s', strtotime( $r['last_crawl_time'] ) ) : null;
		$table = Database::inspections_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table requires a direct query.
		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix is safe; values are prepared.
				"INSERT INTO {$table}
				 (property_url,url,url_hash,verdict,coverage_state,robots_state,indexing_state,page_fetch_state,google_canonical,user_canonical,mobile_verdict,rich_verdict,last_crawl_time,raw_json,last_inspected)
				 VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)
				 ON DUPLICATE KEY UPDATE verdict=VALUES(verdict),coverage_state=VALUES(coverage_state),robots_state=VALUES(robots_state),
				 indexing_state=VALUES(indexing_state),page_fetch_state=VALUES(page_fetch_state),google_canonical=VALUES(google_canonical),
				 user_canonical=VALUES(user_canonical),mobile_verdict=VALUES(mobile_verdict),rich_verdict=VALUES(rich_verdict),
				 last_crawl_time=VALUES(last_crawl_time),raw_json=VALUES(raw_json),last_inspected=VALUES(last_inspected)",
				$property,
				$url,
				md5( $url ),
				$r['verdict'],
				$r['coverage_state'],
				$r['robots_state'],
				$r['indexing_state'],
				$r['page_fetch_state'],
				$r['google_canonical'],
				$r['user_canonical'],
				$r['mobile_verdict'],
				$r['rich_verdict'],
				$crawl,
				wp_json_encode( $r['raw'] ),
				current_time( 'mysql' )
			)
		);
	}

	/* ---------- Issue derivation ---------- */

	/**
	 * Translate an inspection result into issue rows and reconcile them.
	 *
	 * @param string              $url     Inspected URL.
	 * @param int                 $post_id Associated post ID (0 if none).
	 * @param array<string,mixed> $r       Normalized inspection result.
	 * @return void
	 */
	private function derive_issues( $url, $post_id, array $r ) {
		$found = array();

		// Indexing problems.
		if ( ! empty( $r['verdict'] ) && 'PASS' !== $r['verdict'] ) {
			$found[] = array(
				'type'     => 'indexing',
				'severity' => 'error',
				'summary'  => sprintf( 'Not indexed: %s', $r['coverage_state'] ? $r['coverage_state'] : $r['verdict'] ),
				'details'  => array(
					'coverage_state' => $r['coverage_state'],
					'indexing_state' => $r['indexing_state'],
					'robots_state'   => $r['robots_state'],
					'fetch_state'    => $r['page_fetch_state'],
				),
			);
		}

		// Canonical mismatch.
		$g = trim( (string) $r['google_canonical'] );
		$u = trim( (string) $r['user_canonical'] );
		if ( '' !== $g && '' !== $u && untrailingslashit( $g ) !== untrailingslashit( $u ) ) {
			$found[] = array(
				'type'     => 'canonical',
				'severity' => 'warning',
				'summary'  => 'Google chose a different canonical than declared',
				'details'  => array(
					'google_canonical' => $g,
					'user_canonical'   => $u,
				),
			);
		}

		// Mobile usability.
		if ( ! empty( $r['mobile_verdict'] ) && 'PASS' !== $r['mobile_verdict'] && ! empty( $r['mobile_issues'] ) ) {
			$msgs    = array_filter( array_map( fn( $i ) => $i['message'] ?? ( $i['issueType'] ?? '' ), $r['mobile_issues'] ) );
			$found[] = array(
				'type'     => 'mobile',
				'severity' => 'warning',
				'summary'  => sprintf( '%d mobile usability issue(s)', count( $r['mobile_issues'] ) ),
				'details'  => array( 'issues' => array_values( $msgs ) ),
			);
		}

		// Structured-data / rich-result issues.
		if ( ! empty( $r['rich_verdict'] ) && 'PASS' !== $r['rich_verdict'] && ! empty( $r['rich_items'] ) ) {
			$problems = array();
			foreach ( $r['rich_items'] as $detected ) {
				foreach ( ( $detected['items'] ?? array() ) as $item ) {
					foreach ( ( $item['issues'] ?? array() ) as $iss ) {
						$problems[] = ( $detected['richResultType'] ?? 'Item' ) . ': ' . ( $iss['issueMessage'] ?? $iss['message'] ?? $iss['issueType'] ?? 'issue' );
					}
				}
			}
			if ( $problems ) {
				$found[] = array(
					'type'     => 'schema',
					'severity' => 'warning',
					'summary'  => sprintf( '%d structured-data issue(s)', count( $problems ) ),
					'details'  => array( 'issues' => $problems ),
				);
			}
		}

		$this->reconcile_issues( $url, $found );
	}

	/**
	 * Upsert current issues for a URL and auto-resolve types no longer present.
	 *
	 * @param string $url   The URL.
	 * @param array  $found Issues detected this run.
	 */
	private function reconcile_issues( $url, array $found ) {
		global $wpdb;
		$table      = Database::issues_table();
		$hash       = md5( $url );
		$now        = current_time( 'mysql' );
		$open_types = array();

		foreach ( $found as $issue ) {
			$open_types[] = $issue['type'];
			$issue_hash   = md5( $url . '|' . $issue['type'] );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table requires a direct query.
			$wpdb->query(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix is safe; values are prepared.
					"INSERT INTO {$table} (url,url_hash,type,severity,summary,details_json,status,issue_hash,detected_at)
					 VALUES (%s,%s,%s,%s,%s,%s,'open',%s,%s)
					 ON DUPLICATE KEY UPDATE severity=VALUES(severity), summary=VALUES(summary), details_json=VALUES(details_json),
					 status=IF(status='ignored','ignored','open'), detected_at=VALUES(detected_at), resolved_at=NULL",
					$url,
					$hash,
					$issue['type'],
					$issue['severity'],
					$issue['summary'],
					wp_json_encode( $issue['details'] ),
					$issue_hash,
					$now
				)
			);
		}

		// Auto-resolve previously-open issues for this URL that no longer appear.
		$placeholders = $open_types ? implode( ',', array_fill( 0, count( $open_types ), '%s' ) ) : '';
		if ( $open_types ) {
			$args = array_merge( array( $now, $hash ), $open_types );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table requires a direct query.
			$wpdb->query(
				// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The IN() placeholders are dynamically generated; the replacement count matches at runtime.
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix is safe; the IN() placeholders are dynamically generated and all values are prepared.
					"UPDATE {$table} SET status='resolved', resolved_at=%s WHERE url_hash=%s AND status='open' AND type NOT IN ($placeholders)",
					...$args
				)
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table requires a direct query.
			$wpdb->query(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix is safe; values are prepared.
					"UPDATE {$table} SET status='resolved', resolved_at=%s WHERE url_hash=%s AND status='open'",
					$now,
					$hash
				)
			);
		}
	}

	/* ---------- Progress helpers (for the admin screen) ---------- */

	/**
	 * Summarize queue progress counts for the admin screen.
	 *
	 * @return array<string,int> Totals keyed by total, pending, done, errors.
	 */
	public static function progress() {
		global $wpdb;
		$q = Database::queue_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table requires a direct query.
		$row = $wpdb->get_row(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix is safe; this aggregate query takes no user input.
			"SELECT COUNT(*) total, SUM(status='pending') pending, SUM(status='done') done, SUM(status='error') errors FROM {$q}",
			ARRAY_A
		);
		return array(
			'total'   => (int) ( $row['total'] ?? 0 ),
			'pending' => (int) ( $row['pending'] ?? 0 ),
			'done'    => (int) ( $row['done'] ?? 0 ),
			'errors'  => (int) ( $row['errors'] ?? 0 ),
		);
	}

	/**
	 * Return the number of inspections used today.
	 *
	 * @return int Inspections performed today.
	 */
	public static function daily_used() {
		$rec = get_option( self::OPT_DAILY_COUNT, array() );
		if ( is_array( $rec ) && ( $rec['date'] ?? '' ) === gmdate( 'Y-m-d' ) ) {
			return (int) $rec['n'];
		}
		return 0;
	}
}
