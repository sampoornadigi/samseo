<?php
/**
 * Admin menus, settings, and screens.
 *
 * @package Sampoorna\SEO
 */

namespace Sampoorna\SEO\Admin;

use Sampoorna\SEO\Security\Crypto;
use Sampoorna\SEO\Core\Database;
use Sampoorna\SEO\Integrations\GSC\OAuth;
use Sampoorna\SEO\Integrations\GSC\Api;
use Sampoorna\SEO\Integrations\GSC\Sync;
use Sampoorna\SEO\Integrations\GSC\Inspector;
use Sampoorna\SEO\Integrations\GSC\Suggestions;
use Sampoorna\SEO\Integrations\GSC\Reports;
use Sampoorna\SEO\Integrations\GA4\Analytics;
use Sampoorna\SEO\ControlPlane\Keys;
use Sampoorna\SEO\ControlPlane\Handshake;
use Sampoorna\SEO\Ai\AiClient;
use Sampoorna\SEO\Technical\Robots;
use Sampoorna\SEO\Technical\IndexNow;
use Sampoorna\SEO\Technical\IndexingApi;
use Sampoorna\SEO\Schema\Graph;
use Sampoorna\SEO\Schema\LocalBusiness;
use Sampoorna\SEO\Geo\LlmsTxt;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers admin menus, settings handlers, and renders the plugin screens.
 */
class Screens {

	/**
	 * Singleton instance.
	 *
	 * @var Screens|null
	 */
	private static $instance = null;

