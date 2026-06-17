<?php
/**
 * Paginated XML sitemaps.
 *
 * Replaces WordPress core's sitemaps with a paginated index + per-type
 * sub-sitemaps (posts, pages, public CPTs, public taxonomies), each with
 * per-URL lastmod and featured images. Performance-budgeted: rendered XML is
 * cached in version-stamped transients and regenerated lazily only after a
 * content change, so normal requests serve cached output with no TTFB hit.
 *
 * @package Sampoorna\SEO
 */

namespace Sampoorna\SEO\Technical;

use Sampoorna\SEO\Meta\MetaStore;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers sitemap routes and renders the index + sub-sitemaps.
 */
class Sitemap {

	const QV_TYPE = 'sampoorna_seo_sitemap';
	const QV_PAGE = 'sampoorna_seo_sitemap_page';

	const PAGE_SIZE   = 1000;
	const OPT_VERSION = 'sampoorna_seo_sitemap_version';

	/**
	 * Singleton instance.
	 *
	 * @var Sitemap|null
	 */
	private static $instance = null;

	/**
	 * Retrieve the singleton instance.
	 *
	 * @return Sitemap
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
		add_filter( 'wp_sitemaps_enabled', '__return_false' );
		add_filter( 'robots_txt', array( $this, 'robots_txt' ), 10, 2 );

		add_action( 'save_post', array( $this, 'on_change' ) );
		add_action( 'deleted_post', array( $this, 'bump_version' ) );
		add_action( 'transition_post_status', array( $this, 'bump_version' ) );
		add_action( 'created_term', array( $this, 'bump_version' ) );
		add_action( 'edited_term', array( $this, 'bump_version' ) );
		add_action( 'delete_term', array( $this, 'bump_version' ) );
	}

	/**
	 * Register the rewrite rules for the index and sub-sitemaps.
	 *
	 * @return void
	 */
	public function register_rules() {
		add_rewrite_rule( '^sitemap_index\.xml$', 'index.php?' . self::QV_TYPE . '=index', 'top' );
		add_rewrite_rule( '^([^/]+?)-sitemap([0-9]+)?\.xml$', 'index.php?' . self::QV_TYPE . '=$matches[1]&' . self::QV_PAGE . '=$matches[2]', 'top' );
	}

	/**
	 * Register our public query vars.
	 *
	 * @param string[] $vars Existing query vars.
	 * @return string[]
	 */
	public function query_vars( $vars ) {
		$vars[] = self::QV_TYPE;
		$vars[] = self::QV_PAGE;
		return $vars;
	}

	/**
	 * Serve a sitemap when one of our query vars is set.
	 *
	 * @return void
	 */
	public function maybe_render() {
		$type = (string) get_query_var( self::QV_TYPE );
		if ( '' === $type ) {
			return;
		}
		$page = max( 1, (int) get_query_var( self::QV_PAGE ) );

		if ( 'index' === $type ) {
			$xml = $this->generate_index();
		} elseif ( post_type_exists( $type ) || taxonomy_exists( $type ) ) {
			$xml = $this->generate_subtype( $type, $page );
		} else {
			$xml = '';
		}

		if ( '' === $xml ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			return;
		}

		if ( ! headers_sent() ) {
			header( 'Content-Type: application/xml; charset=UTF-8' );
			header( 'X-Robots-Tag: noindex, follow', true );
		}
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- XML is assembled from individually esc_url()/esc_html() escaped values.
		echo $xml;
		exit;
	}

	/**
	 * Append the sitemap index to robots.txt (modern replacement for ping).
	 *
	 * @param string $output    Existing robots.txt body.
	 * @param bool   $is_public Whether the site is public.
	 * @return string
	 */
	public function robots_txt( $output, $is_public ) {
		if ( $is_public ) {
			$output .= "\nSitemap: " . esc_url( home_url( '/sitemap_index.xml' ) ) . "\n";
		}
		return $output;
	}

	/* ---------- Providers ---------- */

