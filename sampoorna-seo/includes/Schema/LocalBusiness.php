<?php
/**
 * LocalBusiness schema enrichment.
 *
 * For a single-location business, enriches the sitewide Organization node in
 * place — promoting its @type to a LocalBusiness subtype and adding the
 * address, telephone, geo, and price range from settings — rather than adding
 * a competing node. Configured via the `sampoorna_seo_local` option; a no-op
 * when unconfigured.
 *
 * @package Sampoorna\SEO
 */

namespace Sampoorna\SEO\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enriches the Organization node with LocalBusiness data.
 */
class LocalBusiness {

	const OPT_LOCAL = 'sampoorna_seo_local';

	/**
	 * Allowed LocalBusiness subtypes offered in settings.
	 *
	 * @return array<string,string>
	 */
	public static function types() {
		return array(
			'LocalBusiness'       => __( 'Local business (generic)', 'sampoorna-seo' ),
			'ProfessionalService' => __( 'Professional service', 'sampoorna-seo' ),
			'Store'               => __( 'Store', 'sampoorna-seo' ),
			'Restaurant'          => __( 'Restaurant', 'sampoorna-seo' ),
			'MedicalBusiness'     => __( 'Medical business', 'sampoorna-seo' ),
		);
	}

	/**
	 * Singleton instance.
	 *
	 * @var LocalBusiness|null
	 */
	private static $instance = null;

	/**
	 * Retrieve the singleton instance.
	 *
	 * @return LocalBusiness
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
		add_filter( 'sampoorna_seo_schema_graph', array( $this, 'enrich_graph' ) );
	}

	/**
	 * Enrich the Organization node in the graph from saved settings.
	 *
	 * @param array<int,array<string,mixed>> $nodes Graph nodes.
	 * @return array<int,array<string,mixed>>
	 */
	public function enrich_graph( $nodes ) {
		$settings = get_option( self::OPT_LOCAL, array() );
		if ( ! is_array( $settings ) || empty( $settings ) ) {
			return $nodes;
		}
		foreach ( $nodes as $i => $node ) {
			if ( is_array( $node ) && isset( $node['@id'] ) && self::is_org_id( (string) $node['@id'] ) ) {
				$nodes[ $i ] = self::enrich( $node, $settings );
				break;
			}
		}
		return $nodes;
	}

	/**
	 * Whether an @id is the Organization node.
	 *
	 * @param string $id Node @id.
	 * @return bool
	 */
	private static function is_org_id( $id ) {
		return '#organization' === substr( $id, -13 );
	}

	/**
	 * Enrich an Organization node with LocalBusiness properties.
	 *
	 * @param array<string,mixed> $org      Organization node.
	 * @param array<string,mixed> $settings { type, street, locality, region, postal, country, telephone, lat, lng, price_range }.
	 * @return array<string,mixed>
	 */
	public static function enrich( array $org, array $settings ) {
		$type = isset( $settings['type'] ) ? trim( (string) $settings['type'] ) : '';
		if ( '' === $type || ! array_key_exists( $type, self::types() ) ) {
			$type = 'LocalBusiness';
		}
		$org['@type'] = $type;

		$address = self::filter_empty(
			array(
				'@type'           => 'PostalAddress',
				'streetAddress'   => self::str( $settings, 'street' ),
				'addressLocality' => self::str( $settings, 'locality' ),
				'addressRegion'   => self::str( $settings, 'region' ),
				'postalCode'      => self::str( $settings, 'postal' ),
				'addressCountry'  => self::str( $settings, 'country' ),
			)
		);
		// Only attach the address when it carries more than the @type marker.
		if ( count( $address ) > 1 ) {
			$org['address'] = $address;
		}

		$telephone = self::str( $settings, 'telephone' );
		if ( '' !== $telephone ) {
			$org['telephone'] = $telephone;
		}

		$price = self::str( $settings, 'price_range' );
		if ( '' !== $price ) {
			$org['priceRange'] = $price;
		}

		$lat = isset( $settings['lat'] ) && '' !== $settings['lat'] ? (float) $settings['lat'] : null;
		$lng = isset( $settings['lng'] ) && '' !== $settings['lng'] ? (float) $settings['lng'] : null;
		if ( null !== $lat && null !== $lng ) {
			$org['geo'] = array(
				'@type'     => 'GeoCoordinates',
				'latitude'  => $lat,
				'longitude' => $lng,
			);
		}

		return $org;
	}

	/**
	 * Trimmed string value from a settings array.
	 *
	 * @param array<string,mixed> $settings Settings.
	 * @param string              $key      Key.
	 * @return string
	 */
	private static function str( array $settings, $key ) {
		return isset( $settings[ $key ] ) ? trim( (string) $settings[ $key ] ) : '';
	}

	/**
	 * Drop empty string values from an array.
	 *
	 * @param array<string,mixed> $data Data.
	 * @return array<string,mixed>
	 */
	private static function filter_empty( array $data ) {
		return array_filter(
			$data,
			static function ( $value ) {
				return '' !== $value && null !== $value;
			}
		);
	}
}
