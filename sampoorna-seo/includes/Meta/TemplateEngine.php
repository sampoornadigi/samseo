<?php
/**
 * Title/description template-variable engine.
 *
 * Resolves Sampoorna SEO template tokens (e.g. %title%, %sitename%, %sep%) into
 * final strings. Phase 3's migration normalizer translates Yoast/Rank Math/AIOSEO
 * token syntaxes into these canonical tokens.
 *
 * @package Sampoorna\SEO
 */

namespace Sampoorna\SEO\Meta;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stateless renderer for template tokens.
 */
class TemplateEngine {

	/**
	 * The configured title separator (option-backed, defaults to a hyphen).
	 *
	 * @return string
	 */
	public static function separator() {
		$sep = (string) get_option( 'sampoorna_seo_sep', '-' );
		return '' !== $sep ? $sep : '-';
	}

	/**
	 * Render a template string against a context.
	 *
	 * Recognized tokens: %title% %sitename% %tagline% %sep% %excerpt% %category%
	 * %page% %currentyear% %searchphrase% %archive_title%. Unknown %tokens% are
	 * stripped; surplus whitespace is collapsed.
	 *
	 * @param string              $template Template string.
	 * @param array<string,mixed> $context  Optional context: 'title', 'post' (\WP_Post), 'excerpt'.
	 * @return string
	 */
	public static function render( $template, array $context = array() ) {
		$template = (string) $template;
		if ( '' === $template ) {
			return '';
		}

		$post  = isset( $context['post'] ) && $context['post'] instanceof \WP_Post ? $context['post'] : null;
		$title = isset( $context['title'] ) ? (string) $context['title'] : ( $post ? get_the_title( $post ) : '' );

		$excerpt = isset( $context['excerpt'] ) ? (string) $context['excerpt'] : '';
		if ( '' === $excerpt && $post ) {
			$excerpt = self::excerpt_from_post( $post );
		}

		$category = '';
		if ( $post ) {
			$terms = get_the_terms( $post, 'category' );
			if ( is_array( $terms ) && ! empty( $terms ) ) {
				$category = $terms[0]->name;
			}
		}

		$page = (int) get_query_var( 'paged' );
		if ( $page < 1 ) {
			$page = (int) get_query_var( 'page' );
		}

		$replacements = array(
			'%title%'         => $title,
			'%sitename%'      => get_bloginfo( 'name' ),
			'%tagline%'       => get_bloginfo( 'description' ),
			'%sep%'           => self::separator(),
			'%excerpt%'       => $excerpt,
			'%category%'      => $category,
			'%page%'          => $page > 1 ? (string) $page : '',
			'%currentyear%'   => gmdate( 'Y' ),
			'%searchphrase%'  => get_search_query(),
			'%archive_title%' => $title,
		);

		$out = strtr( $template, $replacements );

		// Strip any unrecognized %tokens% and tidy whitespace.
		$out = (string) preg_replace( '/%[a-z_]+%/', '', $out );
		$out = (string) preg_replace( '/\s{2,}/', ' ', $out );
		return trim( $out );
	}

	/**
	 * Derive a plain-text excerpt from a post (manual excerpt or trimmed content).
	 *
	 * @param \WP_Post $post Post object.
	 * @return string
	 */
	private static function excerpt_from_post( \WP_Post $post ) {
		if ( '' !== trim( (string) $post->post_excerpt ) ) {
			return wp_strip_all_tags( (string) $post->post_excerpt );
		}
		$content = wp_strip_all_tags( strip_shortcodes( (string) $post->post_content ) );
		$content = (string) preg_replace( '/\s+/', ' ', $content );
		return wp_trim_words( $content, 30, '' );
	}
}
