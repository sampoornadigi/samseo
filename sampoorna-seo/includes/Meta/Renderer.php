<?php
/**
 * Server-side SEO output renderer (wp_head).
 *
 * Emits the document title, meta description, canonical, robots, Open Graph and
 * Twitter tags from per-object meta + templates — all server-side, so Google
 * receives correct markup with no client-side injection. Held to a strict
 * performance budget: local post meta + options only, no remote calls, computed
 * once per request. Defensive by design: a failure here must never break wp_head.
 *
 * @package Sampoorna\SEO
 */

namespace Sampoorna\SEO\Meta;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders SEO tags into the front-end document head.
 */
class Renderer {

	/**
	 * Singleton instance.
	 *
	 * @var Renderer|null
	 */
	private static $instance = null;

	/**
	 * Retrieve the singleton instance.
	 *
	 * @return Renderer
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Wire front-end head filters/actions.
	 */
	private function __construct() {
		add_filter( 'pre_get_document_title', array( $this, 'filter_title' ), 20 );
		add_filter( 'wp_robots', array( $this, 'filter_robots' ) );
		remove_action( 'wp_head', 'rel_canonical' );
		add_action( 'wp_head', array( $this, 'output' ), 1 );
	}

	/**
	 * Override the document title for contexts we own.
	 *
	 * @param string $title Title computed by core/theme.
	 * @return string
	 */
	public function filter_title( $title ) {
		try {
			$computed = $this->computed_title();
			return '' !== $computed ? $computed : $title;
		} catch ( \Throwable $e ) {
			return $title;
		}
	}

	/**
	 * Merge per-object noindex/nofollow into the core robots directives.
	 *
	 * @param array<string,mixed> $robots Robots directives.
	 * @return array<string,mixed>
	 */
	public function filter_robots( $robots ) {
		try {
			$post = $this->singular_post();
			$term = $post ? null : $this->queried_term();
			if ( ! $post && ! $term ) {
				return $robots;
			}
			if ( '' !== $this->meta_get( 'robots_noindex' ) ) {
				$robots['noindex'] = true;
				unset( $robots['index'] );
			}
			if ( '' !== $this->meta_get( 'robots_nofollow' ) ) {
				$robots['nofollow'] = true;
				unset( $robots['follow'] );
			}
		} catch ( \Throwable $e ) {
			return $robots;
		}
		return $robots;
	}

