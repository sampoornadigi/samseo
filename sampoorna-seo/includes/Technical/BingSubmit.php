<?php
/**
 * Bing Webmaster URL submission.
 *
 * Submits new/updated URLs to the Bing Webmaster "SubmitUrl" API on publish,
 * authenticated by an API key (from Bing Webmaster Tools → Settings → API
 * access). Unlike Google's Indexing API, Bing accepts general pages, so this
 * mirrors IndexNow: fire-and-forget on publish so saves are never slowed. The
 * key is stored encrypted at rest.
 *
 * @package Sampoorna\SEO
 */

namespace Sampoorna\SEO\Technical;

use Sampoorna\SEO\Security\Crypto;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Submits URLs to the Bing Webmaster API on publish.
 */
class BingSubmit {

	const OPT_ENABLED = 'sampoorna_seo_bing_enabled';
	const OPT_KEY     = 'sampoorna_seo_bing_apikey'; // Stored encrypted.
	const ENDPOINT    = 'https://ssl.bing.com/webmaster/api.svc/json/SubmitUrl';

	/**
	 * Singleton instance.
	 *
	 * @var BingSubmit|null
	 */
	private static $instance = null;

	/**
	 * Retrieve the singleton instance.
	 *
	 * @return BingSubmit
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Wire the publish trigger.
	 */
	private function __construct() {
		add_action( 'transition_post_status', array( $this, 'on_transition' ), 10, 3 );
	}

	/**
	 * Whether submission is enabled.
	 *
	 * @return bool
	 */
	public static function enabled() {
		return (bool) get_option( self::OPT_ENABLED, false );
	}

	/**
	 * The decrypted Bing API key (empty until configured).
	 *
	 * @return string
	 */
	public static function api_key() {
		return Crypto::decrypt( get_option( self::OPT_KEY, '' ) );
	}

	/**
	 * Whether submission is enabled and a key is present.
	 *
	 * @return bool
	 */
	public static function is_configured() {
		return self::enabled() && '' !== self::api_key();
	}

	/**
	 * Submit a URL when a public post is published or updated.
	 *
	 * @param string   $new_status New status.
	 * @param string   $old_status Old status.
	 * @param \WP_Post $post       Post object.
	 * @return void
	 */
	public function on_transition( $new_status, $old_status, $post ) {
		if ( ! self::is_configured() || ! get_option( 'blog_public' ) ) {
			return;
		}
		if ( ! $post instanceof \WP_Post || wp_is_post_revision( $post->ID ) || wp_is_post_autosave( $post->ID ) ) {
			return;
		}
		if ( 'publish' !== $new_status ) {
			return;
		}
		$pt = get_post_type_object( $post->post_type );
		if ( ! $pt || empty( $pt->public ) ) {
			return;
		}
		$this->submit_url( (string) get_permalink( $post ) );
	}

	/**
	 * Fire-and-forget submission of a single URL to Bing.
	 *
	 * @param string $url URL to submit.
	 * @return void
	 */
	public function submit_url( $url ) {
		$url = (string) $url;
		if ( '' === $url || ! self::is_configured() ) {
			return;
		}
		wp_remote_post(
			self::endpoint( self::api_key() ),
			array(
				'headers'  => array( 'Content-Type' => 'application/json; charset=utf-8' ),
				'body'     => wp_json_encode( self::payload( home_url( '/' ), $url ) ),
				'timeout'  => 5,
				'blocking' => false,
			)
		);
	}

	/**
	 * The endpoint with the API key as a query parameter. Pure (unit-testable).
	 *
	 * @param string $key API key.
	 * @return string
	 */
	public static function endpoint( $key ) {
		return add_query_arg( 'apikey', rawurlencode( $key ), self::ENDPOINT );
	}

	/**
	 * The SubmitUrl request payload. Pure (unit-testable).
	 *
	 * @param string $site_url Site root URL.
	 * @param string $url      URL to submit.
	 * @return array<string,string>
	 */
	public static function payload( $site_url, $url ) {
		return array(
			'siteUrl' => (string) $site_url,
			'url'     => (string) $url,
		);
	}
}
