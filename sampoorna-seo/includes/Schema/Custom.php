<?php
/**
 * Custom JSON-LD nodes (AEO/GEO auto-deploy).
 *
 * The control plane generates answer-engine schema (FAQPage / Article / HowTo) and
 * deploys it per post via the signed /apply path into the `schema_jsonld` meta
 * (an array of nodes). This module folds those nodes into the connected sitewide
 *
 * @graph for the current singular page, so they render valid + linked — no separate
 * <script> blocks, no duplicate @context.
 *
 * @package Sampoorna\SEO
 */

namespace Sampoorna\SEO\Schema;

use Sampoorna\SEO\Meta\MetaStore;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Appends a post's deployed JSON-LD nodes to the schema @graph.
 */
class Custom {

	/**
	 * Singleton instance.
	 *
	 * @var Custom|null
	 */
	private static $instance = null;

	/**
	 * Retrieve the singleton instance.
	 *
	 * @return Custom
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Hook into the schema graph filter (after the built-in nodes).
	 */
	private function __construct() {
		add_filter( 'sampoorna_seo_schema_graph', array( $this, 'add_nodes' ), 20, 2 );
	}

	/**
	 * Append the current post's deployed JSON-LD nodes to the @graph.
	 *
	 * @param array<int,array<string,mixed>> $nodes   Existing graph nodes.
	 * @param array<string,mixed>            $context { is_singular, post }.
	 * @return array<int,array<string,mixed>>
	 */
	public function add_nodes( $nodes, $context ) {
		$post = isset( $context['post'] ) ? $context['post'] : null;
		if ( ! $post instanceof \WP_Post ) {
			return $nodes;
		}
		$raw = MetaStore::get( $post->ID, 'schema_jsonld' );
		if ( '' === $raw ) {
			return $nodes;
		}
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			return $nodes;
		}
		foreach ( $decoded as $node ) {
			if ( is_array( $node ) && isset( $node['@type'] ) ) {
				unset( $node['@context'] ); // the @graph carries a single sitewide @context
				$nodes[] = $node;
			}
		}
		return $nodes;
	}
}
