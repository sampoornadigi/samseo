<?php
/**
 * Database layer: table creation, upsert, and read helpers.
 *
 * @package Sampoorna\SEO
 */

namespace Sampoorna\SEO\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Database access layer for the plugin's custom tables.
 *
 * Handles dbDelta table creation/upgrade plus upsert and read helpers for
 * performance, inspection, issue, queue, and suggestion data.
 */
class Database {

	/** Bump when table structure changes so the upgrade routine re-runs dbDelta. */
	const DB_VERSION     = '5';
	const OPT_DB_VERSION = 'sampoorna_seo_db_version';

	/**
	 * Fully-qualified performance table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'sampoorna_seo_performance';
	}

	/**
	 * Fully-qualified inspections table name.
	 *
	 * @return string
	 */
	public static function inspections_table() {
		global $wpdb;
		return $wpdb->prefix . 'sampoorna_seo_inspections';
	}

	/**
	 * Fully-qualified issues table name.
	 *
	 * @return string
	 */
	public static function issues_table() {
		global $wpdb;
		return $wpdb->prefix . 'sampoorna_seo_issues';
	}

	/**
	 * Fully-qualified queue table name.
	 *
	 * @return string
	 */
	public static function queue_table() {
		global $wpdb;
		return $wpdb->prefix . 'sampoorna_seo_queue';
	}

	/**
	 * Fully-qualified suggestions table name.
	 *
	 * @return string
	 */
	public static function suggestions_table() {
		global $wpdb;
		return $wpdb->prefix . 'sampoorna_seo_suggestions';
	}

	/**
	 * Fully-qualified redirects table name.
	 *
	 * @return string
	 */
	public static function redirects_table() {
		global $wpdb;
		return $wpdb->prefix . 'sampoorna_seo_redirects';
	}

	/**
	 * Fully-qualified 404-log table name.
	 *
	 * @return string
	 */
	public static function not_found_table() {
		global $wpdb;
		return $wpdb->prefix . 'sampoorna_seo_not_found';
	}

	/**
	 * Fully-qualified control-plane changes (deployment journal) table name.
	 *
	 * @return string
	 */
	public static function changes_table() {
		global $wpdb;
		return $wpdb->prefix . 'sampoorna_seo_changes';
	}

	/**
	 * Fully-qualified AI-crawler hit-log table name.
	 *
	 * @return string
	 */
	public static function ai_hits_table() {
		global $wpdb;
		return $wpdb->prefix . 'sampoorna_seo_ai_hits';
	}

	/**
	 * Fully-qualified AI-referral log table name.
	 *
	 * @return string
	 */
	public static function ai_referrals_table() {
		global $wpdb;
		return $wpdb->prefix . 'sampoorna_seo_ai_referrals';
	}

	/**
	 * Run table creation if the plugin DB version changed. Cheap to call on load.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		if ( get_option( self::OPT_DB_VERSION ) !== self::DB_VERSION ) {
			self::create_tables();
			update_option( self::OPT_DB_VERSION, self::DB_VERSION );
		}
	}

	/**
	 * Create / update custom tables. Idempotent (dbDelta).
	 *
	 * @return void
	 */
	public static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$perf            = self::table();
		$insp            = self::inspections_table();
		$issues          = self::issues_table();
		$queue           = self::queue_table();
		$sugg            = self::suggestions_table();
		$redirects       = self::redirects_table();
		$not_found       = self::not_found_table();
		$changes         = self::changes_table();
		$ai_hits         = self::ai_hits_table();
		$ai_referrals    = self::ai_referrals_table();

		$sql = array();

