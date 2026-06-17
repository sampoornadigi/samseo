<?php
/**
 * Product schema for WooCommerce product pages.
 *
 * When viewing a single WooCommerce product, attaches a Product node (with an
 * Offer and, when reviews exist, an AggregateRating) to the @graph via the
 * `sampoorna_seo_schema_graph` filter. WooCommerce-gated: a no-op when the
 * plugin is inactive or the request is not a product page.
 *
 * @package Sampoorna\SEO
 */

namespace Sampoorna\SEO\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds a Product node from a WooCommerce product.
 */
class Product {

	/**
	 * Singleton instance.
	 *
	 * @var Product|null
	 */
	private static $instance = null;

	/**
	 * Retrieve the singleton instance.
	 *
	 * @return Product
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
	 * Attach a Product node when on a single WooCommerce product.
	 *
	 * @param array<int,array<string,mixed>> $nodes   Graph nodes.
	 * @param array<string,mixed>            $context { is_singular, post }.
	 * @return array<int,array<string,mixed>>
	 */
	public function add_node( $nodes, $context ) {
		if ( ! function_exists( 'wc_get_product' ) || ! function_exists( 'is_product' ) || ! is_product() ) {
			return $nodes;
		}
		$post = isset( $context['post'] ) ? $context['post'] : null;
		if ( ! $post instanceof \WP_Post ) {
			return $nodes;
		}
		$product = wc_get_product( $post->ID );
		if ( ! $product ) {
			return $nodes;
		}

		$data = self::product_data( $product );
		$node = self::product_node( $data, (string) get_permalink( $post ) );
		if ( ! empty( $node ) ) {
			$nodes[] = $node;
		}
		return $nodes;
	}

	/**
	 * Extract a plain data array from a WooCommerce product object.
	 *
	 * Kept separate from the node builder so the builder stays unit-testable
	 * without WooCommerce loaded.
	 *
	 * @param object $product WC_Product instance.
	 * @return array<string,mixed>
	 */
	private static function product_data( $product ) {
		$image    = '';
		$image_id = (int) $product->get_image_id();
		if ( $image_id ) {
			$src   = wp_get_attachment_image_url( $image_id, 'full' );
			$image = $src ? $src : '';
		}

		$data = array(
			'name'        => $product->get_name(),
			'description' => wp_strip_all_tags( (string) $product->get_short_description() ),
			'sku'         => $product->get_sku(),
			'image'       => $image,
			'price'       => $product->get_price(),
			'currency'    => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '',
			'in_stock'    => $product->is_in_stock(),
		);

		if ( $product->get_rating_count() > 0 ) {
			$data['rating']       = (float) $product->get_average_rating();
			$data['review_count'] = (int) $product->get_review_count();
		}

		return $data;
	}

	/**
	 * Build a Product node from a plain data array.
	 *
	 * @param array<string,mixed> $data { name, description, sku, image, price, currency, in_stock, rating, review_count }.
	 * @param string              $url  Product URL (for @id and the Offer url).
	 * @return array<string,mixed>
	 */
	public static function product_node( array $data, $url ) {
		$name = isset( $data['name'] ) ? trim( (string) $data['name'] ) : '';
		if ( '' === $name ) {
			return array();
		}

		$node = array(
			'@type'       => 'Product',
			'@id'         => $url . '#product',
			'name'        => $name,
			'description' => isset( $data['description'] ) ? (string) $data['description'] : '',
			'sku'         => isset( $data['sku'] ) ? (string) $data['sku'] : '',
			'image'       => isset( $data['image'] ) ? (string) $data['image'] : '',
		);

		$price = isset( $data['price'] ) ? (string) $data['price'] : '';
		if ( '' !== $price ) {
			$node['offers'] = array(
				'@type'         => 'Offer',
				'price'         => $price,
				'priceCurrency' => isset( $data['currency'] ) ? (string) $data['currency'] : '',
				'availability'  => ! empty( $data['in_stock'] ) ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
				'url'           => $url,
			);
		}

		if ( isset( $data['rating'], $data['review_count'] ) && (int) $data['review_count'] > 0 ) {
			$node['aggregateRating'] = array(
				'@type'       => 'AggregateRating',
				'ratingValue' => (string) $data['rating'],
				'reviewCount' => (int) $data['review_count'],
			);
		}

		return $node;
	}
}
