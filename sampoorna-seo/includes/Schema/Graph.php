<?php
/**
 * JSON-LD @graph schema engine.
 *
 * Emits a single connected schema.org @graph into wp_head: sitewide
 * Organization + WebSite anchor it, each request adds WebPage + BreadcrumbList,
 * and posts add Article + Person — all linked by stable @id references. Other
 * modules attach nodes via the `sampoorna_seo_schema_graph` filter rather than
 * printing separate blocks. No fabrication: empty properties are pruned.
 *
 * @package Sampoorna\SEO
 */

namespace Sampoorna\SEO\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds and outputs the site's connected JSON-LD graph.
 */
class Graph {

	const OPT_ORG_NAME = 'sampoorna_seo_org_name';
	const OPT_ORG_LOGO = 'sampoorna_seo_org_logo';
	const OPT_SOCIAL   = 'sampoorna_seo_social';

	/**
	 * Singleton instance.
	 *
	 * @var Graph|null
	 */
	private static $instance = null;

	/**
	 * Retrieve the singleton instance.
	 *
	 * @return Graph
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
		add_action( 'wp_head', array( $this, 'output' ), 5 );
	}

	/**
	 * Echo the JSON-LD graph for the current request.
	 *
	 * @return void
	 */
	public function output() {
		if ( is_feed() || is_robots() || is_404() ) {
			return;
		}
		$graph = $this->build_graph();
		if ( empty( $graph ) ) {
			return;
		}
		$json = wp_json_encode(
			array(
				'@context' => 'https://schema.org',
				'@graph'   => array_values( $graph ),
			),
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
		);
		if ( ! is_string( $json ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON encoded with JSON_HEX_TAG/AMP/APOS/QUOT cannot break out of the script element.
		echo "\n" . '<script type="application/ld+json">' . $json . '</script>' . "\n";
	}

	/**
	 * Build the connected node graph for the current context.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function build_graph() {
		$home  = home_url( '/' );
		$nodes = array(
			$this->organization_node( $home ),
			$this->website_node( $home ),
		);

		$post = is_singular() ? get_queried_object() : null;
		if ( $post instanceof \WP_Post ) {
			$url     = (string) get_permalink( $post );
			$nodes[] = $this->webpage_node( $post, $url, $home, true );
			$nodes[] = $this->breadcrumb_node( $post, $url, $home );
			if ( is_singular( 'post' ) ) {
				$nodes[] = $this->article_node( $post, $url, $home );
				$nodes[] = $this->person_node( (int) $post->post_author, $home );
			}
		} elseif ( is_front_page() ) {
			$nodes[] = $this->webpage_node( null, $home, $home, false );
		}

		/**
		 * Filter the schema @graph nodes before output. Add FAQ/Product/etc. here.
		 *
		 * @param array<int,array<string,mixed>> $nodes   Graph nodes.
		 * @param array<string,mixed>            $context { is_singular, post }.
		 */
		$nodes = apply_filters(
			'sampoorna_seo_schema_graph',
			$nodes,
			array(
				'is_singular' => is_singular(),
				'post'        => $post,
			)
		);

		$clean = array();
		foreach ( (array) $nodes as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			$node = $this->prune( $node );
			if ( isset( $node['@type'], $node['@id'] ) ) {
				$clean[] = $node;
			}
		}
		return $clean;
	}

	/* ---------- Node builders ---------- */

	/**
	 * Organization node.
	 *
	 * @param string $home Home URL.
	 * @return array<string,mixed>
	 */
	private function organization_node( $home ) {
		$node = array(
			'@type' => 'Organization',
			'@id'   => $home . '#organization',
			'name'  => $this->org_name(),
			'url'   => $home,
		);
		$logo = $this->logo_url();
		if ( '' !== $logo ) {
			$node['logo']  = array(
				'@type' => 'ImageObject',
				'@id'   => $home . '#logo',
				'url'   => $logo,
			);
			$node['image'] = array( '@id' => $home . '#logo' );
		}
		$social = $this->social_urls();
		if ( ! empty( $social ) ) {
			$node['sameAs'] = $social;
		}
		return $node;
	}

	/**
	 * WebSite node.
	 *
	 * @param string $home Home URL.
	 * @return array<string,mixed>
	 */
	private function website_node( $home ) {
		return array(
			'@type'           => 'WebSite',
			'@id'             => $home . '#website',
			'url'             => $home,
			'name'            => get_bloginfo( 'name' ),
			'inLanguage'      => get_bloginfo( 'language' ),
			'publisher'       => array( '@id' => $home . '#organization' ),
			'potentialAction' => array(
				array(
					'@type'       => 'SearchAction',
					'target'      => array(
						'@type'       => 'EntryPoint',
						'urlTemplate' => $home . '?s={search_term_string}',
					),
					'query-input' => 'required name=search_term_string',
				),
			),
		);
	}

	/**
	 * WebPage node.
	 *
	 * @param \WP_Post|null $post           Post, or null for the front page.
	 * @param string        $url            Page URL.
	 * @param string        $home           Home URL.
	 * @param bool          $with_breadcrumb Whether a BreadcrumbList node exists.
	 * @return array<string,mixed>
	 */
	private function webpage_node( $post, $url, $home, $with_breadcrumb ) {
		$node = array(
			'@type'      => 'WebPage',
			'@id'        => $url . '#webpage',
			'url'        => $url,
			'name'       => $post instanceof \WP_Post ? get_the_title( $post ) : get_bloginfo( 'name' ),
			'isPartOf'   => array( '@id' => $home . '#website' ),
			'inLanguage' => get_bloginfo( 'language' ),
		);
		if ( $with_breadcrumb ) {
			$node['breadcrumb'] = array( '@id' => $url . '#breadcrumb' );
		}
		if ( $post instanceof \WP_Post ) {
			$node['datePublished'] = mysql2date( DATE_W3C, $post->post_date_gmt, false );
			$node['dateModified']  = mysql2date( DATE_W3C, $post->post_modified_gmt, false );
			$image                 = $this->featured_image_url( $post );
			if ( '' !== $image ) {
				$node['primaryImageOfPage'] = array(
					'@type' => 'ImageObject',
					'url'   => $image,
				);
			}
		}
		return $node;
	}

	/**
	 * BreadcrumbList node from the post's ancestry.
	 *
	 * @param \WP_Post $post Post.
	 * @param string   $url  Page URL.
	 * @param string   $home Home URL.
	 * @return array<string,mixed>
	 */
	private function breadcrumb_node( $post, $url, $home ) {
		$items    = array();
		$position = 1;
		$items[]  = $this->crumb( $position++, __( 'Home', 'sampoorna-seo' ), $home );
		foreach ( array_reverse( get_post_ancestors( $post ) ) as $ancestor_id ) {
			$items[] = $this->crumb( $position++, get_the_title( $ancestor_id ), (string) get_permalink( $ancestor_id ) );
		}
		$items[] = $this->crumb( $position, get_the_title( $post ), $url );

		return array(
			'@type'           => 'BreadcrumbList',
			'@id'             => $url . '#breadcrumb',
			'itemListElement' => $items,
		);
	}

	/**
	 * Build one breadcrumb ListItem.
	 *
	 * @param int    $position 1-based position.
	 * @param string $name     Crumb label.
	 * @param string $item_url Crumb URL.
	 * @return array<string,mixed>
	 */
	private function crumb( $position, $name, $item_url ) {
		return array(
			'@type'    => 'ListItem',
			'position' => $position,
			'name'     => $name,
			'item'     => $item_url,
		);
	}

	/**
	 * Article node for a post.
	 *
	 * @param \WP_Post $post Post.
	 * @param string   $url  Page URL.
	 * @param string   $home Home URL.
	 * @return array<string,mixed>
	 */
	private function article_node( $post, $url, $home ) {
		$node  = array(
			'@type'            => 'Article',
			'@id'              => $url . '#article',
			'headline'         => get_the_title( $post ),
			'datePublished'    => mysql2date( DATE_W3C, $post->post_date_gmt, false ),
			'dateModified'     => mysql2date( DATE_W3C, $post->post_modified_gmt, false ),
			'author'           => array( '@id' => $home . '#/person/' . (int) $post->post_author ),
			'publisher'        => array( '@id' => $home . '#organization' ),
			'mainEntityOfPage' => array( '@id' => $url . '#webpage' ),
			'isPartOf'         => array( '@id' => $url . '#webpage' ),
		);
		$image = $this->featured_image_url( $post );
		if ( '' !== $image ) {
			$node['image'] = array(
				'@type' => 'ImageObject',
				'url'   => $image,
			);
		}
		return $node;
	}

	/**
	 * Person node for a post author.
	 *
	 * @param int    $author_id Author user ID.
	 * @param string $home      Home URL.
	 * @return array<string,mixed>
	 */
	private function person_node( $author_id, $home ) {
		return array(
			'@type' => 'Person',
			'@id'   => $home . '#/person/' . $author_id,
			'name'  => get_the_author_meta( 'display_name', $author_id ),
			'url'   => get_author_posts_url( $author_id ),
		);
	}

	/* ---------- Helpers ---------- */

	/**
	 * Organization name (option, falling back to the site title).
	 *
	 * @return string
	 */
	private function org_name() {
		$name = trim( (string) get_option( self::OPT_ORG_NAME, '' ) );
		return '' !== $name ? $name : get_bloginfo( 'name' );
	}

	/**
	 * Organization logo URL (option, custom logo, then site icon).
	 *
	 * @return string
	 */
	private function logo_url() {
		$opt = trim( (string) get_option( self::OPT_ORG_LOGO, '' ) );
		if ( '' !== $opt ) {
			return $opt;
		}
		$custom = get_theme_mod( 'custom_logo' );
		if ( $custom ) {
			$src = wp_get_attachment_image_url( (int) $custom, 'full' );
			if ( $src ) {
				return $src;
			}
		}
		$icon = get_site_icon_url();
		return $icon ? $icon : '';
	}

	/**
	 * Social profile URLs for sameAs.
	 *
	 * @return string[]
	 */
	private function social_urls() {
		$urls = get_option( self::OPT_SOCIAL, array() );
		if ( ! is_array( $urls ) ) {
			return array();
		}
		return array_values( array_filter( array_map( 'strval', $urls ) ) );
	}

	/**
	 * Featured image URL for a post.
	 *
	 * @param \WP_Post $post Post.
	 * @return string
	 */
	private function featured_image_url( $post ) {
		$thumb_id = get_post_thumbnail_id( $post );
		if ( ! $thumb_id ) {
			return '';
		}
		$src = wp_get_attachment_image_url( (int) $thumb_id, 'full' );
		return $src ? $src : '';
	}

	/**
	 * Recursively drop null/empty values so we never emit blank properties.
	 *
	 * @param array<string,mixed> $data Node data.
	 * @return array<string,mixed>
	 */
	private function prune( array $data ) {
		foreach ( $data as $key => $value ) {
			if ( is_array( $value ) ) {
				$value = $this->prune( $value );
				if ( empty( $value ) ) {
					unset( $data[ $key ] );
				} else {
					$data[ $key ] = $value;
				}
			} elseif ( null === $value || '' === $value ) {
				unset( $data[ $key ] );
			}
		}
		return $data;
	}
}
