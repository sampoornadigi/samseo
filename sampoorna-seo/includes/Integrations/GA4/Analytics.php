<?php
/**
 * Google Analytics 4 (Data API) reader.
 *
 * Reuses the Search Console OAuth connection (the shared consent now includes
 * the analytics.readonly scope) plus a GA4 numeric property id, and reads a
 * 28-day traffic summary via the Analytics Data API. No external Composer
 * dependency — requests go through the WordPress HTTP API with the OAuth bearer
 * token. Inactive (a no-op) until connected and a property id is set.
 *
 * @package Sampoorna\SEO
 */

namespace Sampoorna\SEO\Integrations\GA4;

use Sampoorna\SEO\Integrations\GSC\OAuth;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads GA4 traffic via the Analytics Data API runReport endpoint.
 */
class Analytics {

	/** GA4 numeric property id (e.g. "123456789"). */
	const OPT_PROPERTY = 'sampoorna_seo_ga4_property';

	/** Analytics Data API base; the property path + :runReport is appended. */
	const DATA_ENDPOINT = 'https://analyticsdata.googleapis.com/v1beta/properties/';

	/**
	 * Singleton instance.
	 *
	 * @var Analytics|null
	 */
	private static $instance = null;

	/**
	 * Retrieve the shared singleton instance.
	 *
	 * @return Analytics
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * The configured GA4 property id (digits only).
	 *
	 * @return string
	 */
	public function property() {
		return preg_replace( '/\D+/', '', (string) get_option( self::OPT_PROPERTY, '' ) );
	}

	/**
	 * Whether GA4 can be queried (OAuth connected + property set).
	 *
	 * @return bool
	 */
	public function is_ready() {
		return '' !== $this->property() && OAuth::instance()->is_connected();
	}

	/**
	 * Fetch a traffic summary for the last N days.
	 *
	 * @param int $days Lookback window in days.
	 * @return array<string,float|int>|\WP_Error
	 */
	public function summary( $days = 28 ) {
		if ( ! $this->is_ready() ) {
			return new \WP_Error( 'ga4_not_ready', __( 'GA4 is not connected or no property is set.', 'sampoorna-seo' ) );
		}
		$token = OAuth::instance()->get_access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$body = wp_json_encode(
			array(
				'dateRanges' => array(
					array(
						'startDate' => max( 1, (int) $days ) . 'daysAgo',
						'endDate'   => 'today',
					),
				),
				'metrics'    => array(
					array( 'name' => 'sessions' ),
					array( 'name' => 'totalUsers' ),
					array( 'name' => 'screenPageViews' ),
					array( 'name' => 'conversions' ),
				),
			)
		);

		$resp = wp_remote_post(
			self::DATA_ENDPOINT . rawurlencode( $this->property() ) . ':runReport',
			array(
				'timeout' => 20,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				),
				'body'    => $body,
			)
		);
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		$data = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( 200 !== $code ) {
			$msg = isset( $data['error']['message'] ) ? (string) $data['error']['message'] : __( 'GA4 request failed.', 'sampoorna-seo' );
			return new \WP_Error( 'ga4_request_failed', $msg );
		}

		return self::parse_report( is_array( $data ) ? $data : array() );
	}

	/**
	 * Map a runReport response to a flat metric summary.
	 *
	 * Pure (no I/O) so it is unit-testable against captured fixtures.
	 *
	 * @param array<string,mixed> $data Decoded runReport response.
	 * @return array{sessions:int,users:int,views:int,conversions:float}
	 */
	public static function parse_report( array $data ) {
		$out = array(
			'sessions'    => 0,
			'users'       => 0,
			'views'       => 0,
			'conversions' => 0.0,
		);
		if ( empty( $data['metricHeaders'] ) || empty( $data['rows'][0]['metricValues'] ) ) {
			return $out;
		}
		$map  = array(
			'sessions'        => 'sessions',
			'totalUsers'      => 'users',
			'screenPageViews' => 'views',
			'conversions'     => 'conversions',
		);
		$vals = $data['rows'][0]['metricValues'];
		foreach ( $data['metricHeaders'] as $i => $header ) {
			$name = isset( $header['name'] ) ? (string) $header['name'] : '';
			if ( ! isset( $map[ $name ], $vals[ $i ]['value'] ) ) {
				continue;
			}
			$key         = $map[ $name ];
			$value       = $vals[ $i ]['value'];
			$out[ $key ] = 'conversions' === $key ? (float) $value : (int) $value;
		}
		return $out;
	}
}