	/**
	 * Indexable providers: each public post type and taxonomy with content.
	 *
	 * @return array<int,array{subtype:string,object:string,total:int,pages:int}>
	 */
	public function providers() {
		$out       = array();
		$page_size = self::page_size();

		$post_types = get_post_types( array( 'public' => true ), 'names' );
		foreach ( $post_types as $pt ) {
			if ( 'attachment' === $pt ) {
				continue;
			}
			$total = $this->post_total( $pt );
			if ( $total < 1 ) {
				continue;
			}
			$out[] = array(
				'subtype' => $pt,
				'object'  => 'post',
				'total'   => $total,
				'pages'   => (int) ceil( $total / $page_size ),
			);
		}

		$taxonomies = get_taxonomies( array( 'public' => true ), 'names' );
		foreach ( $taxonomies as $tax ) {
			if ( 'post_format' === $tax ) {
				continue;
			}
			$total = $this->term_total( $tax );
			if ( $total < 1 ) {
				continue;
			}
			$out[] = array(
				'subtype' => $tax,
				'object'  => 'term',
				'total'   => $total,
				'pages'   => (int) ceil( $total / $page_size ),
			);
		}

		return $out;
	}

	/* ---------- Generation ---------- */

	/**
	 * Build the sitemap index XML.
	 *
	 * @return string
	 */
	public function generate_index() {
		$cache_key = $this->cache_key( 'index', 0 );
		$cached    = get_transient( $cache_key );
		if ( is_string( $cached ) ) {
			return $cached;
		}

		$xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$xml .= '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
		foreach ( $this->providers() as $p ) {
			for ( $page = 1; $page <= $p['pages']; $page++ ) {
				$loc  = home_url( '/' . $p['subtype'] . '-sitemap' . $page . '.xml' );
				$xml .= "\t<sitemap><loc>" . esc_url( $loc ) . '</loc></sitemap>' . "\n";
			}
		}
		$xml .= '</sitemapindex>' . "\n";

		set_transient( $cache_key, $xml, 12 * HOUR_IN_SECONDS );
		return $xml;
	}

	/**
	 * Build a sub-sitemap page for a post type or taxonomy.
	 *
	 * @param string $subtype Post type or taxonomy name.
	 * @param int    $page    1-based page number.
	 * @return string Empty string when the page is out of range.
	 */
	public function generate_subtype( $subtype, $page ) {
		$cache_key = $this->cache_key( $subtype, $page );
		$cached    = get_transient( $cache_key );
		if ( is_string( $cached ) ) {
			return $cached;
		}

		if ( post_type_exists( $subtype ) ) {
			$urls = $this->post_items( $subtype, $page );
		} elseif ( taxonomy_exists( $subtype ) ) {
			$urls = $this->term_items( $subtype, $page );
		} else {
			$urls = array();
		}

		if ( empty( $urls ) ) {
			return '';
		}

		$has_images = false;
		foreach ( $urls as $u ) {
			if ( '' !== $u['image'] ) {
				$has_images = true;
				break;
			}
		}

		$ns   = 'xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"';
		$ns  .= $has_images ? ' xmlns:image="http://www.google.com/schemas/sitemap-image/1.1"' : '';
		$xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$xml .= '<urlset ' . $ns . '>' . "\n";
		foreach ( $urls as $u ) {
			$xml .= "\t<url>\n\t\t<loc>" . esc_url( $u['loc'] ) . "</loc>\n";
			if ( '' !== $u['lastmod'] ) {
				$xml .= "\t\t<lastmod>" . esc_html( $u['lastmod'] ) . "</lastmod>\n";
			}
			if ( '' !== $u['image'] ) {
				$xml .= "\t\t<image:image><image:loc>" . esc_url( $u['image'] ) . "</image:loc></image:image>\n";
			}
			$xml .= "\t</url>\n";
		}
		$xml .= '</urlset>' . "\n";

		set_transient( $cache_key, $xml, 12 * HOUR_IN_SECONDS );
		return $xml;
	}

	/* ---------- Item queries ---------- */

	/**
	 * Meta query fragment excluding no-indexed objects.
	 *
	 * @return array<int|string,mixed>
	 */
	private function noindex_meta_query() {
		return array(
			'relation' => 'OR',
			array(
				'key'     => MetaStore::KEY_NOINDEX,
				'compare' => 'NOT EXISTS',
			),
			array(
				'key'     => MetaStore::KEY_NOINDEX,
				'value'   => '1',
				'compare' => '!=',
			),
		);
	}

