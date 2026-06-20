<?php
/**
 * HowTo schema from in-content step lists.
 *
 * Attaches a HowTo node to the @graph when a singular post reads as a how-to —
 * its title contains "how to" AND its content has an ordered list of at least
 * two steps. Nothing is fabricated: without that structure no node is emitted.
 * Hooks the `sampoorna_seo_schema_graph` filter like the other schema types.
 *
 * @package Sampoorna\SEO
 */

namespace Sampoorna\SEO\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds a HowTo node from an in-content ordered step list.
 */
class HowTo {

	/**
	 * Minimum steps required to emit a HowTo.
	 */
	const MIN_STEPS = 2;

	/**
	 * Singleton instance.
	 *
	 * @var HowTo|null
	 */
	private static $instance = null;

	/**
	 * Retrieve the singleton instance.
	 *
	 * @return HowTo
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Hook the schema graph filter.
	 */
	private function __construct() {
		add_filter( 'sampoorna_seo_schema_graph', array( $this, 'add_node' ), 10, 2 );
	}

	/**
	 * Attach a HowTo node for the current singular post when steps are present.
	 *
	 * @param array<int,array<string,mixed>> $nodes   Graph nodes.
	 * @param array<string,mixed>            $context { is_singular, post }.
	 * @return array<int,array<string,mixed>>
	 */
	public function add_node( $nodes, $context ) {
		$post = isset( $context['post'] ) ? $context['post'] : null;
		if ( ! $post instanceof \WP_Post ) {
			return $nodes;
		}
		$node = self::howto_node( get_the_title( $post ), (string) $post->post_content, (string) get_permalink( $post ) );
		if ( ! empty( $node ) ) {
			$nodes[] = $node;
		}
		return $nodes;
	}

	/**
	 * Build a HowTo node, or an empty array when the post is not a step-wise how-to.
	 *
	 * @param string $title   Post title (must read as "how to ...").
	 * @param string $content Post content HTML.
	 * @param string $url     Page URL (for the node @id).
	 * @return array<string,mixed>
	 */
	public static function howto_node( $title, $content, $url ) {
		if ( ! self::is_howto_title( $title ) ) {
			return array();
		}
		$steps = self::extract_steps( $content );
		if ( count( $steps ) < self::MIN_STEPS ) {
			return array();
		}

		$list     = array();
		$position = 1;
		foreach ( $steps as $step ) {
			$list[] = array(
				'@type'    => 'HowToStep',
				'position' => $position++,
				'text'     => $step,
			);
		}

		return array(
			'@type' => 'HowTo',
			'@id'   => $url . '#howto',
			'name'  => trim( (string) $title ),
			'step'  => $list,
		);
	}

	/**
	 * Whether a title reads as a how-to.
	 *
	 * @param string $title Post title.
	 * @return bool
	 */
	private static function is_howto_title( $title ) {
		return 1 === preg_match( '/\bhow\s+to\b/i', (string) $title );
	}

	/**
	 * Extract step texts from the first ordered list in the content.
	 *
	 * @param string $content Post content HTML.
	 * @return string[]
	 */
	private static function extract_steps( $content ) {
		$content = trim( (string) $content );
		if ( '' === $content || ! class_exists( '\DOMDocument' ) ) {
			return array();
		}

		$doc  = new \DOMDocument();
		$prev = libxml_use_internal_errors( true );
		$doc->loadHTML( '<html><head><meta charset="utf-8"></head><body>' . $content . '</body></html>' );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );

		$lists = $doc->getElementsByTagName( 'ol' );
		if ( 0 === $lists->length ) {
			return array();
		}
		$ol = $lists->item( 0 );
		if ( null === $ol ) {
			return array();
		}

		$steps = array();
		foreach ( $ol->childNodes as $child ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMNode::childNodes is a PHP DOM API property.
			if ( $child instanceof \DOMElement && 'li' === strtolower( $child->nodeName ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMNode::nodeName is a PHP DOM API property.
				$text = trim( (string) preg_replace( '/\s+/', ' ', $child->textContent ) ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMNode::textContent is a PHP DOM API property.
				if ( '' !== $text ) {
					$steps[] = $text;
				}
			}
		}
		return $steps;
	}
}
