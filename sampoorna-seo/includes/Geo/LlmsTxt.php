<?php
/**
 * LLMs.txt / llms-full.txt generator (GEO / AI visibility).
 *
 * Serves a curated markdown map of the site at /llms.txt (and the content-rich
 * /llms-full.txt) so LLMs and answer engines can ingest and quote the site's
 * key content. Follows the same performance-budgeted machinery as the XML
 * sitemap: a rewrite-routed endpoint served on template_redirect before
 * redirect_canonical, with output cached in a version-stamped transient that is
 * busted lazily on content change.
 *
 * @package Sampoorna\SEO
 */

namespace Sampoorna\SEO\Geo;

use Sampoorna\SEO\Meta\MetaStore;
use Sampoorna\SEO\Schema\Graph;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds and serves the site's llms.txt / llms-full.txt.
 */
class LlmsTxt {

	const QV          = 'sampoorna_seo_llms';
	const OPT_ENABLED = 'sampoorna_seo_llms_enabled';
	const OPT_INTRO   = 'sampoorna_seo_llms_intro';
	const OPT_VERSION = 'sampoorna_seo_llms_version';

	/** Max items listed per section in llms.txt. */
	const SECTION_CAP = 100;

	/** Max items (with full content) in llms-full.txt. */
	const FULL_LIMIT = 50;

	/**
	 * Singleton instance.
	 *
	 * @var LlmsTxt|null
	 */
	private static $instance = null;

	/**
	 * Retrieve the singleton instance.
	 *
	 * @return LlmsTxt
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Wire routing, output, and cache-busting hooks.
	 */
	private function __construct() {
		add_action( 'init', array( $this, 'register_rules' ) );
		add_filter( 'query_vars', array( $this, 'query_vars' ) );
		// Priority 0 so we serve before core's redirect_canonical adds a trailing slash.
		add_action( 'template_redirect', array( $this, 'maybe_render' ), 0 );

		add_action( 'save_post', array( $this, 'bump_version' ) );
		add_action( 'deleted_post', array( $this, 'bump_version' ) );
		add_action( 'transition_post_status', array( $this, 'bump_version' ) );
	}

	/**
	 * Register the rewrite rules for /llms.txt and /llms-full.txt.
	 *
	 * @return void
	 */
	public function register_rules() {
		add_rewrite_rule( '^llms\.txt$', 'index.php?' . self::QV . '=index', 'top' );
		add_rewrite_rule( '^llms-full\.txt$', 'index.php?' . self::QV . '=full', 'top' );
	}

	/**
	 * Register our public query var.
	 *
	 * @param string[] $vars Existing query vars.
	 * @return string[]
	 */
	public function query_vars( $vars ) {
		$vars[] = self::QV;
		return $vars;
	}

	/**
	 * Whether llms.txt output is enabled.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return (bool) get_option( self::OPT_ENABLED, true );
	}

	/**
	 * Serve the llms.txt variant when our query var is set.
	 *
	 * @return void
	 */
	public function maybe_render() {
		$variant = (string) get_query_var( self::QV );
		if ( '' === $variant ) {
			return;
		}

		$body = self::is_enabled() ? $this->generate( $variant ) : '';
		if ( '' === $body ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			return;
		}

		if ( ! headers_sent() ) {
			header( 'Content-Type: text/plain; charset=utf-8' );
			header( 'X-Robots-Tag: noindex, follow', true );
		}
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain-text body; URLs are esc_url_raw'd and text is sanitized during assembly.
		echo $body;
		exit;
	}

	/**
	 * Bump the cache version so the next request regenerates.
	 *
	 * @return void
	 */
	public function bump_version() {
		update_option( self::OPT_VERSION, (int) get_option( self::OPT_VERSION, 1 ) + 1 );
	}

	/**
	 * Build the llms.txt (index) or llms-full.txt (full) body.
	 *
	 * @param string $variant 'index' or 'full'.
	 * @return string Empty string when disabled or unknown variant.
	 */
	public function generate( $variant ) {
		if ( ! self::is_enabled() || ! in_array( $variant, array( 'index', 'full' ), true ) ) {
			return '';
		}

		$version   = (int) get_option( self::OPT_VERSION, 1 );
		$cache_key = 'sseo_llms_' . md5( $variant . '|' . $version );
		$cached    = get_transient( $cache_key );
		if ( is_string( $cached ) ) {
			return $cached;
		}

		$body = ( 'full' === $variant ) ? $this->build_full() : $this->build_index();
		set_transient( $cache_key, $body, 12 * HOUR_IN_SECONDS );
		return $body;
	}