	/**
	 * Echo description, canonical, Open Graph and Twitter tags.
	 *
	 * @return void
	 */
	public function output() {
		try {
			if ( is_feed() || is_robots() || is_404() ) {
				return;
			}
			if ( ! is_singular() && ! is_front_page() && ! is_home() && ! $this->is_term_archive() ) {
				return;
			}

			$title       = $this->computed_title();
			$description = $this->computed_description();
			$canonical   = $this->computed_canonical();
			$post        = $this->singular_post();

			$ogt      = $this->meta_get( 'og_title' );
			$ogd      = $this->meta_get( 'og_desc' );
			$og_title = '' !== $ogt ? $ogt : $title;
			$og_desc  = '' !== $ogd ? $ogd : $description;
			$og_image = $post ? $this->computed_og_image( $post ) : $this->meta_get( 'og_image' );
			$og_type  = is_singular( 'post' ) ? 'article' : 'website';

			echo "\n<!-- Sampoorna SEO -->\n";

			if ( '' !== $description ) {
				printf( '<meta name="description" content="%s" />' . "\n", esc_attr( $description ) );
			}
			if ( '' !== $canonical ) {
				printf( '<link rel="canonical" href="%s" />' . "\n", esc_url( $canonical ) );
			}

			if ( '' !== $og_title ) {
				printf( '<meta property="og:title" content="%s" />' . "\n", esc_attr( $og_title ) );
			}
			if ( '' !== $og_desc ) {
				printf( '<meta property="og:description" content="%s" />' . "\n", esc_attr( $og_desc ) );
			}
			printf( '<meta property="og:type" content="%s" />' . "\n", esc_attr( $og_type ) );
			printf( '<meta property="og:site_name" content="%s" />' . "\n", esc_attr( get_bloginfo( 'name' ) ) );
			if ( '' !== $canonical ) {
				printf( '<meta property="og:url" content="%s" />' . "\n", esc_url( $canonical ) );
			}
			if ( '' !== $og_image ) {
				printf( '<meta property="og:image" content="%s" />' . "\n", esc_url( $og_image ) );
			}

			$card = '' !== $og_image ? 'summary_large_image' : 'summary';
			printf( '<meta name="twitter:card" content="%s" />' . "\n", esc_attr( $card ) );
			if ( '' !== $og_title ) {
				printf( '<meta name="twitter:title" content="%s" />' . "\n", esc_attr( $og_title ) );
			}
			if ( '' !== $og_desc ) {
				printf( '<meta name="twitter:description" content="%s" />' . "\n", esc_attr( $og_desc ) );
			}
			if ( '' !== $og_image ) {
				printf( '<meta name="twitter:image" content="%s" />' . "\n", esc_url( $og_image ) );
			}

			echo "<!-- /Sampoorna SEO -->\n";
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/**
	 * The current queried post when the request is a singular view.
	 *
	 * @return \WP_Post|null
	 */
	private function singular_post() {
		if ( ! is_singular() ) {
			return null;
		}
		$post = get_queried_object();
		return $post instanceof \WP_Post ? $post : null;
	}

	/**
	 * The current queried term when the request is a category/tag/taxonomy archive.
	 *
	 * @return \WP_Term|null
	 */
	private function queried_term() {
		if ( ! is_category() && ! is_tag() && ! is_tax() ) {
			return null;
		}
		$term = get_queried_object();
		return $term instanceof \WP_Term ? $term : null;
	}

	/**
	 * Whether the current request is a term archive we render.
	 *
	 * @return bool
	 */
	private function is_term_archive() {
		return null !== $this->queried_term();
	}

	/**
	 * Read an override field for the current context (post, else term).
	 *
	 * @param string $field Logical field name.
	 * @return string
	 */
	private function meta_get( $field ) {
		$post = $this->singular_post();
		if ( $post ) {
			return MetaStore::get( $post->ID, $field );
		}
		$term = $this->queried_term();
		if ( $term ) {
			return TermMeta::get( $term->term_id, $field );
		}
		return '';
	}

	/**
	 * Resolve the document title for the current context.
	 *
	 * @return string
	 */
	private function computed_title() {
		$post = $this->singular_post();
		if ( $post ) {
			$context  = array(
				'post'  => $post,
				'title' => get_the_title( $post ),
			);
			$override = MetaStore::get( $post->ID, 'title' );
			$template = '' !== $override ? $override : (string) get_option( 'sampoorna_seo_title_template', '%title% %sep% %sitename%' );
			return TemplateEngine::render( $template, $context );
		}
		$term = $this->queried_term();
		if ( $term ) {
			$override = TermMeta::get( $term->term_id, 'title' );
			$template = '' !== $override ? $override : (string) get_option( 'sampoorna_seo_tax_title_template', '%title% %sep% %sitename%' );
			return TemplateEngine::render( $template, array( 'title' => $term->name ) );
		}
		if ( is_front_page() ) {
			$name    = get_bloginfo( 'name' );
			$tagline = get_bloginfo( 'description' );
			return '' !== $tagline ? $name . ' ' . TemplateEngine::separator() . ' ' . $tagline : $name;
		}
		return '';
	}

	/**
	 * Resolve the meta description for the current context.
	 *
	 * @return string
	 */
	private function computed_description() {
		$post = $this->singular_post();
		if ( $post ) {
			$override = MetaStore::get( $post->ID, 'desc' );
			if ( '' !== $override ) {
				return TemplateEngine::render( $override, array( 'post' => $post ) );
			}
			$template = (string) get_option( 'sampoorna_seo_desc_template', '%excerpt%' );
			return TemplateEngine::render( $template, array( 'post' => $post ) );
		}
		$term = $this->queried_term();
		if ( $term ) {
			$override = TermMeta::get( $term->term_id, 'desc' );
			if ( '' !== $override ) {
				return TemplateEngine::render( $override, array( 'title' => $term->name ) );
			}
			return trim( (string) wp_strip_all_tags( (string) $term->description ) );
		}
		if ( is_front_page() ) {
			return get_bloginfo( 'description' );
		}
		return '';
	}

	/**
	 * Resolve the canonical URL for the current context.
	 *
	 * @return string
	 */
	private function computed_canonical() {
		$post = $this->singular_post();
		if ( $post ) {
			$override = MetaStore::get( $post->ID, 'canonical' );
			if ( '' !== $override ) {
				return $override;
			}
			$canonical = wp_get_canonical_url( $post );
			return false !== $canonical ? $canonical : (string) get_permalink( $post );
		}
		$term = $this->queried_term();
		if ( $term ) {
			$override = TermMeta::get( $term->term_id, 'canonical' );
			if ( '' !== $override ) {
				return $override;
			}
			$link = get_term_link( $term );
			return is_wp_error( $link ) ? '' : (string) $link;
		}
		if ( is_front_page() ) {
			return home_url( '/' );
		}
		return '';
	}

	/**
	 * Resolve the Open Graph image URL (explicit override or featured image).
	 *
	 * @param \WP_Post|null $post Current post, if singular.
	 * @return string
	 */
	private function computed_og_image( $post ) {
		if ( ! $post ) {
			return '';
		}
		$override = MetaStore::get( $post->ID, 'og_image' );
		if ( '' !== $override ) {
			return $override;
		}
		if ( has_post_thumbnail( $post ) ) {
			$src = wp_get_attachment_image_url( get_post_thumbnail_id( $post ), 'full' );
			return $src ? $src : '';
		}
		return '';
	}
}
