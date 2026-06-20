<?php
/**
 * Google Indexing API submission (service-account auth).
 *
 * Notifies Google of new/updated/removed URLs via the Indexing API. Auth is a
 * service account: we mint an RS256-signed JWT, exchange it for a short-lived
 * access token (cached), and POST to urlNotifications:publish.
 *
 * Scope note: Google officially supports the Indexing API only for pages with
 * JobPosting or BroadcastEvent structured data. We therefore do NOT auto-submit
 * every post (Google ignores general pages and it wastes quota) — submission is
 * on-demand for the URLs you choose. The service-account JSON is stored
 * encrypted at rest.
 *
 * @package Sampoorna\SEO
 */

namespace Sampoorna\SEO\Technical;

use Sampoorna\SEO\Security\Crypto;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Authenticates with a Google service account and publishes URL notifications.
 */
class IndexingApi {

	const OPT_ENABLED = 'sampoorna_seo_gindexing_enabled';
	const OPT_KEY     = 'sampoorna_seo_gindexing_sa'; // Service-account JSON, encrypted.

	const SCOPE             = 'https://www.googleapis.com/auth/indexing';
	const DEFAULT_TOKEN_URI = 'https://oauth2.googleapis.com/token';
	const PUBLISH_ENDPOINT  = 'https://indexing.googleapis.com/v3/urlNotifications:publish';
	const TOKEN_TRANSIENT   = 'sampoorna_seo_gindexing_token';

	/**
	 * Singleton instance.
	 *
	 * @var IndexingApi|null
	 */
	private static $instance = null;

	/**
	 * Retrieve the singleton instance.
	 *
	 * @return IndexingApi
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Whether submission is enabled and a usable service account is stored.
	 *
	 * @return bool
	 */
	public function is_configured() {
		if ( ! (bool) get_option( self::OPT_ENABLED, false ) ) {
			return false;
		}
		$creds = $this->credentials();
		return '' !== ( $creds['client_email'] ?? '' ) && '' !== ( $creds['private_key'] ?? '' );
	}

	/**
	 * Decode the stored (decrypted) service-account JSON.
	 *
	 * @return array<string,string>
	 */
	public function credentials() {
		$raw = Crypto::decrypt( get_option( self::OPT_KEY, '' ) );
		$sa  = $raw ? json_decode( $raw, true ) : array();
		if ( ! is_array( $sa ) ) {
			return array();
		}
		return array(
			'client_email' => isset( $sa['client_email'] ) ? (string) $sa['client_email'] : '',
			'private_key'  => isset( $sa['private_key'] ) ? (string) $sa['private_key'] : '',
			'token_uri'    => isset( $sa['token_uri'] ) ? (string) $sa['token_uri'] : self::DEFAULT_TOKEN_URI,
		);
	}

	/**
	 * Submit a single URL notification to the Indexing API.
	 *
	 * @param string $url  Absolute URL to notify about.
	 * @param string $type URL_UPDATED (default) or URL_DELETED.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function submit_url( $url, $type = 'URL_UPDATED' ) {
		$url  = (string) $url;
		$type = 'URL_DELETED' === $type ? 'URL_DELETED' : 'URL_UPDATED';
		if ( '' === $url ) {
			return new \WP_Error( 'gindexing_no_url', __( 'No URL to submit.', 'sampoorna-seo' ) );
		}
		if ( ! $this->is_configured() ) {
			return new \WP_Error( 'gindexing_not_configured', __( 'Google Indexing API is not configured.', 'sampoorna-seo' ) );
		}
		$token = $this->get_access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$resp = wp_remote_post(
			self::PUBLISH_ENDPOINT,
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'url'  => $url,
						'type' => $type,
					)
				),
			)
		);
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		$data = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( 200 !== $code ) {
			$msg = isset( $data['error']['message'] ) ? (string) $data['error']['message'] : __( 'Indexing API request failed.', 'sampoorna-seo' );
			return new \WP_Error( 'gindexing_request_failed', $msg );
		}
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Return a valid access token (cached), minting a new one when needed.
	 *
	 * @return string|\WP_Error
	 */
	public function get_access_token() {
		$cached = get_transient( self::TOKEN_TRANSIENT );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}
		$creds = $this->credentials();
		if ( '' === $creds['client_email'] || '' === $creds['private_key'] ) {
			return new \WP_Error( 'gindexing_no_creds', __( 'Missing service-account credentials.', 'sampoorna-seo' ) );
		}

		$now = time();
		$jwt = self::encode_jwt( self::claims( $creds, $now ), $creds['private_key'] );
		if ( is_wp_error( $jwt ) ) {
			return $jwt;
		}

		$resp = wp_remote_post(
			'' !== $creds['token_uri'] ? $creds['token_uri'] : self::DEFAULT_TOKEN_URI,
			array(
				'timeout' => 20,
				'body'    => array(
					'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
					'assertion'  => $jwt,
				),
			)
		);
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}
		$data = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( empty( $data['access_token'] ) ) {
			$msg = isset( $data['error_description'] ) ? (string) $data['error_description'] : __( 'Could not obtain an Indexing API token.', 'sampoorna-seo' );
			return new \WP_Error( 'gindexing_token_failed', $msg );
		}
		$ttl = max( 60, (int) ( $data['expires_in'] ?? 3600 ) - 60 );
		set_transient( self::TOKEN_TRANSIENT, (string) $data['access_token'], $ttl );
		return (string) $data['access_token'];
	}

	/**
	 * Build the JWT claim set for the service account. Pure (unit-testable).
	 *
	 * @param array<string,string> $creds Decoded service-account credentials.
	 * @param int                  $now   Current UNIX time.
	 * @return array<string,mixed>
	 */
	public static function claims( array $creds, $now ) {
		$aud = ! empty( $creds['token_uri'] ) ? $creds['token_uri'] : self::DEFAULT_TOKEN_URI;
		return array(
			'iss'   => $creds['client_email'] ?? '',
			'scope' => self::SCOPE,
			'aud'   => $aud,
			'iat'   => (int) $now,
			'exp'   => (int) $now + 3600,
		);
	}

	/**
	 * Encode + RS256-sign a JWT. Pure (no I/O) — unit-testable with any key.
	 *
	 * @param array<string,mixed> $claim       JWT claim set.
	 * @param string              $private_key PEM private key.
	 * @return string|\WP_Error Signed JWT, or WP_Error when signing fails.
	 */
	public static function encode_jwt( array $claim, $private_key ) {
		$header        = array(
			'alg' => 'RS256',
			'typ' => 'JWT',
		);
		$segments      = array(
			self::base64url( (string) wp_json_encode( $header ) ),
			self::base64url( (string) wp_json_encode( $claim ) ),
		);
		$signing_input = implode( '.', $segments );

		$signature = '';
		$ok        = openssl_sign( $signing_input, $signature, $private_key, OPENSSL_ALGO_SHA256 );
		if ( ! $ok ) {
			return new \WP_Error( 'gindexing_sign_failed', __( 'Could not sign the JWT (invalid private key?).', 'sampoorna-seo' ) );
		}
		$segments[] = self::base64url( $signature );
		return implode( '.', $segments );
	}

	/**
	 * URL-safe base64 encoding (no padding), per the JWT spec.
	 *
	 * @param string $data Raw bytes.
	 * @return string
	 */
	public static function base64url( $data ) {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Standard JWT base64url encoding, not obfuscation.
	}
}
