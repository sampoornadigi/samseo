<?php
/**
 * Hreflang alternate-language link output.
 *
 * Emits rel="alternate" hreflang tags for translated content. Sources alternates
 * from Polylang automatically (or any plugin/code via the
 * `sampoorna_seo_hreflang_alternates` filter); on single-language sites it emits
 * nothing — no fabricated alternates.
 *
 * @package Sampoorna\SEO
 */

namespace Sampoorna\SEO\Technical;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders hreflang alternates into the document head.
 */
class Hreflang {

	/**
	 * Singleton instance.
	 *
	 * @var Hreflang|null
	 */
	private static $instance = null;

	/**
	 * Retrieve the singleton instance.
	 *
	 * @return Hreflang
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Wire the head output (after the meta Renderer at priority 1).
	 */
	private function __construct() {
		add_action( 'wp_head', array( $this, 'output' ), 2 );
	}

	/**
	 * Emit hreflang link tags for the current singular view.
	 *
	 * @return void
	 */
	public function output() {
		if ( ! is_singular() ) {
			return;
		}
		$post = get_queried_object();
		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		$alternates = $this->alternates( $post );
		/**
		 * Filter hreflang alternates ([lang => url]) for a post. WPML/custom/manual
		 * integrations hook here; an 'x-default' key is honored if provided.
		 *
		 * @param array<string,string> $alternates Language code => URL.
		 * @param \WP_Post              $post       Current post.
		 */
		$alternates = apply_filters( 'sampoorna_seo_hreflang_alternates', $alternates, $post );

		if ( ! is_array( $alternates ) || count( $alternates ) < 1 ) {
			return;
		}

		echo "\n";
		foreach ( $alternates as $lang => $url ) {
			$url = (string) $url;
			if ( '' === $url ) {
				continue;
			}
			printf( '<link rel="alternate" hreflang="%s" href="%s" />' . "\n", esc_attr( (string) $lang ), esc_url( $url ) );
		}
	}

	/**
	 * Auto-detect alternates from Polylang, including x-default. Empty otherwise.
	 *
	 * @param \WP_Post $post Current post.
	 * @return array<string,string>
	 */
	private function alternates( $post ) {
		$out = array();

		if ( function_exists( 'pll_get_post_translations' ) ) {
			$translations = pll_get_post_translations( $post->ID );
			if ( is_array( $translations ) ) {
				foreach ( $translations as $lang => $tr_id ) {
					$link = get_permalink( (int) $tr_id );
					if ( $link ) {
						$out[ (string) $lang ] = $link;
					}
				}
			}
			if ( function_exists( 'pll_default_language' ) ) {
				$default = pll_default_language();
				if ( $default && isset( $out[ $default ] ) ) {
					$out['x-default'] = $out[ $default ];
				}
			}
		}

		return $out;
	}
}