	/**
	 * Returns the shared singleton instance.
	 *
	 * @return Screens
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Wires up admin hooks.
	 */
	private function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_sampoorna_seo_save_settings', array( $this, 'save_settings' ) );
		add_action( 'admin_post_sampoorna_seo_select_property', array( $this, 'select_property' ) );
		add_action( 'admin_post_sampoorna_seo_save_ga4', array( $this, 'save_ga4' ) );
		add_action( 'admin_post_sampoorna_seo_issue_bulk', array( $this, 'issue_bulk' ) );
		add_action( 'admin_post_sampoorna_seo_save_control_plane', array( $this, 'save_control_plane' ) );
		add_action( 'admin_post_sampoorna_seo_rotate_key', array( $this, 'rotate_key' ) );
		add_action( 'admin_post_sampoorna_seo_announce', array( $this, 'announce_now' ) );
		add_action( 'admin_post_sampoorna_seo_add_redirect', array( $this, 'add_redirect' ) );
		add_action( 'admin_post_sampoorna_seo_redirect_bulk', array( $this, 'redirect_bulk' ) );
		add_action( 'admin_post_sampoorna_seo_not_found_bulk', array( $this, 'not_found_bulk' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
	}

	/**
	 * Registers the top-level menu and submenus.
	 *
	 * @return void
	 */
	public function menu() {
		add_menu_page(
			__( 'Search Console', 'sampoorna-seo' ),
			__( 'Search Console', 'sampoorna-seo' ),
			'manage_options',
			'sampoorna-seo-dashboard',
			array( $this, 'render_dashboard' ),
			'dashicons-chart-area',
			58
		);
		add_submenu_page( 'sampoorna-seo-dashboard', __( 'Dashboard', 'sampoorna-seo' ), __( 'Dashboard', 'sampoorna-seo' ), 'manage_options', 'sampoorna-seo-dashboard', array( $this, 'render_dashboard' ) );
		add_submenu_page( 'sampoorna-seo-dashboard', __( 'Performance', 'sampoorna-seo' ), __( 'Performance', 'sampoorna-seo' ), 'manage_options', 'sampoorna-seo-performance', array( $this, 'render_performance' ) );
		add_submenu_page( 'sampoorna-seo-dashboard', __( 'Issues', 'sampoorna-seo' ), __( 'Issues', 'sampoorna-seo' ), 'manage_options', 'sampoorna-seo-issues', array( $this, 'render_issues' ) );
		add_submenu_page( 'sampoorna-seo-dashboard', __( 'Suggestions', 'sampoorna-seo' ), __( 'Suggestions', 'sampoorna-seo' ), 'manage_options', 'sampoorna-seo-suggestions', array( $this, 'render_suggestions' ) );
		add_submenu_page( 'sampoorna-seo-dashboard', __( 'Redirects', 'sampoorna-seo' ), __( 'Redirects', 'sampoorna-seo' ), 'manage_options', 'sampoorna-seo-redirects', array( $this, 'render_redirects' ) );
		add_submenu_page( 'sampoorna-seo-dashboard', __( '404 Log', 'sampoorna-seo' ), __( '404 Log', 'sampoorna-seo' ), 'manage_options', 'sampoorna-seo-404-log', array( $this, 'render_404_log' ) );
		add_submenu_page( 'sampoorna-seo-dashboard', __( 'Settings', 'sampoorna-seo' ), __( 'Settings', 'sampoorna-seo' ), 'manage_options', 'sampoorna-seo-settings', array( $this, 'render_settings' ) );
	}

	/**
	 * Enqueues admin assets on plugin screens.
	 *
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public function assets( $hook ) {
		if ( false === strpos( $hook, 'sampoorna-seo-' ) ) {
			return;
		}
		// Chart.js for the trend chart (from CDN; bundle locally for production).
		wp_enqueue_script( 'sampoorna-seo-chartjs', 'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js', array(), '4.4.1', true );
	}

	/* ---------- Settings actions ---------- */

	/**
	 * Persists the settings form submitted from the Settings screen.
	 *
	 * @return void
	 */
	public function save_settings() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'sampoorna_seo_save_settings' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'sampoorna-seo' ) );
		}

		$client_id = isset( $_POST['sampoorna_seo_client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['sampoorna_seo_client_id'] ) ) : '';
		update_option( OAuth::OPT_CLIENT_ID, $client_id );

		// Only overwrite the secret if a new value was entered.
		$secret = isset( $_POST['sampoorna_seo_client_secret'] ) ? sanitize_text_field( wp_unslash( $_POST['sampoorna_seo_client_secret'] ) ) : '';
		if ( '' !== $secret ) {
			update_option( OAuth::OPT_SECRET, Crypto::encrypt( $secret ), false );
		}

		$days = isset( $_POST['sampoorna_seo_initial_days'] ) ? absint( $_POST['sampoorna_seo_initial_days'] ) : 90;
		update_option( 'sampoorna_seo_initial_days', max( 1, min( 480, $days ) ) );

		$threshold = isset( $_POST['sampoorna_seo_drop_threshold'] ) ? absint( $_POST['sampoorna_seo_drop_threshold'] ) : 30;
		update_option( 'sampoorna_seo_drop_threshold', max( 1, min( 99, $threshold ) ) );

		// Email digest settings.
		update_option( Reports::OPT_ENABLED, isset( $_POST['sampoorna_seo_digest_enabled'] ) ? 1 : 0 );
		$freq = isset( $_POST['sampoorna_seo_digest_freq'] ) ? sanitize_key( $_POST['sampoorna_seo_digest_freq'] ) : 'weekly';
		update_option( Reports::OPT_FREQ, in_array( $freq, array( 'daily', 'weekly' ), true ) ? $freq : 'weekly' );
		$email = isset( $_POST['sampoorna_seo_digest_email'] ) ? sanitize_email( wp_unslash( $_POST['sampoorna_seo_digest_email'] ) ) : '';
		update_option( Reports::OPT_EMAIL, $email );

		// Apply the schedule to match the saved settings.
		Reports::reschedule();

		// AI layer: only overwrite the key when a new value is entered.
		$ai_key = isset( $_POST['sampoorna_seo_ai_api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['sampoorna_seo_ai_api_key'] ) ) : '';
		if ( '' !== $ai_key ) {
			update_option( AiClient::OPT_API_KEY, Crypto::encrypt( $ai_key ), false );
		}
		$ai_model = isset( $_POST['sampoorna_seo_ai_model'] ) ? sanitize_text_field( wp_unslash( $_POST['sampoorna_seo_ai_model'] ) ) : '';
		if ( in_array( $ai_model, AiClient::allowed_models(), true ) ) {
			update_option( AiClient::OPT_MODEL, $ai_model, false );
		}

		// Technical SEO: custom robots.txt body + IndexNow toggle.
		$robots = isset( $_POST['sampoorna_seo_robots_txt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['sampoorna_seo_robots_txt'] ) ) : '';
		update_option( Robots::OPT_BODY, $robots );
		update_option( IndexNow::OPT_ENABLED, isset( $_POST['sampoorna_seo_indexnow_enabled'] ) ? 1 : 0 );
		IndexNow::ensure_key();

		update_option( IndexingApi::OPT_ENABLED, isset( $_POST['sampoorna_seo_gindexing_enabled'] ) ? 1 : 0 );
		$gsa = isset( $_POST['sampoorna_seo_gindexing_sa'] ) ? trim( sanitize_textarea_field( wp_unslash( $_POST['sampoorna_seo_gindexing_sa'] ) ) ) : '';
		// Only replace when fresh, valid JSON is pasted; the masked placeholder is not valid JSON, so it is ignored.
		if ( '' !== $gsa && is_array( json_decode( $gsa, true ) ) ) {
			update_option( IndexingApi::OPT_KEY, Crypto::encrypt( $gsa ), false );
		}

		// GEO / AI visibility: llms.txt enable + intro.
		update_option( LlmsTxt::OPT_ENABLED, isset( $_POST['sampoorna_seo_llms_enabled'] ) ? 1 : 0 );
		$llms_intro = isset( $_POST['sampoorna_seo_llms_intro'] ) ? sanitize_textarea_field( wp_unslash( $_POST['sampoorna_seo_llms_intro'] ) ) : '';
		update_option( LlmsTxt::OPT_INTRO, $llms_intro );

		// Schema / Organization.
		$org_name = isset( $_POST['sampoorna_seo_org_name'] ) ? sanitize_text_field( wp_unslash( $_POST['sampoorna_seo_org_name'] ) ) : '';
		update_option( Graph::OPT_ORG_NAME, $org_name );
		$org_logo = isset( $_POST['sampoorna_seo_org_logo'] ) ? esc_url_raw( wp_unslash( $_POST['sampoorna_seo_org_logo'] ) ) : '';
		update_option( Graph::OPT_ORG_LOGO, $org_logo );
		$social_raw = isset( $_POST['sampoorna_seo_social'] ) ? sanitize_textarea_field( wp_unslash( $_POST['sampoorna_seo_social'] ) ) : '';
		$social     = array();
		foreach ( preg_split( '/\r\n|\r|\n/', $social_raw ) as $line ) {
			$line = esc_url_raw( trim( $line ) );
			if ( '' !== $line ) {
				$social[] = $line;
			}
		}
		update_option( Graph::OPT_SOCIAL, $social );

		// Schema / LocalBusiness (single location).
		$local_in = isset( $_POST['sampoorna_seo_local'] ) && is_array( $_POST['sampoorna_seo_local'] )
			? wp_unslash( $_POST['sampoorna_seo_local'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each field is sanitized individually below.
			: array();
		$type     = isset( $local_in['type'] ) ? sanitize_text_field( $local_in['type'] ) : '';
		$local    = array(
			'type'        => array_key_exists( $type, LocalBusiness::types() ) ? $type : '',
			'street'      => isset( $local_in['street'] ) ? sanitize_text_field( $local_in['street'] ) : '',
			'locality'    => isset( $local_in['locality'] ) ? sanitize_text_field( $local_in['locality'] ) : '',
			'region'      => isset( $local_in['region'] ) ? sanitize_text_field( $local_in['region'] ) : '',
			'postal'      => isset( $local_in['postal'] ) ? sanitize_text_field( $local_in['postal'] ) : '',
			'country'     => isset( $local_in['country'] ) ? sanitize_text_field( $local_in['country'] ) : '',
			'telephone'   => isset( $local_in['telephone'] ) ? sanitize_text_field( $local_in['telephone'] ) : '',
			'lat'         => isset( $local_in['lat'] ) && '' !== trim( (string) $local_in['lat'] ) ? (string) (float) $local_in['lat'] : '',
			'lng'         => isset( $local_in['lng'] ) && '' !== trim( (string) $local_in['lng'] ) ? (string) (float) $local_in['lng'] : '',
			'price_range' => isset( $local_in['price_range'] ) ? sanitize_text_field( $local_in['price_range'] ) : '',
		);
		// Persist only when at least one field is filled; otherwise clear it.
		update_option( LocalBusiness::OPT_LOCAL, '' === implode( '', $local ) ? array() : $local );

		wp_safe_redirect( admin_url( 'admin.php?page=sampoorna-seo-settings&sampoorna_seo_notice=saved' ) );
		exit;
	}

	/**
	 * Stores the selected Search Console property.
	 *
	 * @return void
	 */
	public function select_property() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'sampoorna_seo_select_property' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'sampoorna-seo' ) );
		}
		$property = isset( $_POST['sampoorna_seo_property'] ) ? esc_url_raw( wp_unslash( $_POST['sampoorna_seo_property'] ) ) : '';
		update_option( OAuth::OPT_PROPERTY, $property );
		wp_safe_redirect( admin_url( 'admin.php?page=sampoorna-seo-settings&sampoorna_seo_notice=property_saved' ) );
		exit;
	}

	/**
	 * Saves the GA4 numeric property id.
	 *
	 * @return void
	 */
	public function save_ga4() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'sampoorna_seo_save_ga4' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'sampoorna-seo' ) );
		}
		$raw = isset( $_POST['sampoorna_seo_ga4_property'] ) ? sanitize_text_field( wp_unslash( $_POST['sampoorna_seo_ga4_property'] ) ) : '';
		update_option( Analytics::OPT_PROPERTY, preg_replace( '/\D+/', '', $raw ) );
		wp_safe_redirect( admin_url( 'admin.php?page=sampoorna-seo-settings&sampoorna_seo_notice=ga4_saved' ) );
		exit;
	}

	/**
	 * Applies a bulk status change to selected issues.
	 *
	 * @return void
	 */
	public function issue_bulk() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'sampoorna_seo_issue_bulk' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'sampoorna-seo' ) );
		}
		$ids    = isset( $_POST['issue'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['issue'] ) ) : array();
		$action = isset( $_POST['bulk_action'] ) ? sanitize_key( $_POST['bulk_action'] ) : '';
		if ( $ids && in_array( $action, array( 'ignore', 'resolve', 'reopen' ), true ) ) {
			$map = array(
				'ignore'  => 'ignored',
				'resolve' => 'resolved',
				'reopen'  => 'open',
			);
			Database::set_issue_status( $ids, $map[ $action ] );
		}
		$status = isset( $_POST['cur_status'] ) ? sanitize_key( $_POST['cur_status'] ) : 'open';
		wp_safe_redirect( admin_url( 'admin.php?page=sampoorna-seo-issues&status=' . $status . '&sampoorna_seo_notice=issues_updated' ) );
		exit;
	}

	/**
	 * Saves the control-plane base URL.
	 *
	 * @return void
	 */
	public function save_control_plane() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'sampoorna_seo_save_control_plane' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'sampoorna-seo' ) );
		}
		$url = isset( $_POST['sampoorna_seo_cp_url'] ) ? esc_url_raw( wp_unslash( $_POST['sampoorna_seo_cp_url'] ) ) : '';
		Keys::set_plane_url( $url );
		wp_safe_redirect( admin_url( 'admin.php?page=sampoorna-seo-settings&sampoorna_seo_notice=cp_saved' ) );
		exit;
	}

	/**
	 * Rotates the per-site control-plane HMAC key.
	 *
	 * @return void
	 */
	public function rotate_key() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'sampoorna_seo_rotate_key' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'sampoorna-seo' ) );
		}
		Keys::rotate();
		wp_safe_redirect( admin_url( 'admin.php?page=sampoorna-seo-settings&sampoorna_seo_notice=key_rotated' ) );
		exit;
	}

	/**
	 * Announces this site to the control plane (signed site->plane handshake).
	 *
	 * @return void
	 */
	public function announce_now() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'sampoorna_seo_announce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'sampoorna-seo' ) );
		}
		$result = Handshake::instance()->announce();
		if ( null === $result ) {
			$notice = 'cp_announce_unconfigured';
		} elseif ( is_wp_error( $result ) ) {
			$notice = 'cp_announce_failed';
		} else {
			$code   = (int) wp_remote_retrieve_response_code( $result );
			$notice = ( $code >= 200 && $code < 300 ) ? 'cp_announced' : 'cp_announce_failed';
		}
		wp_safe_redirect( admin_url( 'admin.php?page=sampoorna-seo-settings&sampoorna_seo_notice=' . $notice ) );
		exit;
	}

	/**
	 * Allowed redirect status codes.
	 *
	 * @return int[]
	 */
	private static function redirect_types() {
		return array( 301, 302, 307, 410 );
	}

	/**
	 * Adds a redirect from the Redirects screen form.
	 *
	 * @return void
	 */
	public function add_redirect() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'sampoorna_seo_add_redirect' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'sampoorna-seo' ) );
		}
		$source   = isset( $_POST['source'] ) ? sanitize_text_field( wp_unslash( $_POST['source'] ) ) : '';
		$type     = isset( $_POST['type'] ) ? absint( $_POST['type'] ) : 301;
		$is_regex = isset( $_POST['is_regex'] ) ? 1 : 0;
		$target   = isset( $_POST['target'] ) ? sanitize_text_field( wp_unslash( $_POST['target'] ) ) : '';

		if ( ! in_array( $type, self::redirect_types(), true ) ) {
			$type = 301;
		}
		// 410 needs no target; others require both.
		if ( '' === $source || ( 410 !== $type && '' === $target ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=sampoorna-seo-redirects&sampoorna_seo_notice=redirect_invalid' ) );
			exit;
		}
		Database::insert_redirect(
			array(
				'source'   => $source,
				'target'   => 410 === $type ? '' : $target,
				'type'     => $type,
				'is_regex' => $is_regex,
			)
		);
		wp_safe_redirect( admin_url( 'admin.php?page=sampoorna-seo-redirects&sampoorna_seo_notice=redirect_added' ) );
		exit;
	}

	/**
	 * Applies a bulk action to selected redirects.
	 *
	 * @return void
	 */
	public function redirect_bulk() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'sampoorna_seo_redirect_bulk' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'sampoorna-seo' ) );
		}
		$ids    = isset( $_POST['redirect'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['redirect'] ) ) : array();
		$action = isset( $_POST['bulk_action'] ) ? sanitize_key( $_POST['bulk_action'] ) : '';
		if ( $ids ) {
			if ( 'delete' === $action ) {
				Database::delete_redirects( $ids );
			} elseif ( 'enable' === $action ) {
				Database::set_redirect_status( $ids, 'active' );
			} elseif ( 'disable' === $action ) {
				Database::set_redirect_status( $ids, 'disabled' );
			}
		}
		wp_safe_redirect( admin_url( 'admin.php?page=sampoorna-seo-redirects&sampoorna_seo_notice=redirects_updated' ) );
		exit;
	}

	/**
	 * Applies a bulk action to selected 404-log rows.
	 *
	 * @return void
	 */
	public function not_found_bulk() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'sampoorna_seo_not_found_bulk' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'sampoorna-seo' ) );
		}
		$ids    = isset( $_POST['row'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['row'] ) ) : array();
		$action = isset( $_POST['bulk_action'] ) ? sanitize_key( $_POST['bulk_action'] ) : '';
		if ( $ids ) {
			if ( 'delete' === $action ) {
				Database::delete_not_found( $ids );
			} elseif ( 'ignore' === $action ) {
				Database::set_not_found_status( $ids, 'ignored' );
			}
		}
		wp_safe_redirect( admin_url( 'admin.php?page=sampoorna-seo-404-log&sampoorna_seo_notice=not_found_updated' ) );
		exit;
	}

	/* ---------- Shared bits ---------- */

	/**
	 * Renders a dismissible admin notice based on the sampoorna_seo_notice query flag.
	 *
	 * @return void
	 */
	private function notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display flag set on redirect, no state change.
		$key = isset( $_GET['sampoorna_seo_notice'] ) ? sanitize_key( $_GET['sampoorna_seo_notice'] ) : '';
		if ( '' === $key ) {
			return;
		}
		$map = array(
			'connected'                => array( 'success', __( 'Connected to Google Search Console.', 'sampoorna-seo' ) ),
			'disconnected'             => array( 'success', __( 'Disconnected. Stored tokens removed.', 'sampoorna-seo' ) ),
			'saved'                    => array( 'success', __( 'Settings saved.', 'sampoorna-seo' ) ),
			'property_saved'           => array( 'success', __( 'Property selected.', 'sampoorna-seo' ) ),
			'synced'                   => array( 'success', __( 'Performance data synced.', 'sampoorna-seo' ) ),
			'seeded'                   => array( 'success', __( 'Queue populated. Inspection runs in the background under Google\'s daily limit.', 'sampoorna-seo' ) ),
			'inspected'                => array( 'success', __( 'Processed a batch of URL inspections.', 'sampoorna-seo' ) ),
			'issues_updated'           => array( 'success', __( 'Issues updated.', 'sampoorna-seo' ) ),
			'suggestions_generated'    => array( 'success', __( 'Suggestions generated.', 'sampoorna-seo' ) ),
			'suggestions_updated'      => array( 'success', __( 'Suggestions updated.', 'sampoorna-seo' ) ),
			'digest_sent'              => array( 'success', __( 'Test digest sent.', 'sampoorna-seo' ) ),
			'cp_saved'                 => array( 'success', __( 'Control-plane URL saved.', 'sampoorna-seo' ) ),
			'key_rotated'              => array( 'success', __( 'A new site key was generated. Re-register its key ID with the control plane.', 'sampoorna-seo' ) ),
			'cp_announced'             => array( 'success', __( 'Announced this site to the control plane.', 'sampoorna-seo' ) ),
			'cp_announce_failed'       => array( 'error', __( 'Announcement failed. Check the control-plane URL and that the site is enrolled.', 'sampoorna-seo' ) ),
			'cp_announce_unconfigured' => array( 'error', __( 'Set a control-plane URL and generate a key before announcing.', 'sampoorna-seo' ) ),
			'redirect_added'           => array( 'success', __( 'Redirect added.', 'sampoorna-seo' ) ),
			'redirect_invalid'         => array( 'error', __( 'Enter a source path and (for 301/302/307) a target URL.', 'sampoorna-seo' ) ),
			'redirects_updated'        => array( 'success', __( 'Redirects updated.', 'sampoorna-seo' ) ),
			'not_found_updated'        => array( 'success', __( '404 log updated.', 'sampoorna-seo' ) ),
			'digest_failed'            => array( 'error', __( 'Could not send the digest. Check the recipient address and that the site can send email.', 'sampoorna-seo' ) ),
			'missing_credentials'      => array( 'error', __( 'Enter your Client ID and Secret first.', 'sampoorna-seo' ) ),
			'bad_state'                => array( 'error', __( 'Security check failed (state mismatch). Try again.', 'sampoorna-seo' ) ),
			'denied'                   => array( 'error', __( 'Authorization was denied.', 'sampoorna-seo' ) ),
			'no_code'                  => array( 'error', __( 'No authorization code returned by Google.', 'sampoorna-seo' ) ),
			'token_error'              => array( 'error', __( 'Failed to exchange the authorization code. Check the redirect URI matches Google Cloud exactly.', 'sampoorna-seo' ) ),
			'sync_error'               => array( 'error', __( 'Sync failed. See the log on the dashboard.', 'sampoorna-seo' ) ),
		);
		if ( ! isset( $map[ $key ] ) ) {
			return;
		}
		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $map[ $key ][0] ),
			esc_html( $map[ $key ][1] )
		);
	}

	/**
	 * Builds an escaped admin-post.php action URL.
	 *
	 * @param string $action The admin-post action name.
	 * @return string Escaped URL.
	 */
	private function action_url( $action ) {
		return esc_url( admin_url( 'admin-post.php?action=' . $action ) );
	}

	/**
	 * Builds an escaped, nonce-protected CSV export URL.
	 *
	 * @param string $dataset The dataset identifier to export.
	 * @return string Escaped URL.
	 */
	private function export_url( $dataset ) {
		return esc_url(
			wp_nonce_url(
				admin_url( 'admin-post.php?action=sampoorna_seo_export&dataset=' . $dataset ),
				'sampoorna_seo_export'
			)
		);
	}

	/* ---------- Screens ---------- */

	/**
	 * Renders the Redirects screen (add form + list with bulk actions).
	 *
	 * @return void
	 */
	public function render_redirects() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only prefill of the source field from a 404-log link.
		$prefill   = isset( $_GET['source'] ) ? sanitize_text_field( wp_unslash( $_GET['source'] ) ) : '';
		$redirects = Database::get_redirects( array( 'status' => 'all' ) );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Redirects', 'sampoorna-seo' ); ?></h1>
			<?php $this->notice(); ?>

			<h2><?php esc_html_e( 'Add redirect', 'sampoorna-seo' ); ?></h2>
			<form method="post" action="<?php echo $this->action_url( 'sampoorna_seo_add_redirect' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- action_url() returns an esc_url()-escaped string. ?>">
				<?php wp_nonce_field( 'sampoorna_seo_add_redirect' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="sseo-r-source"><?php esc_html_e( 'Source path', 'sampoorna-seo' ); ?></label></th>
						<td><input name="source" id="sseo-r-source" type="text" class="regular-text" value="<?php echo esc_attr( $prefill ); ?>" placeholder="/old-page/"></td>
					</tr>
					<tr>
						<th scope="row"><label for="sseo-r-target"><?php esc_html_e( 'Target URL', 'sampoorna-seo' ); ?></label></th>
						<td><input name="target" id="sseo-r-target" type="text" class="regular-text" placeholder="/new-page/"> <span class="description"><?php esc_html_e( '(not needed for 410 Gone)', 'sampoorna-seo' ); ?></span></td>
					</tr>
					<tr>
						<th scope="row"><label for="sseo-r-type"><?php esc_html_e( 'Type', 'sampoorna-seo' ); ?></label></th>
						<td>
							<select name="type" id="sseo-r-type">
								<option value="301"><?php esc_html_e( '301 Moved Permanently', 'sampoorna-seo' ); ?></option>
								<option value="302"><?php esc_html_e( '302 Found (temporary)', 'sampoorna-seo' ); ?></option>
								<option value="307"><?php esc_html_e( '307 Temporary Redirect', 'sampoorna-seo' ); ?></option>
								<option value="410"><?php esc_html_e( '410 Gone', 'sampoorna-seo' ); ?></option>
							</select>
							<label style="margin-left:12px;"><input type="checkbox" name="is_regex" value="1"> <?php esc_html_e( 'Source is a regular expression (target may use $1…)', 'sampoorna-seo' ); ?></label>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Add redirect', 'sampoorna-seo' ), 'secondary', '', false ); ?>
			</form>

			<h2><?php esc_html_e( 'Existing redirects', 'sampoorna-seo' ); ?></h2>
			<form method="post" action="<?php echo $this->action_url( 'sampoorna_seo_redirect_bulk' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- action_url() returns an esc_url()-escaped string. ?>">
				<?php wp_nonce_field( 'sampoorna_seo_redirect_bulk' ); ?>
				<div class="tablenav top">
					<select name="bulk_action">
						<option value=""><?php esc_html_e( 'Bulk actions', 'sampoorna-seo' ); ?></option>
						<option value="enable"><?php esc_html_e( 'Enable', 'sampoorna-seo' ); ?></option>
						<option value="disable"><?php esc_html_e( 'Disable', 'sampoorna-seo' ); ?></option>
						<option value="delete"><?php esc_html_e( 'Delete', 'sampoorna-seo' ); ?></option>
					</select>
					<button class="button action"><?php esc_html_e( 'Apply', 'sampoorna-seo' ); ?></button>
				</div>
				<table class="widefat striped">
					<thead><tr>
						<td class="check-column"><input type="checkbox" onclick="jQuery('.sseo-rcb').prop('checked',this.checked);"></td>
						<th><?php esc_html_e( 'Source', 'sampoorna-seo' ); ?></th>
						<th><?php esc_html_e( 'Target', 'sampoorna-seo' ); ?></th>
						<th><?php esc_html_e( 'Type', 'sampoorna-seo' ); ?></th>
						<th><?php esc_html_e( 'Status', 'sampoorna-seo' ); ?></th>
						<th><?php esc_html_e( 'Hits', 'sampoorna-seo' ); ?></th>
					</tr></thead>
					<tbody>
					<?php if ( empty( $redirects ) ) : ?>
						<tr><td colspan="6"><?php esc_html_e( 'No redirects yet.', 'sampoorna-seo' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $redirects as $r ) : ?>
							<tr>
								<th class="check-column"><input class="sseo-rcb" type="checkbox" name="redirect[]" value="<?php echo (int) $r['id']; ?>"></th>
								<td><code><?php echo esc_html( $r['source'] ); ?></code><?php echo $r['is_regex'] ? ' <span class="description">(regex)</span>' : ''; ?></td>
								<td><?php echo esc_html( $r['target'] ); ?></td>
								<td><?php echo esc_html( (string) $r['type'] ); ?></td>
								<td><?php echo esc_html( $r['status'] ); ?></td>
								<td><?php echo esc_html( (string) $r['hits'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
					</tbody>
				</table>
			</form>
		</div>
		<?php
	}

	/**
	 * Renders the 404 Log screen (list with bulk actions + create-redirect links).
	 *
	 * @return void
	 */
	public function render_404_log() {
		$rows = Database::get_not_found( array( 'status' => 'all' ) );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( '404 Log', 'sampoorna-seo' ); ?></h1>
			<?php $this->notice(); ?>
			<p class="description"><?php esc_html_e( 'Not-found URLs hit by visitors. Use “Create redirect” to send them somewhere useful.', 'sampoorna-seo' ); ?></p>

			<form method="post" action="<?php echo $this->action_url( 'sampoorna_seo_not_found_bulk' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- action_url() returns an esc_url()-escaped string. ?>">
				<?php wp_nonce_field( 'sampoorna_seo_not_found_bulk' ); ?>
				<div class="tablenav top">
					<select name="bulk_action">
						<option value=""><?php esc_html_e( 'Bulk actions', 'sampoorna-seo' ); ?></option>
						<option value="ignore"><?php esc_html_e( 'Ignore', 'sampoorna-seo' ); ?></option>
						<option value="delete"><?php esc_html_e( 'Delete', 'sampoorna-seo' ); ?></option>
					</select>
					<button class="button action"><?php esc_html_e( 'Apply', 'sampoorna-seo' ); ?></button>
				</div>
				<table class="widefat striped">
					<thead><tr>
						<td class="check-column"><input type="checkbox" onclick="jQuery('.sseo-ncb').prop('checked',this.checked);"></td>
						<th><?php esc_html_e( 'URL', 'sampoorna-seo' ); ?></th>
						<th><?php esc_html_e( 'Hits', 'sampoorna-seo' ); ?></th>
						<th><?php esc_html_e( 'Last seen', 'sampoorna-seo' ); ?></th>
						<th><?php esc_html_e( 'Status', 'sampoorna-seo' ); ?></th>
						<th></th>
					</tr></thead>
					<tbody>
					<?php if ( empty( $rows ) ) : ?>
						<tr><td colspan="6"><?php esc_html_e( 'No 404s logged.', 'sampoorna-seo' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $rows as $row ) : ?>
							<?php $add_url = admin_url( 'admin.php?page=sampoorna-seo-redirects&source=' . rawurlencode( $row['url'] ) ); ?>
							<tr>
								<th class="check-column"><input class="sseo-ncb" type="checkbox" name="row[]" value="<?php echo (int) $row['id']; ?>"></th>
								<td><code><?php echo esc_html( $row['url'] ); ?></code></td>
								<td><?php echo esc_html( (string) $row['hits'] ); ?></td>
								<td><?php echo esc_html( mysql2date( 'Y-m-d H:i', $row['last_seen'] ) ); ?></td>
								<td><?php echo esc_html( $row['status'] ); ?></td>
								<td><a class="button button-small" href="<?php echo esc_url( $add_url ); ?>"><?php esc_html_e( 'Create redirect', 'sampoorna-seo' ); ?></a></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
					</tbody>
				</table>
			</form>
		</div>
		<?php
	}

	/**
	 * Renders the Settings screen.
	 *
	 * @return void
	 */
	public function render_settings() {
		$oauth = OAuth::instance();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Search Console — Settings', 'sampoorna-seo' ); ?></h1>
			<?php $this->notice(); ?>

			<h2><?php esc_html_e( '1. Google API credentials', 'sampoorna-seo' ); ?></h2>
			<p>
				<?php esc_html_e( 'In Google Cloud Console, enable the Search Console API and create an OAuth Web Application client. Set the authorized redirect URI to:', 'sampoorna-seo' ); ?>
			</p>
			<p><code><?php echo esc_html( $oauth->redirect_uri() ); ?></code></p>

			<form method="post" action="<?php echo $this->action_url( 'sampoorna_seo_save_settings' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- action_url() returns an esc_url()-escaped string. ?>">
				<?php wp_nonce_field( 'sampoorna_seo_save_settings' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="sampoorna_seo_client_id"><?php esc_html_e( 'Client ID', 'sampoorna-seo' ); ?></label></th>
						<td><input name="sampoorna_seo_client_id" id="sampoorna_seo_client_id" type="text" class="regular-text" value="<?php echo esc_attr( $oauth->client_id() ); ?>" autocomplete="off"></td>
					</tr>
					<tr>
						<th scope="row"><label for="sampoorna_seo_client_secret"><?php esc_html_e( 'Client Secret', 'sampoorna-seo' ); ?></label></th>
						<td>
							<input name="sampoorna_seo_client_secret" id="sampoorna_seo_client_secret" type="password" class="regular-text" value="" autocomplete="off" placeholder="<?php echo $oauth->client_secret() ? esc_attr__( '•••••••• (stored — leave blank to keep)', 'sampoorna-seo' ) : ''; ?>">
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="sampoorna_seo_initial_days"><?php esc_html_e( 'Initial sync window (days)', 'sampoorna-seo' ); ?></label></th>
						<td><input name="sampoorna_seo_initial_days" id="sampoorna_seo_initial_days" type="number" min="1" max="480" value="<?php echo esc_attr( get_option( 'sampoorna_seo_initial_days', 90 ) ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="sampoorna_seo_drop_threshold"><?php esc_html_e( 'Click-drop alert threshold (%)', 'sampoorna-seo' ); ?></label></th>
						<td><input name="sampoorna_seo_drop_threshold" id="sampoorna_seo_drop_threshold" type="number" min="1" max="99" value="<?php echo esc_attr( get_option( 'sampoorna_seo_drop_threshold', 30 ) ); ?>"></td>
					</tr>
				</table>

				<h2><?php esc_html_e( '4. Email digest', 'sampoorna-seo' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Enable digest', 'sampoorna-seo' ); ?></th>
						<td>
							<label>
								<input name="sampoorna_seo_digest_enabled" type="checkbox" value="1" <?php checked( Reports::is_enabled() ); ?>>
								<?php esc_html_e( 'Email a periodic summary (KPIs, drops, issues, suggestions).', 'sampoorna-seo' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="sampoorna_seo_digest_freq"><?php esc_html_e( 'Frequency', 'sampoorna-seo' ); ?></label></th>
						<td>
							<select name="sampoorna_seo_digest_freq" id="sampoorna_seo_digest_freq">
								<option value="weekly" <?php selected( Reports::frequency(), 'weekly' ); ?>><?php esc_html_e( 'Weekly', 'sampoorna-seo' ); ?></option>
								<option value="daily" <?php selected( Reports::frequency(), 'daily' ); ?>><?php esc_html_e( 'Daily', 'sampoorna-seo' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="sampoorna_seo_digest_email"><?php esc_html_e( 'Recipient', 'sampoorna-seo' ); ?></label></th>
						<td>
							<input name="sampoorna_seo_digest_email" id="sampoorna_seo_digest_email" type="email" class="regular-text" value="<?php echo esc_attr( get_option( Reports::OPT_EMAIL, '' ) ); ?>" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>">
							<p class="description"><?php esc_html_e( 'Leave blank to use the site admin email.', 'sampoorna-seo' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'AI', 'sampoorna-seo' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="sampoorna_seo_ai_api_key"><?php esc_html_e( 'Anthropic API key', 'sampoorna-seo' ); ?></label></th>
						<td>
							<input name="sampoorna_seo_ai_api_key" id="sampoorna_seo_ai_api_key" type="password" class="regular-text" value="" autocomplete="off" placeholder="<?php echo AiClient::is_configured() ? esc_attr__( '•••••••• (stored — leave blank to keep)', 'sampoorna-seo' ) : 'sk-ant-...'; ?>">
							<p class="description"><?php esc_html_e( 'Powers one-click AI title & meta generation in the post editor. Stored encrypted.', 'sampoorna-seo' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="sampoorna_seo_ai_model"><?php esc_html_e( 'Model', 'sampoorna-seo' ); ?></label></th>
						<td>
							<select name="sampoorna_seo_ai_model" id="sampoorna_seo_ai_model">
								<?php foreach ( AiClient::allowed_models() as $model_id ) : ?>
									<option value="<?php echo esc_attr( $model_id ); ?>" <?php selected( AiClient::model(), $model_id ); ?>><?php echo esc_html( $model_id ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Technical SEO', 'sampoorna-seo' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="sampoorna_seo_robots_txt"><?php esc_html_e( 'robots.txt', 'sampoorna-seo' ); ?></label></th>
						<td>
							<textarea name="sampoorna_seo_robots_txt" id="sampoorna_seo_robots_txt" class="large-text code" rows="6"><?php echo esc_textarea( get_option( Robots::OPT_BODY, '' ) ); ?></textarea>
							<p class="description">
								<?php esc_html_e( 'Custom robots.txt rules. Leave blank for the WordPress default. The sitemap line is added automatically.', 'sampoorna-seo' ); ?>
								<a href="<?php echo esc_url( home_url( '/robots.txt' ) ); ?>" target="_blank"><?php esc_html_e( 'View robots.txt', 'sampoorna-seo' ); ?></a>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'IndexNow', 'sampoorna-seo' ); ?></th>
						<td>
							<label>
								<input name="sampoorna_seo_indexnow_enabled" type="checkbox" value="1" <?php checked( IndexNow::enabled() ); ?>>
								<?php esc_html_e( 'Auto-submit new and updated URLs to IndexNow (Bing, Yandex, and others).', 'sampoorna-seo' ); ?>
							</label>
							<?php if ( '' !== IndexNow::key() ) : ?>
								<p class="description">
									<?php esc_html_e( 'Key file:', 'sampoorna-seo' ); ?>
									<a href="<?php echo esc_url( IndexNow::key_file_url() ); ?>" target="_blank"><code><?php echo esc_html( IndexNow::key() . '.txt' ); ?></code></a>
								</p>
							<?php endif; ?>
						</td>
					</tr>
				</table>

				<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Google Indexing API', 'sampoorna-seo' ); ?></th>
							<td>
								<label>
									<input name="sampoorna_seo_gindexing_enabled" type="checkbox" value="1" <?php checked( (bool) get_option( IndexingApi::OPT_ENABLED, false ) ); ?>>
									<?php esc_html_e( 'Enable on-demand URL submission to the Google Indexing API.', 'sampoorna-seo' ); ?>
								</label>
								<p class="description">
									<?php esc_html_e( 'Paste the service-account JSON key (stored encrypted). Google officially supports the Indexing API only for pages with JobPosting or BroadcastEvent structured data; submission is on demand, not automatic.', 'sampoorna-seo' ); ?>
								</p>
								<textarea name="sampoorna_seo_gindexing_sa" rows="4" class="large-text code" placeholder='{ "type": "service_account", "client_email": "…", "private_key": "…" }'><?php echo '' !== Crypto::decrypt( get_option( IndexingApi::OPT_KEY, '' ) ) ? esc_textarea( '••• stored — paste again to replace •••' ) : ''; ?></textarea>
							</td>
						</tr>
					</table>

					<h2><?php esc_html_e( 'GEO / AI visibility', 'sampoorna-seo' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'llms.txt', 'sampoorna-seo' ); ?></th>
						<td>
							<label>
								<input name="sampoorna_seo_llms_enabled" type="checkbox" value="1" <?php checked( LlmsTxt::is_enabled() ); ?>>
								<?php esc_html_e( 'Serve a curated llms.txt / llms-full.txt for AI crawlers and answer engines.', 'sampoorna-seo' ); ?>
							</label>
							<?php if ( LlmsTxt::is_enabled() ) : ?>
								<p class="description">
									<a href="<?php echo esc_url( home_url( '/llms.txt' ) ); ?>" target="_blank"><code>/llms.txt</code></a>
									&middot;
									<a href="<?php echo esc_url( home_url( '/llms-full.txt' ) ); ?>" target="_blank"><code>/llms-full.txt</code></a>
								</p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="sampoorna_seo_llms_intro"><?php esc_html_e( 'llms.txt summary', 'sampoorna-seo' ); ?></label></th>
						<td>
							<textarea name="sampoorna_seo_llms_intro" id="sampoorna_seo_llms_intro" class="large-text" rows="3"><?php echo esc_textarea( get_option( LlmsTxt::OPT_INTRO, '' ) ); ?></textarea>
							<p class="description"><?php esc_html_e( 'One-line summary of the site for the top of llms.txt. Falls back to the site tagline.', 'sampoorna-seo' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Schema / Organization', 'sampoorna-seo' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="sampoorna_seo_org_name"><?php esc_html_e( 'Organization name', 'sampoorna-seo' ); ?></label></th>
						<td><input name="sampoorna_seo_org_name" id="sampoorna_seo_org_name" type="text" class="regular-text" value="<?php echo esc_attr( get_option( Graph::OPT_ORG_NAME, '' ) ); ?>" placeholder="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="sampoorna_seo_org_logo"><?php esc_html_e( 'Logo URL', 'sampoorna-seo' ); ?></label></th>
						<td>
							<input name="sampoorna_seo_org_logo" id="sampoorna_seo_org_logo" type="url" class="regular-text" value="<?php echo esc_attr( get_option( Graph::OPT_ORG_LOGO, '' ) ); ?>" placeholder="<?php echo esc_attr( (string) get_site_icon_url() ); ?>">
							<p class="description"><?php esc_html_e( 'Used in the Organization schema. Falls back to the site logo/icon.', 'sampoorna-seo' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="sampoorna_seo_social"><?php esc_html_e( 'Social profile URLs', 'sampoorna-seo' ); ?></label></th>
						<td>
							<textarea name="sampoorna_seo_social" id="sampoorna_seo_social" class="large-text code" rows="4"><?php echo esc_textarea( implode( "\n", (array) get_option( Graph::OPT_SOCIAL, array() ) ) ); ?></textarea>
							<p class="description"><?php esc_html_e( 'One URL per line (Facebook, X, LinkedIn, …). Emitted as schema sameAs.', 'sampoorna-seo' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Local business (single location)', 'sampoorna-seo' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Optional. Fill these to enrich your Organization schema with NAP, geo, and price-range data (LocalBusiness). Leave blank to skip.', 'sampoorna-seo' ); ?></p>
				<?php $local = (array) get_option( LocalBusiness::OPT_LOCAL, array() ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="sampoorna_seo_local_type"><?php esc_html_e( 'Business type', 'sampoorna-seo' ); ?></label></th>
						<td>
							<select name="sampoorna_seo_local[type]" id="sampoorna_seo_local_type">
								<?php foreach ( LocalBusiness::types() as $value => $label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( isset( $local['type'] ) ? $local['type'] : '', $value ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="sampoorna_seo_local_street"><?php esc_html_e( 'Street address', 'sampoorna-seo' ); ?></label></th>
						<td><input name="sampoorna_seo_local[street]" id="sampoorna_seo_local_street" type="text" class="regular-text" value="<?php echo esc_attr( isset( $local['street'] ) ? $local['street'] : '' ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="sampoorna_seo_local_locality"><?php esc_html_e( 'City / locality', 'sampoorna-seo' ); ?></label></th>
						<td><input name="sampoorna_seo_local[locality]" id="sampoorna_seo_local_locality" type="text" class="regular-text" value="<?php echo esc_attr( isset( $local['locality'] ) ? $local['locality'] : '' ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="sampoorna_seo_local_region"><?php esc_html_e( 'State / region', 'sampoorna-seo' ); ?></label></th>
						<td><input name="sampoorna_seo_local[region]" id="sampoorna_seo_local_region" type="text" class="regular-text" value="<?php echo esc_attr( isset( $local['region'] ) ? $local['region'] : '' ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="sampoorna_seo_local_postal"><?php esc_html_e( 'Postal code', 'sampoorna-seo' ); ?></label></th>
						<td><input name="sampoorna_seo_local[postal]" id="sampoorna_seo_local_postal" type="text" class="regular-text" value="<?php echo esc_attr( isset( $local['postal'] ) ? $local['postal'] : '' ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="sampoorna_seo_local_country"><?php esc_html_e( 'Country code', 'sampoorna-seo' ); ?></label></th>
						<td><input name="sampoorna_seo_local[country]" id="sampoorna_seo_local_country" type="text" class="regular-text" value="<?php echo esc_attr( isset( $local['country'] ) ? $local['country'] : '' ); ?>" placeholder="IN"></td>
					</tr>
					<tr>
						<th scope="row"><label for="sampoorna_seo_local_telephone"><?php esc_html_e( 'Telephone', 'sampoorna-seo' ); ?></label></th>
						<td><input name="sampoorna_seo_local[telephone]" id="sampoorna_seo_local_telephone" type="text" class="regular-text" value="<?php echo esc_attr( isset( $local['telephone'] ) ? $local['telephone'] : '' ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="sampoorna_seo_local_lat"><?php esc_html_e( 'Latitude / longitude', 'sampoorna-seo' ); ?></label></th>
						<td>
							<input name="sampoorna_seo_local[lat]" id="sampoorna_seo_local_lat" type="text" class="small-text" value="<?php echo esc_attr( isset( $local['lat'] ) ? $local['lat'] : '' ); ?>" placeholder="17.385">
							<input name="sampoorna_seo_local[lng]" id="sampoorna_seo_local_lng" type="text" class="small-text" value="<?php echo esc_attr( isset( $local['lng'] ) ? $local['lng'] : '' ); ?>" placeholder="78.486">
							<p class="description"><?php esc_html_e( 'Both required for geo coordinates.', 'sampoorna-seo' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="sampoorna_seo_local_price"><?php esc_html_e( 'Price range', 'sampoorna-seo' ); ?></label></th>
						<td><input name="sampoorna_seo_local[price_range]" id="sampoorna_seo_local_price" type="text" class="regular-text" value="<?php echo esc_attr( isset( $local['price_range'] ) ? $local['price_range'] : '' ); ?>" placeholder="₹₹"></td>
					</tr>
				</table>
				<?php submit_button( __( 'Save settings', 'sampoorna-seo' ) ); ?>
			</form>

			<p>
				<strong><?php esc_html_e( 'Last digest sent:', 'sampoorna-seo' ); ?></strong>
				<?php
				$last_digest = Reports::last_sent();
				echo esc_html( $last_digest ? $last_digest : __( 'never', 'sampoorna-seo' ) );
				?>
				<form method="post" action="<?php echo $this->action_url( 'sampoorna_seo_send_test_digest' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- action_url() returns an esc_url()-escaped string. ?>" style="display:inline;margin-left:8px;">
					<?php wp_nonce_field( 'sampoorna_seo_send_test_digest' ); ?>
					<button class="button"><?php esc_html_e( 'Send test digest now', 'sampoorna-seo' ); ?></button>
				</form>
			</p>

			<hr>
			<h2><?php esc_html_e( '2. Connect your Google account', 'sampoorna-seo' ); ?></h2>
			<?php if ( ! $oauth->is_configured() ) : ?>
				<p><em><?php esc_html_e( 'Enter and save your Client ID and Secret above before connecting.', 'sampoorna-seo' ); ?></em></p>
			<?php elseif ( $oauth->is_connected() ) : ?>
				<p>
					<span class="dashicons dashicons-yes-alt" style="color:#46b450;"></span>
					<?php esc_html_e( 'Connected.', 'sampoorna-seo' ); ?>
				</p>
				<form method="post" action="<?php echo $this->action_url( 'sampoorna_seo_disconnect' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- action_url() returns an esc_url()-escaped string. ?>" style="display:inline;">
					<?php wp_nonce_field( 'sampoorna_seo_disconnect' ); ?>
					<button class="button button-secondary"><?php esc_html_e( 'Disconnect & delete data', 'sampoorna-seo' ); ?></button>
				</form>
			<?php else : ?>
				<form method="post" action="<?php echo $this->action_url( 'sampoorna_seo_connect' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- action_url() returns an esc_url()-escaped string. ?>">
					<?php wp_nonce_field( 'sampoorna_seo_connect' ); ?>
					<button class="button button-primary"><?php esc_html_e( 'Connect Google Search Console', 'sampoorna-seo' ); ?></button>
				</form>
			<?php endif; ?>

			<?php if ( $oauth->is_connected() ) : ?>
				<hr>
				<h2><?php esc_html_e( '3. Choose a property', 'sampoorna-seo' ); ?></h2>
				<?php
				$sites = Api::list_sites();
				if ( is_wp_error( $sites ) ) {
					echo '<p class="notice notice-error" style="padding:8px;">' . esc_html( $sites->get_error_message() ) . '</p>';
				} elseif ( empty( $sites ) ) {
					esc_html_e( 'No verified properties found for this account.', 'sampoorna-seo' );
				} else {
					$selected = $oauth->selected_property();
					?>
					<form method="post" action="<?php echo $this->action_url( 'sampoorna_seo_select_property' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- action_url() returns an esc_url()-escaped string. ?>">
						<?php wp_nonce_field( 'sampoorna_seo_select_property' ); ?>
						<select name="sampoorna_seo_property">
							<?php foreach ( $sites as $site ) : ?>
								<option value="<?php echo esc_attr( $site['url'] ); ?>" <?php selected( $selected, $site['url'] ); ?>>
									<?php echo esc_html( $site['url'] . ' (' . $site['permission'] . ')' ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<?php submit_button( __( 'Use this property', 'sampoorna-seo' ), 'secondary', '', false ); ?>
					</form>
					<?php
				}
			endif;
			?>

			<?php if ( $oauth->is_connected() ) : ?>
				<hr>
				<h2><?php esc_html_e( 'Google Analytics 4', 'sampoorna-seo' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Enter your GA4 numeric property id (Admin → Property settings → Property ID) to show a 28-day traffic summary. Uses the same Google connection above — reconnect once if GA4 reports a scope error.', 'sampoorna-seo' ); ?></p>
				<?php $ga4 = Analytics::instance(); ?>
				<form method="post" action="<?php echo $this->action_url( 'sampoorna_seo_save_ga4' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- action_url() returns an esc_url()-escaped string. ?>">
					<?php wp_nonce_field( 'sampoorna_seo_save_ga4' ); ?>
					<input type="text" name="sampoorna_seo_ga4_property" value="<?php echo esc_attr( $ga4->property() ); ?>" placeholder="123456789" pattern="[0-9]*" class="regular-text">
					<?php submit_button( __( 'Save property', 'sampoorna-seo' ), 'secondary', '', false ); ?>
				</form>
				<?php
				if ( $ga4->is_ready() ) {
					$summary = $ga4->summary( 28 );
					if ( is_wp_error( $summary ) ) {
						echo '<p class="notice notice-error" style="padding:8px;">' . esc_html( $summary->get_error_message() ) . '</p>';
					} else {
						echo '<p><strong>' . esc_html__( 'Last 28 days:', 'sampoorna-seo' ) . '</strong> '
							. esc_html(
								sprintf(
									/* translators: 1: sessions, 2: users, 3: pageviews, 4: conversions. */
									__( '%1$s sessions, %2$s users, %3$s views, %4$s conversions.', 'sampoorna-seo' ),
									number_format_i18n( $summary['sessions'] ),
									number_format_i18n( $summary['users'] ),
									number_format_i18n( $summary['views'] ),
									number_format_i18n( $summary['conversions'] )
								)
							) . '</p>';
					}
				}
				?>
			<?php endif; ?>

			<hr>
			<h2><?php esc_html_e( 'Control plane', 'sampoorna-seo' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Connect this site to the Sampoorna control plane. Requests are authenticated with a per-site HMAC key, stored encrypted at rest.', 'sampoorna-seo' ); ?></p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Site key ID', 'sampoorna-seo' ); ?></th>
					<td>
						<code><?php echo esc_html( '' !== Keys::key_id() ? Keys::key_id() : '—' ); ?></code>
						<?php if ( Keys::is_configured() ) : ?>
							<span class="dashicons dashicons-yes-alt" style="color:#46b450;"></span> <?php esc_html_e( 'Secret configured.', 'sampoorna-seo' ); ?>
						<?php else : ?>
							<em><?php esc_html_e( 'No key generated yet.', 'sampoorna-seo' ); ?></em>
						<?php endif; ?>
					</td>
				</tr>
				<?php if ( Keys::is_configured() ) : ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Shared secret', 'sampoorna-seo' ); ?></th>
						<td>
							<details>
								<summary style="cursor:pointer;"><?php esc_html_e( 'Reveal secret for enrollment', 'sampoorna-seo' ); ?></summary>
								<p>
									<input type="text" class="large-text code" readonly value="<?php echo esc_attr( Keys::secret() ); ?>" onfocus="this.select();" />
								</p>
								<p class="description"><?php esc_html_e( 'Paste this secret and the key ID above into the control plane to enroll this site. Treat it like a password — anyone with it can authenticate as this site.', 'sampoorna-seo' ); ?></p>
							</details>
						</td>
					</tr>
				<?php endif; ?>
				<tr>
					<th scope="row"><label for="sampoorna_seo_cp_url"><?php esc_html_e( 'Control-plane URL', 'sampoorna-seo' ); ?></label></th>
					<td>
						<form method="post" action="<?php echo $this->action_url( 'sampoorna_seo_save_control_plane' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- action_url() returns an esc_url()-escaped string. ?>">
							<?php wp_nonce_field( 'sampoorna_seo_save_control_plane' ); ?>
							<input type="url" id="sampoorna_seo_cp_url" name="sampoorna_seo_cp_url" class="regular-text" value="<?php echo esc_attr( Keys::plane_url() ); ?>" placeholder="https://control.example.com/api" />
							<?php submit_button( __( 'Save URL', 'sampoorna-seo' ), 'secondary', '', false ); ?>
						</form>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Rotate key', 'sampoorna-seo' ); ?></th>
					<td>
						<form method="post" action="<?php echo $this->action_url( 'sampoorna_seo_rotate_key' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- action_url() returns an esc_url()-escaped string. ?>">
							<?php wp_nonce_field( 'sampoorna_seo_rotate_key' ); ?>
							<button class="button button-secondary"><?php esc_html_e( 'Generate a new site key', 'sampoorna-seo' ); ?></button>
							<span class="description"><?php esc_html_e( 'Invalidates the current key.', 'sampoorna-seo' ); ?></span>
						</form>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Announce', 'sampoorna-seo' ); ?></th>
					<td>
						<form method="post" action="<?php echo $this->action_url( 'sampoorna_seo_announce' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- action_url() returns an esc_url()-escaped string. ?>">
							<?php wp_nonce_field( 'sampoorna_seo_announce' ); ?>
							<button class="button button-secondary" <?php disabled( '' === Keys::plane_url() || ! Keys::is_configured() ); ?>><?php esc_html_e( 'Announce this site now', 'sampoorna-seo' ); ?></button>
							<span class="description"><?php esc_html_e( 'Sends a signed descriptor to the control-plane URL.', 'sampoorna-seo' ); ?></span>
						</form>
					</td>
				</tr>
			</table>
		</div>
		<?php
	}

	/**
	 * Renders the Dashboard screen.
	 *
	 * @return void
	 */
	public function render_dashboard() {
		$oauth    = OAuth::instance();
		$property = $oauth->selected_property();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Search Console — Dashboard', 'sampoorna-seo' ); ?></h1>
			<?php $this->notice(); ?>

			<?php
			if ( ! $oauth->is_connected() || '' === $property ) {
				?>
				<p><?php esc_html_e( 'Connect your account and select a property on the Settings screen to begin.', 'sampoorna-seo' ); ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=sampoorna-seo-settings' ) ); ?>"><?php esc_html_e( 'Go to Settings', 'sampoorna-seo' ); ?></a></p>
				<?php
				return;
			}
			?>

			<p>
				<strong><?php esc_html_e( 'Property:', 'sampoorna-seo' ); ?></strong> <code><?php echo esc_html( $property ); ?></code>
				&nbsp;|&nbsp;
				<strong><?php esc_html_e( 'Last sync:', 'sampoorna-seo' ); ?></strong>
				<?php
				$last_sync = Sync::last_sync();
				echo esc_html( $last_sync ? $last_sync : __( 'never', 'sampoorna-seo' ) );
				?>
				&nbsp;
				<form method="post" action="<?php echo $this->action_url( 'sampoorna_seo_sync_now' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- action_url() returns an esc_url()-escaped string. ?>" style="display:inline;">
					<?php wp_nonce_field( 'sampoorna_seo_sync_now' ); ?>
					<button class="button button-primary"><?php esc_html_e( 'Sync now', 'sampoorna-seo' ); ?></button>
				</form>
			</p>

			<?php
			$cmp   = Database::compare_windows( $property, 7 );
			$cur   = $cmp['current'];
			$prev  = $cmp['previous'];
			$delta = function ( $c, $p ) {
				if ( $p <= 0 ) {
					return '';
				}
				$pct   = round( ( ( $c - $p ) / $p ) * 100, 1 );
				$col   = $pct >= 0 ? '#46b450' : '#dc3232';
				$arrow = $pct >= 0 ? '▲' : '▼';
				return ' <span style="color:' . $col . ';font-size:13px;">' . $arrow . ' ' . abs( $pct ) . '%</span>';
			};
		?>
			<h2><?php esc_html_e( 'Last 7 days vs prior 7 days', 'sampoorna-seo' ); ?></h2>
			<div style="display:flex;gap:16px;flex-wrap:wrap;">
				<?php
				$cards = array(
					array( __( 'Clicks', 'sampoorna-seo' ), $cur['clicks'], $delta( $cur['clicks'], $prev['clicks'] ) ),
					array( __( 'Impressions', 'sampoorna-seo' ), $cur['impressions'], $delta( $cur['impressions'], $prev['impressions'] ) ),
					array( __( 'Avg. position', 'sampoorna-seo' ), $cur['position'], '' ),
				);
				foreach ( $cards as $c ) :
					?>
					<div style="background:#fff;border:1px solid #ccd0d4;border-radius:6px;padding:16px 20px;min-width:160px;">
						<div style="color:#646970;font-size:12px;text-transform:uppercase;"><?php echo esc_html( $c[0] ); ?></div>
						<div style="font-size:26px;font-weight:600;"><?php echo esc_html( number_format_i18n( $c[1] ) ); ?><?php echo wp_kses_post( $c[2] ); ?></div>
					</div>
				<?php endforeach; ?>
			</div>

			<h2><?php esc_html_e( 'Clicks & impressions trend', 'sampoorna-seo' ); ?></h2>
			<canvas id="sampoorna-seo-trend" height="90"></canvas>

			<?php
			$threshold = (int) get_option( 'sampoorna_seo_drop_threshold', 30 ) / 100;
			$drops     = Database::click_drops( $property, 7, $threshold );
			?>
			<h2><?php esc_html_e( 'Pages with click drops', 'sampoorna-seo' ); ?></h2>
			<?php if ( empty( $drops ) ) : ?>
				<p><?php esc_html_e( 'No significant drops detected in the latest window.', 'sampoorna-seo' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead><tr>
						<th><?php esc_html_e( 'Page', 'sampoorna-seo' ); ?></th>
						<th><?php esc_html_e( 'Prev clicks', 'sampoorna-seo' ); ?></th>
						<th><?php esc_html_e( 'Current clicks', 'sampoorna-seo' ); ?></th>
						<th><?php esc_html_e( 'Drop', 'sampoorna-seo' ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( array_slice( $drops, 0, 25 ) as $d ) : ?>
						<tr>
							<td><?php echo esc_html( $d['page_url'] ); ?></td>
							<td><?php echo esc_html( $d['prev_clicks'] ); ?></td>
							<td><?php echo esc_html( $d['cur_clicks'] ); ?></td>
							<td style="color:#dc3232;"><?php echo esc_html( $d['drop_pct'] ); ?>%</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<?php
			$series = Database::daily_totals( $property, 90 );
			$labels = wp_list_pluck( $series, 'date' );
			$clicks = array_map( 'intval', wp_list_pluck( $series, 'clicks' ) );
			$imps   = array_map( 'intval', wp_list_pluck( $series, 'impressions' ) );
			?>
			<script>
			document.addEventListener('DOMContentLoaded', function () {
				if (typeof Chart === 'undefined') { return; }
				var ctx = document.getElementById('sampoorna-seo-trend');
				if (!ctx) { return; }
				new Chart(ctx, {
					type: 'line',
					data: {
						labels: <?php echo wp_json_encode( $labels ); ?>,
						datasets: [
							{ label: 'Clicks', data: <?php echo wp_json_encode( $clicks ); ?>, borderColor: '#2271b1', tension: 0.3, yAxisID: 'y' },
							{ label: 'Impressions', data: <?php echo wp_json_encode( $imps ); ?>, borderColor: '#9b59b6', tension: 0.3, yAxisID: 'y1' }
						]
					},
					options: {
						responsive: true,
						interaction: { mode: 'index', intersect: false },
						scales: {
							y:  { type: 'linear', position: 'left',  title: { display: true, text: 'Clicks' } },
							y1: { type: 'linear', position: 'right', title: { display: true, text: 'Impressions' }, grid: { drawOnChartArea: false } }
						}
					}
				});
			});
			</script>
		</div>
		<?php
	}

	/**
	 * Renders the Performance screen.
	 *
	 * @return void
	 */
	public function render_performance() {
		$oauth    = OAuth::instance();
		$property = $oauth->selected_property();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Search Console — Performance', 'sampoorna-seo' ); ?></h1>
			<?php $this->notice(); ?>

			<?php
			if ( ! $oauth->is_connected() || '' === $property ) {
				?>
				<p><?php esc_html_e( 'Connect a property in Settings first.', 'sampoorna-seo' ); ?></p>
				<?php
				return;
			}
			?>

			<?php
			$tabs = array(
				'page'  => __( 'Top pages', 'sampoorna-seo' ),
				'query' => __( 'Top queries', 'sampoorna-seo' ),
			);
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab selector, value compared against a literal; no state change.
			$active = isset( $_GET['dim'] ) && 'query' === $_GET['dim'] ? 'query' : 'page';
			?>
			<h2 class="nav-tab-wrapper">
				<?php foreach ( $tabs as $dim => $label ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=sampoorna-seo-performance&dim=' . $dim ) ); ?>"
						class="nav-tab <?php echo $active === $dim ? 'nav-tab-active' : ''; ?>">
						<?php echo esc_html( $label ); ?>
					</a>
				<?php endforeach; ?>
			</h2>

			<p>
				<a class="button" href="<?php echo $this->export_url( 'page' === $active ? 'perf_pages' : 'perf_queries' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- export_url() returns an esc_url()-escaped string. ?>">
					<?php esc_html_e( 'Export CSV', 'sampoorna-seo' ); ?>
				</a>
			</p>

			<?php
			$rows = Database::top_rows( $property, $active, 28, 200 );
			?>
			<table class="widefat striped">
				<thead><tr>
					<th><?php echo 'query' === $active ? esc_html__( 'Query', 'sampoorna-seo' ) : esc_html__( 'Page', 'sampoorna-seo' ); ?></th>
					<th><?php esc_html_e( 'Clicks', 'sampoorna-seo' ); ?></th>
					<th><?php esc_html_e( 'Impressions', 'sampoorna-seo' ); ?></th>
					<th><?php esc_html_e( 'CTR', 'sampoorna-seo' ); ?></th>
					<th><?php esc_html_e( 'Avg. position', 'sampoorna-seo' ); ?></th>
				</tr></thead>
				<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr><td colspan="5"><?php esc_html_e( 'No data yet. Run a sync from the Dashboard.', 'sampoorna-seo' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $rows as $r ) : ?>
						<tr>
							<td><?php echo esc_html( $r['label'] ); ?></td>
							<td><?php echo esc_html( number_format_i18n( (int) $r['clicks'] ) ); ?></td>
							<td><?php echo esc_html( number_format_i18n( (int) $r['impressions'] ) ); ?></td>
							<td><?php echo esc_html( (string) round( (float) $r['ctr'] * 100, 2 ) ); ?>%</td>
							<td><?php echo esc_html( (string) round( (float) $r['position'], 1 ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>
			<p class="description"><?php esc_html_e( 'Aggregated over the last 28 days of synced data.', 'sampoorna-seo' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Renders the Issues screen.
	 *
	 * @return void
	 */
	public function render_issues() {
		$oauth    = OAuth::instance();
		$property = $oauth->selected_property();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin list filter, no state change.
		$status = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : 'open';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin list filter, no state change.
		$type = isset( $_GET['type'] ) ? sanitize_key( $_GET['type'] ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin list filter, no state change.
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Search Console — Issues', 'sampoorna-seo' ); ?></h1>
			<?php $this->notice(); ?>

			<?php
			if ( ! $oauth->is_connected() || '' === $property ) {
				?>
				<p><?php esc_html_e( 'Connect a property in Settings first.', 'sampoorna-seo' ); ?></p>
				<?php
				return;
			}
			?>

			<?php
			$progress = Inspector::progress();
			$used     = Inspector::daily_used();
			$pct      = $progress['total'] > 0 ? round( ( $progress['done'] / $progress['total'] ) * 100 ) : 0;
			?>
			<div style="background:#fff;border:1px solid #ccd0d4;border-radius:6px;padding:14px 18px;margin:12px 0;">
				<strong><?php esc_html_e( 'Scan progress', 'sampoorna-seo' ); ?>:</strong>
				<?php echo esc_html( sprintf( '%d of %d URLs inspected (%d%%)', $progress['done'], $progress['total'], $pct ) ); ?>
				&nbsp;|&nbsp; <?php echo esc_html( sprintf( 'pending: %d, errors: %d', $progress['pending'], $progress['errors'] ) ); ?>
				&nbsp;|&nbsp; <?php echo esc_html( sprintf( 'inspections used today: %d / %d', $used, Inspector::DAILY_CAP ) ); ?>
				<div style="background:#e2e4e7;border-radius:4px;height:10px;margin-top:8px;overflow:hidden;">
					<div style="background:#2271b1;height:10px;width:<?php echo esc_attr( (string) $pct ); ?>%;"></div>
				</div>
				<p style="margin-top:10px;">
					<form method="post" action="<?php echo $this->action_url( 'sampoorna_seo_seed_queue' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- action_url() returns an esc_url()-escaped string. ?>" style="display:inline;">
						<?php wp_nonce_field( 'sampoorna_seo_seed_queue' ); ?>
						<button class="button"><?php esc_html_e( 'Build / refresh queue', 'sampoorna-seo' ); ?></button>
					</form>
					<form method="post" action="<?php echo $this->action_url( 'sampoorna_seo_inspect_now' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- action_url() returns an esc_url()-escaped string. ?>" style="display:inline;">
						<?php wp_nonce_field( 'sampoorna_seo_inspect_now' ); ?>
						<button class="button button-primary"><?php esc_html_e( 'Inspect a batch now', 'sampoorna-seo' ); ?></button>
					</form>
					<a class="button" href="<?php echo $this->export_url( 'issues' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- export_url() returns an esc_url()-escaped string. ?>"><?php esc_html_e( 'Export CSV', 'sampoorna-seo' ); ?></a>
					<span class="description"><?php esc_html_e( 'Inspection otherwise runs automatically every 15 minutes, throttled under Google\'s 2,000/day limit.', 'sampoorna-seo' ); ?></span>
				</p>
			</div>

			<?php
			$counts = Database::issue_type_counts();
			$labels = array(
				'indexing'  => __( 'Indexing', 'sampoorna-seo' ),
				'canonical' => __( 'Canonical', 'sampoorna-seo' ),
				'mobile'    => __( 'Mobile', 'sampoorna-seo' ),
				'schema'    => __( 'Schema', 'sampoorna-seo' ),
			);
			?>
			<p>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=sampoorna-seo-issues&status=' . $status ) ); ?>" class="button <?php echo '' === $type ? 'button-primary' : ''; ?>">
					<?php esc_html_e( 'All open', 'sampoorna-seo' ); ?> (<?php echo (int) array_sum( $counts ); ?>)
				</a>
				<?php foreach ( $labels as $key => $label ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=sampoorna-seo-issues&status=' . $status . '&type=' . $key ) ); ?>" class="button <?php echo $type === $key ? 'button-primary' : ''; ?>">
						<?php echo esc_html( $label ); ?> (<?php echo (int) ( $counts[ $key ] ?? 0 ); ?>)
					</a>
				<?php endforeach; ?>
			</p>

			<ul class="subsubsub">
				<?php
				$states = array(
					'open'     => __( 'Open', 'sampoorna-seo' ),
					'resolved' => __( 'Resolved', 'sampoorna-seo' ),
					'ignored'  => __( 'Ignored', 'sampoorna-seo' ),
					'all'      => __( 'All', 'sampoorna-seo' ),
				);
				$i      = 0;
				foreach ( $states as $key => $label ) :
					++$i;
					?>
					<li>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=sampoorna-seo-issues&status=' . $key . ( $type ? '&type=' . $type : '' ) ) ); ?>" class="<?php echo $status === $key ? 'current' : ''; ?>"><?php echo esc_html( $label ); ?></a><?php echo $i < count( $states ) ? ' |' : ''; ?>
					</li>
				<?php endforeach; ?>
			</ul>

			<form method="get" style="margin:8px 0;">
				<input type="hidden" name="page" value="sampoorna-seo-issues">
				<input type="hidden" name="status" value="<?php echo esc_attr( $status ); ?>">
				<?php
				if ( $type ) :
					?>
					<input type="hidden" name="type" value="<?php echo esc_attr( $type ); ?>"><?php endif; ?>
				<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Filter by URL…', 'sampoorna-seo' ); ?>">
				<button class="button"><?php esc_html_e( 'Search', 'sampoorna-seo' ); ?></button>
			</form>

			<?php
			$issues = Database::get_issues(
				array(
					'status' => $status,
					'type'   => $type,
					'search' => $search,
					'limit'  => 200,
				)
			);
			?>
			<form method="post" action="<?php echo $this->action_url( 'sampoorna_seo_issue_bulk' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- action_url() returns an esc_url()-escaped string. ?>">
				<?php wp_nonce_field( 'sampoorna_seo_issue_bulk' ); ?>
				<input type="hidden" name="cur_status" value="<?php echo esc_attr( $status ); ?>">
				<div class="tablenav top">
					<select name="bulk_action">
						<option value=""><?php esc_html_e( 'Bulk actions', 'sampoorna-seo' ); ?></option>
						<option value="ignore"><?php esc_html_e( 'Ignore', 'sampoorna-seo' ); ?></option>
						<option value="resolve"><?php esc_html_e( 'Mark resolved', 'sampoorna-seo' ); ?></option>
						<option value="reopen"><?php esc_html_e( 'Reopen', 'sampoorna-seo' ); ?></option>
					</select>
					<button class="button action"><?php esc_html_e( 'Apply', 'sampoorna-seo' ); ?></button>
				</div>
				<table class="widefat striped">
					<thead><tr>
						<td class="check-column"><input type="checkbox" onclick="jQuery('.sampoorna-seo-cb').prop('checked',this.checked);"></td>
						<th><?php esc_html_e( 'Type', 'sampoorna-seo' ); ?></th>
						<th><?php esc_html_e( 'Severity', 'sampoorna-seo' ); ?></th>
						<th><?php esc_html_e( 'URL', 'sampoorna-seo' ); ?></th>
						<th><?php esc_html_e( 'Summary', 'sampoorna-seo' ); ?></th>
						<th><?php esc_html_e( 'Detected', 'sampoorna-seo' ); ?></th>
					</tr></thead>
					<tbody>
					<?php if ( empty( $issues ) ) : ?>
						<tr><td colspan="6"><?php esc_html_e( 'No issues for this filter. If you just installed, build the queue and let inspection run.', 'sampoorna-seo' ); ?></td></tr>
					<?php else : ?>
						<?php
						foreach ( $issues as $iss ) :
							$details = json_decode( (string) $iss['details_json'], true );
							$sev_col = 'error' === $iss['severity'] ? '#dc3232' : ( 'notice' === $iss['severity'] ? '#646970' : '#dba617' );
							?>
							<tr>
								<th class="check-column"><input class="sampoorna-seo-cb" type="checkbox" name="issue[]" value="<?php echo (int) $iss['id']; ?>"></th>
								<td><?php echo esc_html( $labels[ $iss['type'] ] ?? $iss['type'] ); ?></td>
								<td><span style="color:<?php echo esc_attr( $sev_col ); ?>;font-weight:600;"><?php echo esc_html( ucfirst( $iss['severity'] ) ); ?></span></td>
								<td><a href="<?php echo esc_url( $iss['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $iss['url'] ); ?></a></td>
								<td>
									<?php echo esc_html( $iss['summary'] ); ?>
									<?php if ( ! empty( $details ) ) : ?>
										<details style="margin-top:4px;">
											<summary style="cursor:pointer;color:#2271b1;"><?php esc_html_e( 'details', 'sampoorna-seo' ); ?></summary>
											<pre style="white-space:pre-wrap;background:#f6f7f7;padding:8px;border-radius:4px;margin-top:4px;"><?php echo esc_html( wp_json_encode( $details, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ); ?></pre>
										</details>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( mysql2date( 'Y-m-d H:i', $iss['detected_at'] ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
					</tbody>
				</table>
			</form>
			<p class="description"><?php esc_html_e( 'Note: the aggregate Coverage report in the GSC web UI is not available via API; this view is rebuilt URL-by-URL via the URL Inspection API.', 'sampoorna-seo' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Renders the Suggestions screen.
	 *
	 * @return void
	 */
	public function render_suggestions() {
		$oauth    = OAuth::instance();
		$property = $oauth->selected_property();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin list filter, no state change.
		$status = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : 'new';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin list filter, no state change.
		$type = isset( $_GET['type'] ) ? sanitize_key( $_GET['type'] ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin list filter, no state change.
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Search Console — Suggestions', 'sampoorna-seo' ); ?></h1>
			<?php $this->notice(); ?>

			<?php
			if ( ! $oauth->is_connected() || '' === $property ) {
				?>
				<p><?php esc_html_e( 'Connect a property in Settings first.', 'sampoorna-seo' ); ?></p>
				<?php
				return;
			}
			?>

			<div style="background:#fff;border:1px solid #ccd0d4;border-radius:6px;padding:14px 18px;margin:12px 0;">
				<strong><?php esc_html_e( 'Last generated:', 'sampoorna-seo' ); ?></strong>
				<?php
				$last_run = Suggestions::last_run();
				echo esc_html( $last_run ? $last_run : __( 'never', 'sampoorna-seo' ) );
				?>
				<form method="post" action="<?php echo $this->action_url( 'sampoorna_seo_generate_suggestions' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- action_url() returns an esc_url()-escaped string. ?>" style="display:inline;margin-left:8px;">
					<?php wp_nonce_field( 'sampoorna_seo_generate_suggestions' ); ?>
					<button class="button button-primary"><?php esc_html_e( 'Generate suggestions', 'sampoorna-seo' ); ?></button>
				</form>
				<a class="button" href="<?php echo $this->export_url( 'suggestions' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- export_url() returns an esc_url()-escaped string. ?>" style="margin-left:4px;"><?php esc_html_e( 'Export CSV', 'sampoorna-seo' ); ?></a>
				<p class="description" style="margin-top:8px;">
					<?php esc_html_e( 'Suggestions are advisory and built from inspection issues, title/meta length checks, and low-CTR pages. Review and apply changes yourself; nothing is edited automatically.', 'sampoorna-seo' ); ?>
				</p>
			</div>

			<?php
			$counts = Database::suggestion_type_counts();
			$labels = array(
				'title'     => __( 'Title', 'sampoorna-seo' ),
				'meta'      => __( 'Meta description', 'sampoorna-seo' ),
				'ctr'       => __( 'Low CTR', 'sampoorna-seo' ),
				'canonical' => __( 'Canonical', 'sampoorna-seo' ),
				'indexing'  => __( 'Indexing', 'sampoorna-seo' ),
				'mobile'    => __( 'Mobile', 'sampoorna-seo' ),
				'schema'    => __( 'Schema', 'sampoorna-seo' ),
			);
			?>
			<p>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=sampoorna-seo-suggestions&status=' . $status ) ); ?>" class="button <?php echo '' === $type ? 'button-primary' : ''; ?>">
					<?php esc_html_e( 'All', 'sampoorna-seo' ); ?> (<?php echo (int) array_sum( $counts ); ?>)
				</a>
				<?php foreach ( $labels as $key => $label ) : ?>
					<?php if ( isset( $counts[ $key ] ) ) : ?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=sampoorna-seo-suggestions&status=' . $status . '&type=' . $key ) ); ?>" class="button <?php echo $type === $key ? 'button-primary' : ''; ?>">
							<?php echo esc_html( $label ); ?> (<?php echo (int) $counts[ $key ]; ?>)
						</a>
					<?php endif; ?>
				<?php endforeach; ?>
			</p>

			<ul class="subsubsub">
				<?php
				$states = array(
					'new'       => __( 'New', 'sampoorna-seo' ),
					'applied'   => __( 'Applied', 'sampoorna-seo' ),
					'dismissed' => __( 'Dismissed', 'sampoorna-seo' ),
					'all'       => __( 'All', 'sampoorna-seo' ),
				);
				$i      = 0;
				foreach ( $states as $key => $label ) :
					++$i;
					?>
					<li>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=sampoorna-seo-suggestions&status=' . $key . ( $type ? '&type=' . $type : '' ) ) ); ?>" class="<?php echo $status === $key ? 'current' : ''; ?>"><?php echo esc_html( $label ); ?></a><?php echo $i < count( $states ) ? ' |' : ''; ?>
					</li>
				<?php endforeach; ?>
			</ul>

			<form method="get" style="margin:8px 0;">
				<input type="hidden" name="page" value="sampoorna-seo-suggestions">
				<input type="hidden" name="status" value="<?php echo esc_attr( $status ); ?>">
				<?php
				if ( $type ) :
					?>
					<input type="hidden" name="type" value="<?php echo esc_attr( $type ); ?>"><?php endif; ?>
				<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Filter by URL…', 'sampoorna-seo' ); ?>">
				<button class="button"><?php esc_html_e( 'Search', 'sampoorna-seo' ); ?></button>
			</form>

			<?php
			$suggestions = Database::get_suggestions(
				array(
					'status' => $status,
					'type'   => $type,
					'search' => $search,
					'limit'  => 300,
				)
			);
			?>
			<form method="post" action="<?php echo $this->action_url( 'sampoorna_seo_suggestion_bulk' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- action_url() returns an esc_url()-escaped string. ?>">
				<?php wp_nonce_field( 'sampoorna_seo_suggestion_bulk' ); ?>
				<input type="hidden" name="cur_status" value="<?php echo esc_attr( $status ); ?>">
				<div class="tablenav top">
					<select name="bulk_action">
						<option value=""><?php esc_html_e( 'Bulk actions', 'sampoorna-seo' ); ?></option>
						<option value="apply"><?php esc_html_e( 'Mark applied', 'sampoorna-seo' ); ?></option>
						<option value="dismiss"><?php esc_html_e( 'Dismiss', 'sampoorna-seo' ); ?></option>
						<option value="reset"><?php esc_html_e( 'Reset to new', 'sampoorna-seo' ); ?></option>
					</select>
					<button class="button action"><?php esc_html_e( 'Apply', 'sampoorna-seo' ); ?></button>
				</div>
				<table class="widefat striped">
					<thead><tr>
						<td class="check-column"><input type="checkbox" onclick="jQuery('.sampoorna-seo-scb').prop('checked',this.checked);"></td>
						<th><?php esc_html_e( 'Type', 'sampoorna-seo' ); ?></th>
						<th><?php esc_html_e( 'Priority', 'sampoorna-seo' ); ?></th>
						<th><?php esc_html_e( 'Page', 'sampoorna-seo' ); ?></th>
						<th><?php esc_html_e( 'Current', 'sampoorna-seo' ); ?></th>
						<th><?php esc_html_e( 'Suggested', 'sampoorna-seo' ); ?></th>
						<th><?php esc_html_e( 'Recommendation', 'sampoorna-seo' ); ?></th>
					</tr></thead>
					<tbody>
					<?php if ( empty( $suggestions ) ) : ?>
						<tr><td colspan="7"><?php esc_html_e( 'No suggestions for this filter. Click "Generate suggestions" above.', 'sampoorna-seo' ); ?></td></tr>
					<?php else : ?>
						<?php
						foreach ( $suggestions as $s ) :
							$pri_col = 'high' === $s['priority'] ? '#dc3232' : ( 'low' === $s['priority'] ? '#646970' : '#dba617' );
							$edit    = $s['post_id'] ? get_edit_post_link( (int) $s['post_id'] ) : '';
							?>
							<tr>
								<th class="check-column"><input class="sampoorna-seo-scb" type="checkbox" name="sugg[]" value="<?php echo (int) $s['id']; ?>"></th>
								<td><?php echo esc_html( $labels[ $s['type'] ] ?? $s['type'] ); ?></td>
								<td><span style="color:<?php echo esc_attr( $pri_col ); ?>;font-weight:600;"><?php echo esc_html( ucfirst( $s['priority'] ) ); ?></span></td>
								<td>
									<a href="<?php echo esc_url( $s['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $s['url'] ); ?></a>
									<?php if ( $edit ) : ?>
										<br><a href="<?php echo esc_url( $edit ); ?>"><?php esc_html_e( 'Edit', 'sampoorna-seo' ); ?></a>
									<?php endif; ?>
								</td>
								<td><?php echo '' !== $s['current_value'] ? esc_html( $s['current_value'] ) : '<em>—</em>'; ?></td>
								<td><?php echo '' !== $s['suggested_value'] ? esc_html( $s['suggested_value'] ) : '<em>—</em>'; ?></td>
								<td><?php echo esc_html( $s['recommendation'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
					</tbody>
				</table>
			</form>
		</div>
		<?php
	}
}