	/**
	 * Build the curated link-map (llms.txt).
	 *
	 * @return string
	 */
	private function build_index() {
		$out = $this->header();
		foreach ( $this->sections() as $section ) {
			$out .= "\n## " . $section['label'] . "\n\n";
			foreach ( $section['items'] as $item ) {
				$desc = '' !== $item['desc'] ? ': ' . $item['desc'] : '';
				$out .= '- [' . $item['title'] . '](' . $item['url'] . ')' . $desc . "\n";
			}
		}
		return rtrim( $out ) . "\n";
	}

	/**
	 * Build the content-rich variant (llms-full.txt).
	 *
	 * @return string
	 */
	private function build_full() {
		$out       = $this->header();
		$remaining = self::FULL_LIMIT;
		$truncated = false;

		foreach ( $this->sections() as $section ) {
			$out .= "\n## " . $section['label'] . "\n";
			foreach ( $section['items'] as $item ) {
				if ( $remaining <= 0 ) {
					$truncated = true;
					break 2;
				}
				$out .= "\n### [" . $item['title'] . '](' . $item['url'] . ")\n\n";
				$out .= '' !== $item['content'] ? $item['content'] . "\n" : $item['desc'] . "\n";
				--$remaining;
			}
		}

		if ( $truncated ) {
			$out .= "\n> Truncated to the most recent " . self::FULL_LIMIT . " items.\n";
		}
		return rtrim( $out ) . "\n";
	}

	/**
	 * The shared document header (title + summary + intro).
	 *
	 * @return string
	 */
	private function header() {
		$name = trim( (string) get_option( Graph::OPT_ORG_NAME, '' ) );
		if ( '' === $name ) {
			$name = (string) get_bloginfo( 'name' );
		}
		$summary = trim( (string) get_option( self::OPT_INTRO, '' ) );
		if ( '' === $summary ) {
			$summary = (string) get_bloginfo( 'description' );
		}

		$out = '# ' . $name . "\n";
		if ( '' !== $summary ) {
			$out .= "\n> " . $summary . "\n";
		}
		return $out;
	}

	/**
	 * Build the per-post-type sections (Pages, Posts, then other public CPTs).
	 *
	 * @return array<int,array{label:string,items:array<int,array<string,string>>}>
	 */
	private function sections() {
		$order = array( 'page', 'post' );
		foreach ( get_post_types( array( 'public' => true ), 'names' ) as $pt ) {
			if ( 'attachment' !== $pt && ! in_array( $pt, $order, true ) ) {
				$order[] = $pt;
			}
		}

		$sections = array();
		foreach ( $order as $pt ) {
			if ( ! post_type_exists( $pt ) ) {
				continue;
			}
			$items = $this->items_for( $pt );
			if ( ! empty( $items ) ) {
				$obj        = get_post_type_object( $pt );
				$label      = ( $obj && isset( $obj->labels->name ) ) ? $obj->labels->name : $pt;
				$sections[] = array(
					'label' => $label,
					'items' => $items,
				);
			}
		}
		return $sections;
	}

	/**
	 * Build the item list for a post type (noindexed entries excluded).
	 *
	 * @param string $post_type Post type.
	 * @return array<int,array<string,string>>
	 */
	private function items_for( $post_type ) {
		$ids = get_posts(
			array(
				'post_type'        => $post_type,
				'post_status'      => 'publish',
				'has_password'     => false,
				'numberposts'      => self::SECTION_CAP,
				'orderby'          => 'date',
				'order'            => 'DESC',
				'fields'           => 'ids',
				'suppress_filters' => false,
			)
		);

		$items = array();
		foreach ( (array) $ids as $id ) {
			$id = (int) $id;
			if ( '1' === MetaStore::get( $id, 'robots_noindex' ) ) {
				continue;
			}
			$post = get_post( $id );
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}
			$items[] = array(
				'title'   => $this->clean( get_the_title( $post ) ),
				'url'     => esc_url_raw( (string) get_permalink( $post ) ),
				'desc'    => $this->description( $post ),
				'content' => $this->plain_content( $post ),
			);
		}
		return $items;
	}

	/**
	 * One-line description: the SEO meta override, else a trimmed excerpt.
	 *
	 * @param \WP_Post $post Post.
	 * @return string
	 */
	private function description( $post ) {
		$override = MetaStore::get( $post->ID, 'desc' );
		if ( '' !== $override ) {
			return $this->clean( $override );
		}
		return $this->clean( wp_trim_words( $this->plain_content( $post ), 40, '' ) );
	}

	/**
	 * Full plain-text content (shortcodes/tags stripped, whitespace collapsed).
	 *
	 * @param \WP_Post $post Post.
	 * @return string
	 */
	private function plain_content( $post ) {
		$text = wp_strip_all_tags( strip_shortcodes( (string) $post->post_content ) );
		return trim( (string) preg_replace( '/\s+/', ' ', $text ) );
	}

	/**
	 * Collapse whitespace/newlines for a single-line markdown cell.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private function clean( $text ) {
		return trim( (string) preg_replace( '/\s+/', ' ', (string) $text ) );
	}
}