		$sql[] = "CREATE TABLE {$perf} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			property_url VARCHAR(255) NOT NULL,
			date DATE NOT NULL,
			page_url VARCHAR(512) NOT NULL DEFAULT '',
			query VARCHAR(512) NOT NULL DEFAULT '',
			clicks INT UNSIGNED NOT NULL DEFAULT 0,
			impressions INT UNSIGNED NOT NULL DEFAULT 0,
			ctr DECIMAL(8,5) NOT NULL DEFAULT 0,
			position DECIMAL(8,2) NOT NULL DEFAULT 0,
			row_hash CHAR(32) NOT NULL,
			synced_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_row (row_hash),
			KEY prop_date (property_url(150), date)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$insp} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			property_url VARCHAR(255) NOT NULL,
			url VARCHAR(512) NOT NULL,
			url_hash CHAR(32) NOT NULL,
			verdict VARCHAR(40) NOT NULL DEFAULT '',
			coverage_state VARCHAR(160) NOT NULL DEFAULT '',
			robots_state VARCHAR(60) NOT NULL DEFAULT '',
			indexing_state VARCHAR(60) NOT NULL DEFAULT '',
			page_fetch_state VARCHAR(60) NOT NULL DEFAULT '',
			google_canonical VARCHAR(512) NOT NULL DEFAULT '',
			user_canonical VARCHAR(512) NOT NULL DEFAULT '',
			mobile_verdict VARCHAR(40) NOT NULL DEFAULT '',
			rich_verdict VARCHAR(40) NOT NULL DEFAULT '',
			last_crawl_time DATETIME NULL,
			raw_json LONGTEXT NULL,
			last_inspected DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_url (url_hash),
			KEY prop (property_url(150))
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$issues} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			url VARCHAR(512) NOT NULL,
			url_hash CHAR(32) NOT NULL,
			type VARCHAR(40) NOT NULL,
			severity VARCHAR(20) NOT NULL DEFAULT 'warning',
			summary VARCHAR(255) NOT NULL DEFAULT '',
			details_json LONGTEXT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'open',
			issue_hash CHAR(32) NOT NULL,
			detected_at DATETIME NOT NULL,
			resolved_at DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_issue (issue_hash),
			KEY status_type (status, type)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$queue} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			url VARCHAR(512) NOT NULL,
			url_hash CHAR(32) NOT NULL,
			post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			priority TINYINT NOT NULL DEFAULT 5,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			last_inspected DATETIME NULL,
			next_due DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_q (url_hash),
			KEY status_due (status, next_due)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$sugg} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			url VARCHAR(512) NOT NULL DEFAULT '',
			type VARCHAR(40) NOT NULL,
			priority VARCHAR(20) NOT NULL DEFAULT 'medium',
			current_value TEXT NULL,
			suggested_value TEXT NULL,
			recommendation VARCHAR(255) NOT NULL DEFAULT '',
			status VARCHAR(20) NOT NULL DEFAULT 'new',
			sugg_hash CHAR(32) NOT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_sugg (sugg_hash),
			KEY status_type (status, type)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$redirects} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			source VARCHAR(512) NOT NULL,
			source_hash CHAR(32) NOT NULL,
			target VARCHAR(512) NOT NULL DEFAULT '',
			type SMALLINT UNSIGNED NOT NULL DEFAULT 301,
			is_regex TINYINT(1) NOT NULL DEFAULT 0,
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			hits BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			last_matched DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_source (source_hash),
			KEY status (status)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$not_found} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			url VARCHAR(512) NOT NULL,
			url_hash CHAR(32) NOT NULL,
			hits BIGINT UNSIGNED NOT NULL DEFAULT 0,
			referrer VARCHAR(512) NOT NULL DEFAULT '',
			user_agent VARCHAR(255) NOT NULL DEFAULT '',
			status VARCHAR(20) NOT NULL DEFAULT 'new',
			first_seen DATETIME NOT NULL,
			last_seen DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_url (url_hash),
			KEY status (status)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$changes} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			deploy_id VARCHAR(64) NOT NULL,
			object_type VARCHAR(10) NOT NULL DEFAULT 'post',
			object_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			field VARCHAR(40) NOT NULL,
			old_value LONGTEXT NULL,
			new_value LONGTEXT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'applied',
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY deploy (deploy_id),
			KEY object (object_type, object_id)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$ai_hits} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			bot VARCHAR(64) NOT NULL,
			hits BIGINT UNSIGNED NOT NULL DEFAULT 0,
			last_url VARCHAR(512) NOT NULL DEFAULT '',
			first_seen DATETIME NOT NULL,
			last_seen DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_bot (bot)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$ai_referrals} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			source VARCHAR(64) NOT NULL,
			hits BIGINT UNSIGNED NOT NULL DEFAULT 0,
			last_url VARCHAR(512) NOT NULL DEFAULT '',
			first_seen DATETIME NOT NULL,
			last_seen DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_source (source)
		) {$charset_collate};";

		foreach ( $sql as $stmt ) {
			dbDelta( $stmt );
		}
	}

	/**
	 * Upsert a single performance row.
	 *
	 * @param string $property Property URL.
	 * @param array  $row      Normalized row data.
	 * @return void
	 */
	public static function upsert_row( $property, array $row ) {
		global $wpdb;

		$page  = isset( $row['page'] ) ? $row['page'] : '';
		$query = isset( $row['query'] ) ? $row['query'] : '';
		$date  = $row['date'];

		$hash = md5( $property . '|' . $date . '|' . $page . '|' . $query );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom plugin table; no caching needed for write.
		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table name from $wpdb->prefix is safe; values are prepared.
				'INSERT INTO ' . self::table() . ' (property_url,date,page_url,query,clicks,impressions,ctr,position,row_hash,synced_at)
				 VALUES (%s,%s,%s,%s,%d,%d,%f,%f,%s,%s)
				 ON DUPLICATE KEY UPDATE clicks=VALUES(clicks),impressions=VALUES(impressions),ctr=VALUES(ctr),position=VALUES(position),synced_at=VALUES(synced_at)',
				$property,
				$date,
				$page,
				$query,
				(int) $row['clicks'],
				(int) $row['impressions'],
				(float) $row['ctr'],
				(float) $row['position'],
				$hash,
				current_time( 'mysql' )
			)
		);
	}

	/**
	 * Daily totals for a property over the last N days (for the trend chart).
	 *
	 * @param string $property Property URL.
	 * @param int    $days     Look-back window.
	 * @return array
	 */
	public static function daily_totals( $property, $days = 90 ) {
		global $wpdb;
		$since = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );
		$table = self::table();
		// Table name from $wpdb->prefix is safe; values are prepared below.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = "SELECT date, SUM(clicks) clicks, SUM(impressions) impressions, AVG(position) position
				 FROM {$table}
				 WHERE property_url=%s AND query='' AND page_url='' AND date>=%s
				 GROUP BY date ORDER BY date ASC";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom plugin table; no caching for analytics read.
		return $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql contains only a safe $wpdb->prefix table name; all values are prepared.
			$wpdb->prepare( $sql, $property, $since ),
			ARRAY_A
		);
	}

	/**
	 * Top rows by a dimension (page or query) for a recent window.
	 *
	 * @param string $property  Property URL.
	 * @param string $dimension 'page' or 'query'.
	 * @param int    $days      Look-back window.
	 * @param int    $limit     Max rows.
	 * @return array
	 */
	public static function top_rows( $property, $dimension, $days = 28, $limit = 100 ) {
		global $wpdb;
		$col   = ( 'query' === $dimension ) ? 'query' : 'page_url';
		$other = ( 'query' === $dimension ) ? 'page_url' : 'query';
		$since = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );
		$table = self::table();

		// Table name from $wpdb->prefix; $col/$other are hard-coded whitelisted column names; values are prepared below.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = "SELECT {$col} AS label, SUM(clicks) clicks, SUM(impressions) impressions,
				        (SUM(clicks)/NULLIF(SUM(impressions),0)) ctr, AVG(position) position
				 FROM {$table}
				 WHERE property_url=%s AND {$col}<>'' AND {$other}='' AND date>=%s
				 GROUP BY {$col} ORDER BY clicks DESC LIMIT %d";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom plugin table; no caching for analytics read.
		return $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql interpolates only safe identifiers; all values are prepared.
			$wpdb->prepare( $sql, $property, $since, $limit ),
			ARRAY_A
		);
	}

	/**
	 * Pages with high impressions but low CTR (candidates for title/meta rewrite).
	 *
	 * @param string $property    Property URL.
	 * @param int    $days        Look-back window.
	 * @param int    $min_impr    Minimum impressions to qualify.
	 * @param float  $max_ctr     Maximum CTR (fraction) to flag.
	 * @param float  $max_pos     Only flag pages ranking at/above this position.
	 * @param int    $limit       Max rows.
	 * @return array
	 */
	public static function low_ctr_pages( $property, $days = 28, $min_impr = 100, $max_ctr = 0.01, $max_pos = 20.0, $limit = 30 ) {
		global $wpdb;
		$since = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );
		$table = self::table();

		// Table name from $wpdb->prefix is safe; values are prepared below.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = "SELECT page_url,
					SUM(clicks) clicks,
					SUM(impressions) impressions,
					(SUM(clicks)/NULLIF(SUM(impressions),0)) ctr,
					AVG(position) position
				 FROM {$table}
				 WHERE property_url=%s AND page_url<>'' AND query='' AND date>=%s
				 GROUP BY page_url
				 HAVING impressions>=%d AND ctr<=%f AND position<=%f
				 ORDER BY impressions DESC LIMIT %d";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom plugin table; no caching for analytics read.
		return $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql contains only a safe $wpdb->prefix table name; all values are prepared.
			$wpdb->prepare( $sql, $property, $since, $min_impr, $max_ctr, $max_pos, $limit ),
			ARRAY_A
		);
	}

	/**
	 * Headline totals for two adjacent windows, for drop detection / KPIs.
	 *
	 * @param string $property Property URL.
	 * @param int    $window   Window size in days.
	 * @return array { current: {...}, previous: {...} }
	 */
	public static function compare_windows( $property, $window = 7 ) {
		global $wpdb;
		$cur_start  = gmdate( 'Y-m-d', strtotime( "-{$window} days" ) );
		$prev_start = gmdate( 'Y-m-d', strtotime( '-' . ( $window * 2 ) . ' days' ) );

		$agg = function ( $start, $end ) use ( $wpdb, $property ) {
			$table = self::table();
			// Table name from $wpdb->prefix is safe; values are prepared below.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$sql = "SELECT SUM(clicks) clicks, SUM(impressions) impressions, AVG(position) position
					 FROM {$table}
					 WHERE property_url=%s AND query='' AND page_url='' AND date>=%s AND date<%s";
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom plugin table; no caching for analytics read.
			$r = $wpdb->get_row(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql contains only a safe $wpdb->prefix table name; all values are prepared.
				$wpdb->prepare( $sql, $property, $start, $end ),
				ARRAY_A
			);
			return array(
				'clicks'      => (int) ( $r['clicks'] ?? 0 ),
				'impressions' => (int) ( $r['impressions'] ?? 0 ),
				'position'    => round( (float) ( $r['position'] ?? 0 ), 1 ),
			);
		};

		return array(
			'current'  => $agg( $cur_start, gmdate( 'Y-m-d', strtotime( '+1 day' ) ) ),
			'previous' => $agg( $prev_start, $cur_start ),
		);
	}

	/**
	 * Drop down to URL-level drops between two windows.
	 *
	 * @param string $property  Property URL.
	 * @param int    $window    Window size in days.
	 * @param float  $threshold Fractional click drop to flag (0.3 = 30%).
	 * @return array
	 */
	public static function click_drops( $property, $window = 7, $threshold = 0.3 ) {
		global $wpdb;
		$cur_start  = gmdate( 'Y-m-d', strtotime( "-{$window} days" ) );
		$prev_start = gmdate( 'Y-m-d', strtotime( '-' . ( $window * 2 ) . ' days' ) );
		$table      = self::table();

		// Table name from $wpdb->prefix is safe; values are prepared below.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = "SELECT page_url,
					SUM(CASE WHEN date>=%s THEN clicks ELSE 0 END) cur_clicks,
					SUM(CASE WHEN date>=%s AND date<%s THEN clicks ELSE 0 END) prev_clicks
				 FROM {$table}
				 WHERE property_url=%s AND page_url<>'' AND query='' AND date>=%s
				 GROUP BY page_url HAVING prev_clicks > 0";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom plugin table; no caching for analytics read.
		$rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql contains only a safe $wpdb->prefix table name; all values are prepared.
			$wpdb->prepare( $sql, $cur_start, $prev_start, $cur_start, $property, $prev_start ),
			ARRAY_A
		);

		$drops = array();
		foreach ( $rows as $r ) {
			$prev  = (int) $r['prev_clicks'];
			$cur   = (int) $r['cur_clicks'];
			$delta = ( $prev - $cur ) / $prev;
			if ( $delta >= $threshold ) {
				$drops[] = array(
					'page_url'    => $r['page_url'],
					'prev_clicks' => $prev,
					'cur_clicks'  => $cur,
					'drop_pct'    => round( $delta * 100, 1 ),
				);
			}
		}
		usort( $drops, fn( $a, $b ) => $b['drop_pct'] <=> $a['drop_pct'] );
		return $drops;
	}

	/* ---------- Issues (Phase 2) ---------- */

	/**
	 * Fetch issues with optional filters.
	 *
	 * @param array $args status|type|search|limit|offset.
	 * @return array
	 */
	public static function get_issues( array $args = array() ) {
		global $wpdb;
		$table  = self::issues_table();
		$where  = array( '1=1' );
		$params = array();

		$status = $args['status'] ?? 'open';
		if ( 'all' !== $status ) {
			$where[]  = 'status=%s';
			$params[] = $status;
		}
		if ( ! empty( $args['type'] ) ) {
			$where[]  = 'type=%s';
			$params[] = $args['type'];
		}
		if ( ! empty( $args['search'] ) ) {
			$where[]  = 'url LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $args['search'] ) . '%';
		}

		$limit    = (int) ( $args['limit'] ?? 100 );
		$offset   = (int) ( $args['offset'] ?? 0 );
		$sql      = 'SELECT * FROM ' . $table . ' WHERE ' . implode( ' AND ', $where ) .
			' ORDER BY FIELD(severity,"error","warning","notice"), detected_at DESC LIMIT %d OFFSET %d';
		$params[] = $limit;
		$params[] = $offset;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- $sql is assembled from hard-coded fragments and a $wpdb->prefix table name; all values are passed as prepared placeholders.
		return $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A );
	}

	/**
	 * Count open issues grouped by type (for summary chips).
	 *
	 * @return array type => count
	 */
	public static function issue_type_counts() {
		global $wpdb;
		$table = self::issues_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix is safe; query has no dynamic values.
		$rows = $wpdb->get_results( "SELECT type, COUNT(*) c FROM {$table} WHERE status='open' GROUP BY type", ARRAY_A );
		$out  = array();
		foreach ( $rows as $r ) {
			$out[ $r['type'] ] = (int) $r['c'];
		}
		return $out;
	}

	/**
	 * Update status for a set of issue IDs.
	 *
	 * @param int[]  $ids    Issue IDs.
	 * @param string $status open|ignored|resolved.
	 * @return void
	 */
	public static function set_issue_status( array $ids, $status ) {
		global $wpdb;
		$ids = array_filter( array_map( 'absint', $ids ) );
		if ( empty( $ids ) ) {
			return;
		}
		$table        = self::issues_table();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$resolved     = ( 'resolved' === $status ) ? current_time( 'mysql' ) : null;
		$args         = array_merge( array( $status, $resolved ), $ids );
		// Table name from $wpdb->prefix is safe; $placeholders is built solely from literal %d tokens.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = "UPDATE {$table} SET status=%s, resolved_at=%s WHERE id IN ($placeholders)";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom plugin table; status update needs no caching.
		$wpdb->query(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- All values (status, resolved_at and the IN list) are prepared via the spread $args; the sniff cannot count the dynamically generated %d placeholders.
			$wpdb->prepare( $sql, ...$args )
		);
	}

	/* ---------- Suggestions (Phase 3) ---------- */

	/**
	 * Insert or update a suggestion (keyed by url+type).
	 *
	 * @param array $s Suggestion fields.
	 * @return void
	 */
	public static function upsert_suggestion( array $s ) {
		global $wpdb;
		$table = self::suggestions_table();
		$hash  = md5( ( $s['url'] ?? '' ) . '|' . $s['type'] );
		$now   = current_time( 'mysql' );

		// Table name from $wpdb->prefix is safe; values are prepared below.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = "INSERT INTO {$table} (post_id,url,type,priority,current_value,suggested_value,recommendation,status,sugg_hash,created_at,updated_at)
				 VALUES (%d,%s,%s,%s,%s,%s,%s,'new',%s,%s,%s)
				 ON DUPLICATE KEY UPDATE post_id=VALUES(post_id), priority=VALUES(priority),
				 current_value=VALUES(current_value), suggested_value=VALUES(suggested_value),
				 recommendation=VALUES(recommendation),
				 status=IF(status='dismissed','dismissed',IF(status='applied','applied','new')),
				 updated_at=VALUES(updated_at)";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom plugin table; upsert needs no caching.
		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql contains only a safe $wpdb->prefix table name; all values are prepared.
				$sql,
				(int) ( $s['post_id'] ?? 0 ),
				$s['url'] ?? '',
				$s['type'],
				$s['priority'] ?? 'medium',
				$s['current_value'] ?? '',
				$s['suggested_value'] ?? '',
				$s['recommendation'] ?? '',
				$hash,
				$now,
				$now
			)
		);
	}

	/**
	 * Fetch suggestions with optional filters.
	 *
	 * @param array $args status|type|search|limit|offset.
	 * @return array
	 */
	public static function get_suggestions( array $args = array() ) {
		global $wpdb;
		$table  = self::suggestions_table();
		$where  = array( '1=1' );
		$params = array();

		$status = $args['status'] ?? 'new';
		if ( 'all' !== $status ) {
			$where[]  = 'status=%s';
			$params[] = $status;
		}
		if ( ! empty( $args['type'] ) ) {
			$where[]  = 'type=%s';
			$params[] = $args['type'];
		}
		if ( ! empty( $args['search'] ) ) {
			$where[]  = 'url LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $args['search'] ) . '%';
		}

		$limit    = (int) ( $args['limit'] ?? 200 );
		$offset   = (int) ( $args['offset'] ?? 0 );
		$sql      = 'SELECT * FROM ' . $table . ' WHERE ' . implode( ' AND ', $where ) .
			' ORDER BY FIELD(priority,"high","medium","low"), updated_at DESC LIMIT %d OFFSET %d';
		$params[] = $limit;
		$params[] = $offset;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- $sql is assembled from hard-coded fragments and a $wpdb->prefix table name; all values are passed as prepared placeholders.
		return $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A );
	}

	/**
	 * Count "new" suggestions grouped by type.
	 *
	 * @return array type => count
	 */
	public static function suggestion_type_counts() {
		global $wpdb;
		$table = self::suggestions_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix is safe; query has no dynamic values.
		$rows = $wpdb->get_results( "SELECT type, COUNT(*) c FROM {$table} WHERE status='new' GROUP BY type", ARRAY_A );
		$out  = array();
		foreach ( $rows as $r ) {
			$out[ $r['type'] ] = (int) $r['c'];
		}
		return $out;
	}

	/**
	 * Update status for a set of suggestion IDs.
	 *
	 * @param int[]  $ids    Suggestion IDs.
	 * @param string $status new|applied|dismissed.
	 * @return void
	 */
	public static function set_suggestion_status( array $ids, $status ) {
		global $wpdb;
		$ids = array_filter( array_map( 'absint', $ids ) );
		if ( empty( $ids ) ) {
			return;
		}
		$table        = self::suggestions_table();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$args         = array_merge( array( $status, current_time( 'mysql' ) ), $ids );
		// Table name from $wpdb->prefix is safe; $placeholders is built solely from literal %d tokens.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = "UPDATE {$table} SET status=%s, updated_at=%s WHERE id IN ($placeholders)";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom plugin table; status update needs no caching.
		$wpdb->query(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- All values (status, updated_at and the IN list) are prepared via the spread $args; the sniff cannot count the dynamically generated %d placeholders.
			$wpdb->prepare( $sql, ...$args )
		);
	}

	/* ---------- Redirects (Phase 1) ---------- */

	/**
	 * All active redirects, ordered exact-first then by id (for the matcher).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function active_redirects() {
		global $wpdb;
		$table = self::redirects_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix is safe; query has no dynamic values.
		$rows = $wpdb->get_results( "SELECT * FROM {$table} WHERE status='active' ORDER BY is_regex ASC, id ASC", ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Fetch redirects with optional status filter.
	 *
	 * @param array $args status|limit.
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_redirects( array $args = array() ) {
		global $wpdb;
		$table  = self::redirects_table();
		$status = isset( $args['status'] ) ? (string) $args['status'] : 'all';
		$limit  = (int) ( $args['limit'] ?? 200 );

		if ( 'all' === $status ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix is safe; limit is prepared.
			$sql = $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix is safe; values are prepared.
			$sql = $wpdb->prepare( "SELECT * FROM {$table} WHERE status=%s ORDER BY id DESC LIMIT %d", $status, $limit );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- $sql is prepared above.
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Insert a redirect (non-regex deduped by source_hash). Returns insert id or 0.
	 *
	 * @param array $data source|target|type|is_regex.
	 * @return int
	 */
	public static function insert_redirect( array $data ) {
		global $wpdb;
		$source   = (string) ( $data['source'] ?? '' );
		$is_regex = ! empty( $data['is_regex'] ) ? 1 : 0;
		if ( '' === $source ) {
			return 0;
		}
		$wpdb->insert(
			self::redirects_table(),
			array(
				'source'      => $source,
				'source_hash' => md5( $source ),
				'target'      => (string) ( $data['target'] ?? '' ),
				'type'        => (int) ( $data['type'] ?? 301 ),
				'is_regex'    => $is_regex,
				'status'      => 'active',
				'hits'        => 0,
				'created_at'  => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%d', '%d', '%s', '%d', '%s' )
		);
		self::bump_redirects_cache();
		return (int) $wpdb->insert_id;
	}

	/**
	 * Delete redirects by id.
	 *
	 * @param int[] $ids Redirect IDs.
	 * @return void
	 */
	public static function delete_redirects( array $ids ) {
		global $wpdb;
		$ids = array_filter( array_map( 'absint', $ids ) );
		if ( empty( $ids ) ) {
			return;
		}
		$table        = self::redirects_table();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix is safe; placeholders are literal %d tokens.
		$sql = "DELETE FROM {$table} WHERE id IN ($placeholders)";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- IDs are prepared via the spread args.
		$wpdb->query( $wpdb->prepare( $sql, ...$ids ) );
		self::bump_redirects_cache();
	}

	/**
	 * Set status for a set of redirect IDs.
	 *
	 * @param int[]  $ids    Redirect IDs.
	 * @param string $status active|disabled.
	 * @return void
	 */
	public static function set_redirect_status( array $ids, $status ) {
		global $wpdb;
		$ids = array_filter( array_map( 'absint', $ids ) );
		if ( empty( $ids ) ) {
			return;
		}
		$table        = self::redirects_table();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$args         = array_merge( array( $status ), $ids );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix is safe; placeholders are literal %d tokens.
		$sql = "UPDATE {$table} SET status=%s WHERE id IN ($placeholders)";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- All values prepared via the spread $args.
		$wpdb->query( $wpdb->prepare( $sql, ...$args ) );
		self::bump_redirects_cache();
	}

	/**
	 * Record a redirect hit.
	 *
	 * @param int $id Redirect ID.
	 * @return void
	 */
	public static function touch_redirect( $id ) {
		global $wpdb;
		$table = self::redirects_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix is safe; values are prepared.
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET hits = hits + 1, last_matched = %s WHERE id = %d", current_time( 'mysql' ), (int) $id ) );
	}

	/**
	 * Bump the cached-active-redirects version (used by the front-end matcher cache).
	 *
	 * @return void
	 */
	private static function bump_redirects_cache() {
		delete_transient( 'sampoorna_seo_redirects_active' );
	}

	/* ---------- 404 log (Phase 1) ---------- */

	/**
	 * Record a not-found URL (upsert; increments hits on repeat).
	 *
	 * @param string $url       Requested path.
	 * @param string $referrer  Referrer.
	 * @param string $user_agent User agent.
	 * @return void
	 */
	public static function log_not_found( $url, $referrer = '', $user_agent = '' ) {
		global $wpdb;
		$url = (string) $url;
		if ( '' === $url ) {
			return;
		}
		$now   = current_time( 'mysql' );
		$table = self::not_found_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix is safe; values are prepared.
		$sql = "INSERT INTO {$table} (url,url_hash,hits,referrer,user_agent,status,first_seen,last_seen)
				 VALUES (%s,%s,1,%s,%s,'new',%s,%s)
				 ON DUPLICATE KEY UPDATE hits = hits + 1, last_seen = VALUES(last_seen), referrer = VALUES(referrer)";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- $sql contains only a safe $wpdb->prefix table name; all values are prepared.
		$wpdb->query( $wpdb->prepare( $sql, $url, md5( $url ), $referrer, $user_agent, $now, $now ) );
	}

	/**
	 * Fetch 404-log rows with optional status filter.
	 *
	 * @param array $args status|limit.
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_not_found( array $args = array() ) {
		global $wpdb;
		$table  = self::not_found_table();
		$status = isset( $args['status'] ) ? (string) $args['status'] : 'new';
		$limit  = (int) ( $args['limit'] ?? 200 );

		if ( 'all' === $status ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix is safe; limit is prepared.
			$sql = $wpdb->prepare( "SELECT * FROM {$table} ORDER BY last_seen DESC LIMIT %d", $limit );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix is safe; values are prepared.
			$sql = $wpdb->prepare( "SELECT * FROM {$table} WHERE status=%s ORDER BY last_seen DESC LIMIT %d", $status, $limit );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- $sql is prepared above.
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Delete 404-log rows by id.
	 *
	 * @param int[] $ids Row IDs.
	 * @return void
	 */
	public static function delete_not_found( array $ids ) {
		global $wpdb;
		$ids = array_filter( array_map( 'absint', $ids ) );
		if ( empty( $ids ) ) {
			return;
		}
		$table        = self::not_found_table();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix is safe; placeholders are literal %d tokens.
		$sql = "DELETE FROM {$table} WHERE id IN ($placeholders)";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- IDs are prepared via the spread args.
		$wpdb->query( $wpdb->prepare( $sql, ...$ids ) );
	}

	/**
	 * Set status for a set of 404-log IDs.
	 *
	 * @param int[]  $ids    Row IDs.
	 * @param string $status new|ignored|redirected.
	 * @return void
	 */
	public static function set_not_found_status( array $ids, $status ) {
		global $wpdb;
		$ids = array_filter( array_map( 'absint', $ids ) );
		if ( empty( $ids ) ) {
			return;
		}
		$table        = self::not_found_table();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$args         = array_merge( array( $status ), $ids );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix is safe; placeholders are literal %d tokens.
		$sql = "UPDATE {$table} SET status=%s WHERE id IN ($placeholders)";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- All values prepared via the spread $args.
		$wpdb->query( $wpdb->prepare( $sql, ...$args ) );
	}

	/* ---------- Control-plane deployment journal ---------- */

	/**
	 * Record one applied change in the deployment journal.
	 *
	 * @param array<string,mixed> $row { deploy_id, object_type, object_id, field, old_value, new_value }.
	 * @return void
	 */
	public static function record_change( array $row ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom plugin table; no caching for a write.
		$wpdb->insert(
			self::changes_table(),
			array(
				'deploy_id'   => (string) $row['deploy_id'],
				'object_type' => (string) $row['object_type'],
				'object_id'   => (int) $row['object_id'],
				'field'       => (string) $row['field'],
				'old_value'   => (string) $row['old_value'],
				'new_value'   => (string) $row['new_value'],
				'status'      => 'applied',
				'created_at'  => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Fetch journal rows for a deployment.
	 *
	 * @param string $deploy_id Deployment ID.
	 * @param string $status    Optional status filter ('' = all).
	 * @return array<int,array<string,mixed>>
	 */
	public static function changes_for_deploy( $deploy_id, $status = '' ) {
		global $wpdb;
		$table = self::changes_table();
		if ( '' !== $status ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table from $wpdb->prefix; values prepared.
			$sql = $wpdb->prepare( "SELECT * FROM {$table} WHERE deploy_id=%s AND status=%s ORDER BY id ASC", $deploy_id, $status );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table from $wpdb->prefix; value prepared.
			$sql = $wpdb->prepare( "SELECT * FROM {$table} WHERE deploy_id=%s ORDER BY id ASC", $deploy_id );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- $sql prepared above.
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Whether any journal rows exist for a deployment.
	 *
	 * @param string $deploy_id Deployment ID.
	 * @return bool
	 */
	public static function deploy_exists( $deploy_id ) {
		global $wpdb;
		$table = self::changes_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table from $wpdb->prefix; value prepared.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE deploy_id=%s", $deploy_id ) ) > 0;
	}

	/**
	 * Update the status of a single journal row.
	 *
	 * @param int    $id     Row ID.
	 * @param string $status applied|rolled_back.
	 * @return void
	 */
	public static function set_change_status( $id, $status ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom plugin table; no caching for a write.
		$wpdb->update(
			self::changes_table(),
			array( 'status' => (string) $status ),
			array( 'id' => (int) $id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/* ---------- AI-crawler engagement log ---------- */

	/**
	 * Record one AI-bot hit (upsert by bot key, incrementing the counter).
	 *
	 * @param string $bot Bot key.
	 * @param string $url Requested URL.
	 * @return void
	 */
	public static function record_ai_hit( $bot, $url ) {
		global $wpdb;
		$table = self::ai_hits_table();
		$now   = current_time( 'mysql' );
		$sql   = "INSERT INTO {$table} (bot, hits, last_url, first_seen, last_seen) VALUES (%s, 1, %s, %s, %s) ON DUPLICATE KEY UPDATE hits = hits + 1, last_url = VALUES(last_url), last_seen = VALUES(last_seen)";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Table from $wpdb->prefix; all values bound via prepare().
		$wpdb->query( $wpdb->prepare( $sql, $bot, $url, $now, $now ) );
	}

	/**
	 * Fetch the AI-crawler hit log, most-recently-seen first.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function ai_hits() {
		global $wpdb;
		$table = self::ai_hits_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table from $wpdb->prefix; no dynamic values.
		$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY last_seen DESC", ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Record one AI-referral hit (upsert by source key).
	 *
	 * @param string $source Source key.
	 * @param string $url    Landing URL.
	 * @return void
	 */
	public static function record_ai_referral( $source, $url ) {
		global $wpdb;
		$table = self::ai_referrals_table();
		$now   = current_time( 'mysql' );
		$sql   = "INSERT INTO {$table} (source, hits, last_url, first_seen, last_seen) VALUES (%s, 1, %s, %s, %s) ON DUPLICATE KEY UPDATE hits = hits + 1, last_url = VALUES(last_url), last_seen = VALUES(last_seen)";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Table from $wpdb->prefix; all values bound via prepare().
		$wpdb->query( $wpdb->prepare( $sql, $source, $url, $now, $now ) );
	}

	/**
	 * Fetch the AI-referral log, most-recently-seen first.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function ai_referrals() {
		global $wpdb;
		$table = self::ai_referrals_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table from $wpdb->prefix; no dynamic values.
		$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY last_seen DESC", ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}
}
