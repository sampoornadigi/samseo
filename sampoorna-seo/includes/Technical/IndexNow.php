<?php
/**
 * IndexNow auto-submission.
 *
 * Submits new/updated/removed URLs to IndexNow-participating engines (Bing,
 * Yandex, …) on publish, authenticated by a public key file served from the
 * site root. Submission is fire-and-forget so saves are never slowed.
 *
 * (The Google Indexing API — officially scoped to JobPosting/BroadcastEvent
 * pages — is a separate, on-demand module: see Technical\IndexingApi.)
 *
 * @package Sampoorna\SEO
 */

namespace Sampoorna\SEO\Technical;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generates the IndexNow key, serves the key file, and submits URLs.
 */
class IndexNow {

	const OPT_KEY     = 'sampoorna_seo_indexnow_key';
	const OPT_ENABLED = 'sampoorna_seo_indexnow_enabled';
	const QV_KEYFILE  = 'sampoorna_seo_indexnow_keyfile';
	const ENDPOINT    = 'https://api.indexnow.org/indexnow';

	/**
	 * Singleton instance.
	 *
	 * @var IndexNow|null
	 */
	private static $instance = null;

	/**
	 * Retrieve the singleton instance.
	 *
	 * @return IndexNow
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Wire key-file routing and the publish trigger.
	 */
	private function __construct() {
		add_action( 'init', array( $this, 'register_rules' ) );
		add_filter( 'query_vars', array( $this, 'query_vars' ) );
		add_action( 'template_redirect', array( $this, 'maybe_serve_key' ), 0 );
		add_action( 'transition_post_status', array( $this, 'on_transition' ), 10, 3 );
	}

	/**
	 * Register the key-file rewrite rule.
	 *
	 * @return void
	 */
	public function register_rules() {
		add_rewrite_rule( '^([a-f0-9]{16,128})\.txt$', 'index.php?' . self::QV_KEYFILE . '=$matches[1]', 'top' );
	}

	/**
	 * Register the key-file query var.
	 *
	 * @param string[] $vars Existing query vars.
	 * @return string[]
	 */
	public function query_vars( $vars ) {
		$vars[] = self::QV_KEYFILE;
		return $vars;
	}

	/**
	 * Serve the key file when the request matches the stored key.
	 *
	 * @return void
	 */
	public function maybe_serve_key() {
		$requested = (string) get_query_var( self::QV_KEYFILE );
		if ( '' === $requested ) {
			return;
		}
		$key = self::key();
		if ( '' === $key || ! hash_equals( $key, $requested ) ) {
			// A hex *.txt that isn't our key → explicit 404 (don't fall through to canonical).
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			return;
		}
		if ( ! headers_sent() ) {
			header( 'Content-Type: text/plain; charset=UTF-8' );
		}
		echo esc_html( $key );
		exit;
	}

	/**
	 * Submit a URL when a public post is published, updated, or unpublished.
	 *
	 * @param string   $new_status New status.
	 * @param string   $old_status Old status.
	 * @param \WP_Post $post       Post object.
	 * @return void
	 */
	public function on_transition( $new_status, $old_status, $post ) {
		if ( ! self::enabled() || '' === self::key() || ! get_option( 'blog_public' ) ) {
			return;
		}
		if ( ! $post instanceof \WP_Post || wp_is_post_revision( $post->ID ) || wp_is_post_autosave( $post->ID ) ) {
			return;
		}
		if ( 'publish' !== $new_status && 'publish' !== $old_status ) {
			return;
		}
		$pt = get_post_type_object( $post->post_type );
		if ( ! $pt || empty( $pt->public ) ) {
			return;
		}
		$this->submit_url( (string) get_permalink( $post ) );
	}

	/**
	 * Fire-and-forget submission of a single URL to IndexNow.
	 *
	 * @param string $url URL to submit.
	 * @return void
	 */
	public function submit_url( $url ) {
		$url = (string) $url;
		if ( '' === $url || '' === self::key() ) {
			return;
		}
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$body = wp_json_encode(
			array(
				'host'        => $host,
				'key'         => self::key(),
				'keyLocation' => self::key_file_url(),
				'urlList'     => array( $url ),
			)
		);
		wp_remote_post(
			self::ENDPOINT,
			array(
				'headers'  => array( 'Content-Type' => 'application/json; charset=utf-8' ),
				'body'     => $body,
				'timeout'  => 5,
				'blocking' => false,
			)
		);
	}

	/**
	 * The public IndexNow key (empty until generated).
	 *
	 * @return string
	 */
	public static function key() {
		return (string) get_option( self::OPT_KEY, '' );
	}

	/**
	 * Generate and store a key if none exists.
	 *
	 * @return void
	 */
	public static function ensure_key() {
		if ( '' === self::key() ) {
			update_option( self::OPT_KEY, bin2hex( random_bytes( 16 ) ), false );
		}
	}

	/**
	 * Public URL of the key file.
	 *
	 * @return string
	 */
	public static function key_file_url() {
		return home_url( '/' . self::key() . '.txt' );
	}

	/**
	 * Whether auto-submission is enabled.
	 *
	 * @return bool
	 */
	public static function enabled() {
		return (bool) get_option( self::OPT_ENABLED, false );
	}
}