	/**
	 * Count indexable published posts of a type.
	 *
	 * @param string $post_type Post type.
	 * @return int
	 */
	private function post_total( $post_type ) {
		$q = new \WP_Query(
			array(
				'post_type'      => $post_type,
				'post_status'    => 'publish',
				'has_password'   => false,
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => false,
				'meta_query'     => $this->noindex_meta_query(), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Sitemap build, cached in a transient.
			)
		);
		return (int) $q->found_posts;
	}

	/**
	 * One page of indexable post URLs.
	 *
	 * @param string $post_type Post type.
	 * @param int    $page      1-based page.
	 * @return array<int,array{loc:string,lastmod:string,image:string}>
	 */
	private function post_items( $post_type, $page ) {
		$q = new \WP_Query(
			array(
				'post_type'           => $post_type,
				'post_status'         => 'publish',
				'has_password'        => false,
				'posts_per_page'      => self::page_size(),
				'paged'               => $page,
				'orderby'             => 'modified',
				'order'               => 'DESC',
				'no_found_rows'       => true,
				'ignore_sticky_posts' => true,
				'meta_query'          => $this->noindex_meta_query(), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Sitemap build, cached in a transient.
			)
		);

		$items = array();

		// Prepend the site root on page 1 of the post type when the front page shows posts.
		if ( 1 === $page && 'post' === $post_type && 'posts' === get_option( 'show_on_front' ) ) {
			$items[] = array(
				'loc'     => home_url( '/' ),
				'lastmod' => '',
				'image'   => '',
			);
		}

		foreach ( $q->posts as $post ) {
			$image    = '';
			$thumb_id = get_post_thumbnail_id( $post );
			if ( $thumb_id ) {
				$src   = wp_get_attachment_image_url( (int) $thumb_id, 'full' );
				$image = $src ? $src : '';
			}
			$items[] = array(
				'loc'     => (string) get_permalink( $post ),
				'lastmod' => mysql2date( DATE_W3C, $post->post_modified_gmt, false ),
				'image'   => $image,
			);
		}

		return $items;
	}

	/**
	 * Count non-empty terms in a taxonomy.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @return int
	 */
	private function term_total( $taxonomy ) {
		$count = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => true,
				'fields'     => 'count',
			)
		);
		return is_wp_error( $count ) ? 0 : (int) $count;
	}

	/**
	 * One page of term URLs.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @param int    $page     1-based page.
	 * @return array<int,array{loc:string,lastmod:string,image:string}>
	 */
	private function term_items( $taxonomy, $page ) {
		$size  = self::page_size();
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => true,
				'number'     => $size,
				'offset'     => ( $page - 1 ) * $size,
				'orderby'    => 'id',
			)
		);
		if ( is_wp_error( $terms ) ) {
			return array();
		}

		$items = array();
		foreach ( $terms as $term ) {
			$link = get_term_link( $term );
			if ( is_wp_error( $link ) ) {
				continue;
			}
			$items[] = array(
				'loc'     => (string) $link,
				'lastmod' => '',
				'image'   => '',
			);
		}
		return $items;
	}

	/* ---------- Cache + helpers ---------- */

	/**
	 * Configurable page size (cap on URLs per sub-sitemap).
	 *
	 * @return int
	 */
	private static function page_size() {
		$size = (int) apply_filters( 'sampoorna_seo_sitemap_page_size', self::PAGE_SIZE );
		return $size > 0 ? $size : self::PAGE_SIZE;
	}

	/**
	 * Current cache version (bumped on any content change).
	 *
	 * @return int
	 */
	private static function version() {
		return (int) get_option( self::OPT_VERSION, 1 );
	}

	/**
	 * Bump the cache version so stale sitemap transients orphan.
	 *
	 * @return void
	 */
	public function bump_version() {
		update_option( self::OPT_VERSION, self::version() + 1, false );
	}

	/**
	 * Bump the cache version on a real post save (skip revisions/autosaves).
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function on_change( $post_id ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		$this->bump_version();
	}

	/**
	 * Transient cache key for a document, namespaced by the cache version.
	 *
	 * @param string $subtype Subtype or 'index'.
	 * @param int    $page    Page number (0 for the index).
	 * @return string
	 */
	private function cache_key( $subtype, $page ) {
		return 'sseo_sm_' . md5( $subtype . '|' . $page . '|' . self::version() );
	}
}
