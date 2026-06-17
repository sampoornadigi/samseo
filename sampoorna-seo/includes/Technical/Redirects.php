<?php
/**
 * Redirect manager + 404 monitor.
 *
 * Matches the current request against admin-managed redirects (301/302/307/410,
 * plain + regex) with defined precedence and loop detection, and logs
 * not-found URLs for one-click redirect creation. The active redirect set is
 * cached in a transient (busted on change) so the front-end matcher does no
 * query when there are no redirects.
 *
 * @package Sampoorna\SEO
 */

namespace Sampoorna\SEO\Technical;

use Sampoorna\SEO\Core\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves redirects on the front end and records 404s.
 */
class Redirects {

	const CACHE_KEY = 'sampoorna_seo_redirects_active';

	/**
	 * Singleton instance.
	 *
	 * @var Redirects|null
	 */
	private static $instance = null;

	/**
	 * Retrieve the singleton instance.
	 *
	 * @return Redirects
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Wire the front-end handler (priority 1: after Sitemap, before redirect_canonical).
	 */
	private function __construct() {
		add_action( 'template_redirect', array( $this, 'handle' ), 1 );
	}

	/**
	 * Match and act on the current request; otherwise log a 404.
	 *
	 * @return void
	 */
	public function handle() {
		$path = $this->current_path();
		if ( '' === $path ) {
			return;
		}

		$match = $this->find_redirect( $path );
		if ( null !== $match ) {
			if ( 410 === $match['type'] ) {
				status_header( 410 );
				nocache_headers();
				exit;
			}
			Database::touch_redirect( $match['id'] );
			wp_safe_redirect( $match['target'], $match['type'] );
			exit;
		}

		$this->maybe_log_404( $path );
	}

	/**
	 * Resolve a redirect for a path. Exact matches take precedence over regex.
	 *
	 * @param string $path Normalized request path.
	 * @return array{id:int,target:string,type:int}|null
	 */
	public function find_redirect( $path ) {
		foreach ( $this->active() as $r ) {
			$type = (int) $r['type'];
			$id   = (int) $r['id'];

			if ( ! empty( $r['is_regex'] ) ) {
				$pattern = '@' . str_replace( '@', '\@', (string) $r['source'] ) . '@';
				$matched = @preg_match( $pattern, $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Invalid user-supplied patterns must not warn; treated as non-match.
				if ( 1 !== $matched ) {
					continue;
				}
				$target = (string) preg_replace( $pattern, (string) $r['target'], $path );
			} else {
				if ( $this->normalize( (string) $r['source'] ) !== $path ) {
					continue;
				}
				$target = (string) $r['target'];
			}

			if ( 410 === $type ) {
				return array(
					'id'     => $id,
					'target' => '',
					'type'   => 410,
				);
			}

			// Loop detection: skip if the target resolves to the same path.
			$target_path = (string) wp_parse_url( $target, PHP_URL_PATH );
			if ( '' !== $target_path && $this->normalize( $target_path ) === $path && false === strpos( $target, '://' ) ) {
				continue;
			}

			$absolute = ( false !== strpos( $target, '://' ) ) ? $target : home_url( '/' . ltrim( $target, '/' ) );
			return array(
				'id'     => $id,
				'target' => $absolute,
				'type'   => ( 301 === $type || 302 === $type || 307 === $type ) ? $type : 301,
			);
		}

		return null;
	}

	/**
	 * Log the current 404 (front-end GET, skipping editors).
	 *
	 * @param string $path Normalized request path.
	 * @return void
	 */
	private function maybe_log_404( $path ) {
		if ( ! is_404() ) {
			return;
		}
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'GET';
		if ( 'GET' !== $method || current_user_can( 'edit_posts' ) ) {
			return;
		}
		$referrer = isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';
		$agent    = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		Database::log_not_found( $path, $referrer, mb_substr( $agent, 0, 255 ) );
	}

	/**
	 * Active redirects, cached in a transient and busted on any change.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function active() {
		$cached = get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$rows = Database::active_redirects();
		set_transient( self::CACHE_KEY, $rows, 12 * HOUR_IN_SECONDS );
		return $rows;
	}

	/**
	 * The current request path, normalized (leading slash, no trailing slash).
	 *
	 * @return string
	 */
	public function current_path() {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$raw = wp_parse_url( $uri, PHP_URL_PATH );
		return $this->normalize( is_string( $raw ) ? urldecode( $raw ) : '' );
	}

	/**
	 * Normalize a path to a leading slash with no trailing slash (root stays "/").
	 *
	 * @param string $path Raw path.
	 * @return string
	 */
	private function normalize( $path ) {
		$path = '/' . ltrim( trim( (string) $path ), '/' );
		return '/' === $path ? '/' : rtrim( $path, '/' );
	}
}
