<?php
/**
 * OAuth 2.0 connection handling for Google Search Console.
 *
 * Uses the WordPress HTTP API directly against Google's OAuth endpoints — no
 * external Composer dependency required.
 *
 * @package Sampoorna\SEO
 */

namespace Sampoorna\SEO\Integrations\GSC;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Sampoorna\SEO\Security\Crypto;

/**
 * Manages the Google OAuth 2.0 connection lifecycle for Search Console.
 */
class OAuth {

	const AUTH_ENDPOINT  = 'https://accounts.google.com/o/oauth2/v2/auth';
	const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';
	// One consent grants both Search Console and GA4 (read-only). Existing
	// connections must reconnect once to add the analytics scope.
	const SCOPE = 'https://www.googleapis.com/auth/webmasters.readonly https://www.googleapis.com/auth/analytics.readonly';

	const OPT_CLIENT_ID = 'sampoorna_seo_client_id';
	const OPT_SECRET    = 'sampoorna_seo_client_secret'; // Stored encrypted.
	const OPT_TOKEN     = 'sampoorna_seo_token';         // Stored encrypted JSON.
	const OPT_PROPERTY  = 'sampoorna_seo_property';

	/**
	 * Singleton instance.
	 *
	 * @var OAuth|null
	 */
	private static $instance = null;

	/**
	 * Retrieve the shared singleton instance.
	 *
	 * @return OAuth
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register the admin-post handlers for the OAuth flow.
	 */
	private function __construct() {
		add_action( 'admin_post_sampoorna_seo_connect', array( $this, 'handle_connect' ) );
		add_action( 'admin_post_sampoorna_seo_oauth_callback', array( $this, 'handle_callback' ) );
		add_action( 'admin_post_sampoorna_seo_disconnect', array( $this, 'handle_disconnect' ) );
	}

	/* ---------- Credential accessors ---------- */

	/**
	 * Get the configured Google OAuth client ID.
	 *
	 * @return string
	 */
	public function client_id() {
		return (string) get_option( self::OPT_CLIENT_ID, '' );
	}

	/**
	 * Get the decrypted Google OAuth client secret.
	 *
	 * @return string
	 */
	public function client_secret() {
		return Crypto::decrypt( get_option( self::OPT_SECRET, '' ) );
	}

	/**
	 * Build the OAuth redirect URI handled by this plugin.
	 *
	 * @return string
	 */
	public function redirect_uri() {
		return admin_url( 'admin-post.php?action=sampoorna_seo_oauth_callback' );
	}

	/**
	 * Whether both client ID and secret are present.
	 *
	 * @return bool
	 */
	public function is_configured() {
		return '' !== $this->client_id() && '' !== $this->client_secret();
	}

	/**
	 * Whether a refresh token is stored (i.e. the account is connected).
	 *
	 * @return bool
	 */
	public function is_connected() {
		$token = $this->get_token();
		return ! empty( $token['refresh_token'] );
	}

	/**
	 * Get the selected Search Console property.
	 *
	 * @return string
	 */
	public function selected_property() {
		return (string) get_option( self::OPT_PROPERTY, '' );
	}

	/* ---------- Connect flow ---------- */

