<?php
/**
 * Performance sync: scheduled (daily) and on-demand.
 *
 * Pulls Search Analytics data and stores it locally. Fetches three dimension
 * sets: by date (trend), by page, and by query. Re-fetches the trailing few
 * days each run to capture late-finalized data.
 *
 * @package Sampoorna\SEO
 */

namespace Sampoorna\SEO\Integrations\GSC;

use Sampoorna\SEO\Core\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles scheduled and on-demand Search Analytics syncing.
 */
class Sync {

	const OPT_LAST_SYNC = 'sampoorna_seo_last_sync';
	const OPT_LAST_LOG  = 'sampoorna_seo_last_sync_log';

	/**
	 * Singleton instance.
	 *
	 * @var Sync|null
	 */
	private static $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return Sync
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register cron and admin-post hooks.
	 */
	private function __construct() {
		// Wrapped in a closure so the action callback returns void; run() keeps its
		// WP_Error|true return for direct callers such as handle_sync_now().
		add_action(
			SAMPOORNA_SEO_CRON_HOOK,
			function () {
				$this->run();
			}
		);
		add_action( 'admin_post_sampoorna_seo_sync_now', array( $this, 'handle_sync_now' ) );
	}

	/**
	 * Manual "Sync now" trigger.
	 */
	public function handle_sync_now() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'sampoorna_seo_sync_now' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'sampoorna-seo' ) );
		}
		$days   = (int) get_option( 'sampoorna_seo_initial_days', 90 );
		$result = $this->run( $days );
		$notice = is_wp_error( $result ) ? 'sync_error' : 'synced';
		wp_safe_redirect( admin_url( 'admin.php?page=sampoorna-seo-dashboard&sampoorna_seo_notice=' . $notice ) );
		exit;
	}

	/**
	 * Execute a sync.
	 *
	 * @param int|null $days Look-back window; null = incremental (last 5 days).
	 * @return true|\WP_Error
	 */
	public function run( $days = null ) {
		$oauth = OAuth::instance();
		if ( ! $oauth->is_connected() ) {
			return new \WP_Error( 'not_connected', 'Not connected to Google Search Console.' );
		}
		$property = $oauth->selected_property();
		if ( '' === $property ) {
			return new \WP_Error( 'no_property', 'No property selected.' );
		}

		// GSC data lags ~2-3 days; never request "today".
		$end    = gmdate( 'Y-m-d', strtotime( '-2 days' ) );
		$window = null === $days ? 5 : max( 1, (int) $days );
		$start  = gmdate( 'Y-m-d', strtotime( "-{$window} days", strtotime( $end ) ) );

		$log = array(
			'start'  => $start,
			'end'    => $end,
			'errors' => array(),
		);

		// 1) Trend (by date). Stored with empty page/query.
		$by_date = Api::search_analytics( $property, $start, $end, array( 'date' ), 5000 );
		if ( is_wp_error( $by_date ) ) {
			return $this->finish_with_error( $by_date );
		}
		foreach ( $by_date as $row ) {
			Database::upsert_row( $property, $row );
		}
		$log['by_date'] = count( $by_date );

		// 2) By page (date + page) so drops can be computed per URL.
		$by_page = Api::search_analytics( $property, $start, $end, array( 'date', 'page' ), 25000 );
		if ( is_wp_error( $by_page ) ) {
			$log['errors'][] = $by_page->get_error_message();
		} else {
			foreach ( $by_page as $row ) {
				Database::upsert_row( $property, $row );
			}
			$log['by_page'] = count( $by_page );
		}

		// 3) By query (date + query) for the query table.
		$by_query = Api::search_analytics( $property, $start, $end, array( 'date', 'query' ), 25000 );
		if ( is_wp_error( $by_query ) ) {
			$log['errors'][] = $by_query->get_error_message();
		} else {
			foreach ( $by_query as $row ) {
				Database::upsert_row( $property, $row );
			}
			$log['by_query'] = count( $by_query );
		}

		update_option( self::OPT_LAST_SYNC, current_time( 'mysql' ), false );
		update_option( self::OPT_LAST_LOG, $log, false );
		return true;
	}

	/**
	 * Persist an error to the sync log and return the error.
	 *
	 * @param \WP_Error $e The error encountered during sync.
	 * @return \WP_Error The same error, for convenient return chaining.
	 */
	private function finish_with_error( \WP_Error $e ) {
		update_option(
			self::OPT_LAST_LOG,
			array(
				'errors' => array( $e->get_error_message() ),
				'when'   => current_time( 'mysql' ),
			),
			false
		);
		return $e;
	}

	/**
	 * Get the timestamp of the last successful sync.
	 *
	 * @return string Stored timestamp, or empty string if never synced.
	 */
	public static function last_sync() {
		return (string) get_option( self::OPT_LAST_SYNC, '' );
	}

	/**
	 * Get the log from the last sync run.
	 *
	 * @return array Sync log data, or empty array if none.
	 */
	public static function last_log() {
		$log = get_option( self::OPT_LAST_LOG, array() );
		return is_array( $log ) ? $log : array();
	}
}
