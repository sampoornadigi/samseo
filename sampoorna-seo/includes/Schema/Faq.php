<?php
/**
 * FAQPage schema from in-content Q&A.
 *
 * Scans a singular post/page for question-style headings (H2–H4) each followed
 * by answer content and, when at least two pairs exist, attaches a FAQPage node
 * to the @graph via the `sampoorna_seo_schema_graph` filter. Nothing is
 * fabricated: the node only appears when real Q&A structure is present.
 *
 * @package Sampoorna\SEO
 */

namespace Sampoorna\SEO\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds a FAQPage node from extractable question/answer pairs.
 */
class Faq {

	/**
	 * Minimum Q&A pairs required to emit a FAQPage.
	 */
	const MIN_PAIRS = 2;

	/**
	 * Singleton instance.
	 *
	 * @var Faq|null
	 */
	private static $instance = null;

	/**
	 * Retrieve the singleton instance.
	 *
	 * @return Faq
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
	 * Attach a FAQPage node for the current singular post when Q&A is present.
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
		$node = self::faq_node( (string) $post->post_content, (string) get_permalink( $post ) );
		if ( ! empty( $node ) ) {
			$nodes[] = $node;
		}
		return $nodes;
	}

	/**
	 * Build a FAQPage node from content, or an empty array when <2 pairs.
	 *
	 * @param string $content Post content HTML.
	 * @param string $url     Page URL (for the node @id).
	 * @return array<string,mixed>
	 */
	public static function faq_node( $content, $url ) {
		$pairs = self::extract_pairs( $content );
		if ( count( $pairs ) < self::MIN_PAIRS ) {
			return array();
		}

		$questions = array();
		foreach ( $pairs as $pair ) {
			$questions[] = array(
				'@type'          => 'Question',
				'name'           => $pair['q'],
				'acceptedAnswer' => array(
					'@type' => 'Answer',
					'text'  => $pair['a'],
				),
			);
		}

		return array(
			'@type'      => 'FAQPage',
			'@id'        => $url . '#faq',
			'mainEntity' => $questions,
		);
	}

	/**
	 * Extract question/answer pairs: a question heading (H2–H4) followed by the
	 * text of the elements up to the next heading.
	 *
	 * @param string $content Post content HTML.
	 * @return array<int,array{q:string,a:string}>
	 */
	private static function extract_pairs( $content ) {
		$content = trim( (string) $content );
		if ( '' === $content || ! class_exists( '\DOMDocument' ) ) {
			return array();
		}

		$doc  = new \DOMDocument();
		$prev = libxml_use_internal_errors( true );
		$doc->loadHTML( '<html><head><meta charset="utf-8"></head><body>' . $content . '</body></html>' );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );

		$body = $doc->getElementsByTagName( 'body' )->item( 0 );
		if ( null === $body ) {
			return array();
		}

		$pairs   = array();
		$current = null;
		$answer  = '';
		foreach ( $body->getElementsByTagName( '*' ) as $el ) {
			$tag = strtolower( $el->nodeName ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMNode::nodeName is a PHP DOM API property.
			if ( in_array( $tag, array( 'h2', 'h3', 'h4' ), true ) ) {
				if ( null !== $current ) {
					$pairs[] = array(
						'q' => $current,
						'a' => trim( $answer ),
					);
				}
				$text    = self::node_text( $el );
				$current = self::is_question( $text ) ? $text : null;
				$answer  = '';
			} elseif ( null !== $current && in_array( $tag, array( 'p', 'ul', 'ol' ), true ) ) {
				$answer .= ( '' === $answer ? '' : ' ' ) . self::node_text( $el );
			}
		}
		if ( null !== $current ) {
			$pairs[] = array(
				'q' => $current,
				'a' => trim( $answer ),
			);
		}

		return array_values(
			array_filter(
				$pairs,
				static function ( $pair ) {
					return '' !== $pair['q'] && '' !== $pair['a'];
				}
			)
		);
	}

	/**
	 * Collapsed text content of a DOM element.
	 *
	 * @param \DOMElement $el Element.
	 * @return string
	 */
	private static function node_text( $el ) {
		return trim( (string) preg_replace( '/\s+/', ' ', $el->textContent ) ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMNode::textContent is a PHP DOM API property.
	}

	/**
	 * Whether a heading reads as a question.
	 *
	 * @param string $heading Heading text.
	 * @return bool
	 */
	private static function is_question( $heading ) {
		$heading = trim( (string) $heading );
		if ( '' === $heading ) {
			return false;
		}
		if ( false !== strpos( $heading, '?' ) ) {
			return true;
		}
		return 1 === preg_match( '/^(who|what|why|how|when|where|which|is|are|can|does|do|should|will)\b/i', $heading );
	}
}