	/**
	 * Redirect the admin to Google's consent screen.
	 */
	public function handle_connect() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'sampoorna_seo_connect' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'sampoorna-seo' ) );
		}
		if ( ! $this->is_configured() ) {
			$this->redirect_settings( 'missing_credentials' );
		}

		$state = wp_create_nonce( 'sampoorna_seo_oauth_state' );
		set_transient( 'sampoorna_seo_oauth_state_' . get_current_user_id(), $state, 15 * MINUTE_IN_SECONDS );

		$url = add_query_arg(
			array(
				'client_id'              => rawurlencode( $this->client_id() ),
				'redirect_uri'           => rawurlencode( $this->redirect_uri() ),
				'response_type'          => 'code',
				'scope'                  => rawurlencode( self::SCOPE ),
				'access_type'            => 'offline',
				'include_granted_scopes' => 'true',
				'prompt'                 => 'consent',
				'state'                  => rawurlencode( $state ),
			),
			self::AUTH_ENDPOINT
		);

		wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect
		exit;
	}

	/**
	 * Handle Google's redirect back with the auth code.
	 */
	public function handle_callback() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'sampoorna-seo' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth callback; CSRF is enforced via the OAuth state parameter, validated below.
		$state    = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		$expected = get_transient( 'sampoorna_seo_oauth_state_' . get_current_user_id() );
		if ( empty( $state ) || $state !== $expected ) {
			$this->redirect_settings( 'bad_state' );
		}
		delete_transient( 'sampoorna_seo_oauth_state_' . get_current_user_id() );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth callback; CSRF is enforced via the OAuth state parameter, validated above.
		if ( isset( $_GET['error'] ) ) {
			$this->redirect_settings( 'denied' );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth callback; CSRF is enforced via the OAuth state parameter, validated above.
		$code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		if ( '' === $code ) {
			$this->redirect_settings( 'no_code' );
		}

		$resp = wp_remote_post(
			self::TOKEN_ENDPOINT,
			array(
				'timeout' => 20,
				'body'    => array(
					'code'          => $code,
					'client_id'     => $this->client_id(),
					'client_secret' => $this->client_secret(),
					'redirect_uri'  => $this->redirect_uri(),
					'grant_type'    => 'authorization_code',
				),
			)
		);

		if ( is_wp_error( $resp ) ) {
			$this->redirect_settings( 'token_error' );
		}
		$data = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( empty( $data['access_token'] ) ) {
			$this->redirect_settings( 'token_error' );
		}

		$data['expires_at'] = time() + (int) ( $data['expires_in'] ?? 3600 ) - 60;
		$this->store_token( $data );

		$this->redirect_settings( 'connected' );
	}

	/**
	 * Revoke and clear stored credentials/data.
	 */
	public function handle_disconnect() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'sampoorna_seo_disconnect' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'sampoorna-seo' ) );
		}
		$token = $this->get_token();
		if ( ! empty( $token['refresh_token'] ) ) {
			wp_remote_post(
				'https://oauth2.googleapis.com/revoke',
				array(
					'timeout' => 15,
					'body'    => array( 'token' => $token['refresh_token'] ),
				)
			);
		}
		delete_option( self::OPT_TOKEN );
		delete_option( self::OPT_PROPERTY );
		$this->redirect_settings( 'disconnected' );
	}

	/* ---------- Token management ---------- */

	/**
	 * Persist the token payload, encrypted, in the options table.
	 *
	 * @param array $data Token data as returned by Google's token endpoint.
	 */
	private function store_token( array $data ) {
		// Preserve an existing refresh token if Google omits it on re-consent.
		$existing = $this->get_token();
		if ( empty( $data['refresh_token'] ) && ! empty( $existing['refresh_token'] ) ) {
			$data['refresh_token'] = $existing['refresh_token'];
		}
		update_option( self::OPT_TOKEN, Crypto::encrypt( wp_json_encode( $data ) ), false );
	}

	/**
	 * Retrieve and decrypt the stored token payload.
	 *
	 * @return array
	 */
	public function get_token() {
		$raw = Crypto::decrypt( get_option( self::OPT_TOKEN, '' ) );
		$tok = $raw ? json_decode( $raw, true ) : array();
		return is_array( $tok ) ? $tok : array();
	}

	/**
	 * Return a valid access token, refreshing if needed.
	 *
	 * @return string|\WP_Error
	 */
	public function get_access_token() {
		$token = $this->get_token();
		if ( empty( $token['refresh_token'] ) ) {
			return new \WP_Error( 'not_connected', __( 'Google Search Console is not connected.', 'sampoorna-seo' ) );
		}
		if ( ! empty( $token['access_token'] ) && ! empty( $token['expires_at'] ) && time() < (int) $token['expires_at'] ) {
			return $token['access_token'];
		}
		return $this->refresh_token( $token['refresh_token'] );
	}

	/**
	 * Exchange a refresh token for a fresh access token.
	 *
	 * @param string $refresh_token The stored refresh token.
	 * @return string|\WP_Error Access token on success, WP_Error on failure.
	 */
	private function refresh_token( $refresh_token ) {
		$resp = wp_remote_post(
			self::TOKEN_ENDPOINT,
			array(
				'timeout' => 20,
				'body'    => array(
					'client_id'     => $this->client_id(),
					'client_secret' => $this->client_secret(),
					'refresh_token' => $refresh_token,
					'grant_type'    => 'refresh_token',
				),
			)
		);
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}
		$data = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( empty( $data['access_token'] ) ) {
			return new \WP_Error( 'refresh_failed', __( 'Could not refresh access token. Please reconnect.', 'sampoorna-seo' ) );
		}
		$data['refresh_token'] = $refresh_token;
		$data['expires_at']    = time() + (int) ( $data['expires_in'] ?? 3600 ) - 60;
		$this->store_token( $data );
		return $data['access_token'];
	}

	/* ---------- Helpers ---------- */

	/**
	 * Redirect back to the settings page with a notice code.
	 *
	 * @param string $notice Notice identifier appended to the settings URL.
	 */
	private function redirect_settings( $notice ) {
		wp_safe_redirect( admin_url( 'admin.php?page=sampoorna-seo-settings&sampoorna_seo_notice=' . rawurlencode( $notice ) ) );
		exit;
	}
}
