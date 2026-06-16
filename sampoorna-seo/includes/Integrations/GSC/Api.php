<?php
/**
 * Thin client for the Search Console REST API.
 *
 * @package Sampoorna\SEO
 */

namespace Sampoorna\SEO\Integrations\GSC;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thin client for the Google Search Console REST API.
 */
class Api {

	const BASE         = 'https://www.googleapis.com/webmasters/v3';
	const INSPECT_BASE = 'https://searchconsole.googleapis.com/v1';

	/**
	 * Authenticated request against an absolute URL.
	 *
	 * @param string     $method GET|POST.
	 * @param string     $url    Absolute endpoint URL.
	 * @param array|null $body   JSON body for POST.
	 * @return array|\WP_Error Decoded response.
	 */
	private static function request_url( $method, $url, $body = null ) {
		$token = OAuth::instance()->get_access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$args = array(
			'method'  => $method,
			'timeout' => 30,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$resp = wp_remote_request( $url, $args );
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}

		$code = (int) wp_remote_retrieve_response_code( $resp );
		$data = json_decode( wp_remote_retrieve_body( $resp ), true );

		if ( $code < 200 || $code >= 300 ) {
			$msg = isset( $data['error']['message'] ) ? $data['error']['message'] : 'HTTP ' . $code;
			return new \WP_Error( 'sampoorna_seo_api_' . $code, $msg, array( 'status' => $code ) );
		}
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Authenticated GET/POST against the v3 API.
	 *
	 * @param string     $method GET|POST.
	 * @param string     $path   Path appended to BASE.
	 * @param array|null $body   JSON body for POST.
	 * @return array|\WP_Error Decoded response.
	 */
	private static function request( $method, $path, $body = null ) {
		$token = OAuth::instance()->get_access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$args = array(
			'method'  => $method,
			'timeout' => 30,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$resp = wp_remote_request( self::BASE . $path, $args );
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}

		$code = (int) wp_remote_retrieve_response_code( $resp );
		$data = json_decode( wp_remote_retrieve_body( $resp ), true );

		if ( $code < 200 || $code >= 300 ) {
			$msg = isset( $data['error']['message'] ) ? $data['error']['message'] : 'HTTP ' . $code;
			return new \WP_Error( 'sampoorna_seo_api_' . $code, $msg, array( 'status' => $code ) );
		}
		return is_array( $data ) ? $data : array();
	}

	/**
	 * List verified properties for the connected account.
	 *
	 * @return array|\WP_Error List of site URLs.
	 */
	public static function list_sites() {
		$data = self::request( 'GET', '/sites' );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$sites = array();
		foreach ( ( $data['siteEntry'] ?? array() ) as $entry ) {
			$sites[] = array(
				'url'        => $entry['siteUrl'],
				'permission' => $entry['permissionLevel'] ?? '',
			);
		}
		return $sites;
	}

	/**
	 * Query Search Analytics, paginating up to $max_rows.
	 *
	 * @param string $property    Property URL.
	 * @param string $start       YYYY-MM-DD.
	 * @param string $end         YYYY-MM-DD.
	 * @param array  $dimensions  e.g. ['date'] or ['page','query'].
	 * @param int    $max_rows    Hard cap across pages.
	 * @return array|\WP_Error Normalized rows.
	 */
	public static function search_analytics( $property, $start, $end, array $dimensions, $max_rows = 25000 ) {
		$path      = '/sites/' . rawurlencode( $property ) . '/searchAnalytics/query';
		$page      = 25000; // API max per request.
		$start_row = 0;
		$all       = array();

		do {
			$body = array(
				'startDate'  => $start,
				'endDate'    => $end,
				'dimensions' => $dimensions,
				'rowLimit'   => min( $page, $max_rows - count( $all ) ),
				'startRow'   => $start_row,
			);
			$data = self::request( 'POST', $path, $body );
			if ( is_wp_error( $data ) ) {
				return $data;
			}
			$rows = $data['rows'] ?? array();
			foreach ( $rows as $r ) {
				$keys = $r['keys'] ?? array();
				$norm = array(
					'clicks'      => $r['clicks'] ?? 0,
					'impressions' => $r['impressions'] ?? 0,
					'ctr'         => $r['ctr'] ?? 0,
					'position'    => $r['position'] ?? 0,
				);
				foreach ( $dimensions as $i => $dim ) {
					$norm[ $dim ] = $keys[ $i ] ?? '';
				}
				$all[] = $norm;
			}
			$rows_count = count( $rows );
			$all_count  = count( $all );
			$start_row += $rows_count;
		} while ( $rows_count === $page && $all_count < $max_rows );

		return $all;
	}

	/**
	 * Top search queries for a single page, using a dimension filter.
	 *
	 * @param string $property Property URL.
	 * @param string $page     Page URL to filter by.
	 * @param string $start    YYYY-MM-DD.
	 * @param string $end      YYYY-MM-DD.
	 * @param int    $limit    Max queries to return.
	 * @return array|\WP_Error List of [query, clicks, impressions, ctr, position].
	 */
	public static function top_queries_for_page( $property, $page, $start, $end, $limit = 5 ) {
		$path = '/sites/' . rawurlencode( $property ) . '/searchAnalytics/query';
		$body = array(
			'startDate'             => $start,
			'endDate'               => $end,
			'dimensions'            => array( 'query' ),
			'rowLimit'              => (int) $limit,
			'dimensionFilterGroups' => array(
				array(
					'filters' => array(
						array(
							'dimension'  => 'page',
							'operator'   => 'equals',
							'expression' => $page,
						),
					),
				),
			),
		);
		$data = self::request( 'POST', $path, $body );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$out = array();
		foreach ( ( $data['rows'] ?? array() ) as $r ) {
			$out[] = array(
				'query'       => $r['keys'][0] ?? '',
				'clicks'      => $r['clicks'] ?? 0,
				'impressions' => $r['impressions'] ?? 0,
				'ctr'         => $r['ctr'] ?? 0,
				'position'    => $r['position'] ?? 0,
			);
		}
		return $out;
	}

	/**
	 * Inspect a single URL via the URL Inspection API.
	 *
	 * @param string $property URL-prefix or domain property in GSC.
	 * @param string $url      The absolute URL to inspect.
	 * @param string $lang     BCP-47 language code (default 'en-US').
	 * @return array|\WP_Error Normalized inspection result.
	 */
	public static function inspect_url( $property, $url, $lang = 'en-US' ) {
		$body = array(
			'inspectionUrl' => $url,
			'siteUrl'       => $property,
			'languageCode'  => $lang,
		);
		$data = self::request_url( 'POST', self::INSPECT_BASE . '/urlInspection/index:inspect', $body );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$result = $data['inspectionResult'] ?? array();
		$index  = $result['indexStatusResult'] ?? array();
		$mobile = $result['mobileUsabilityResult'] ?? array();
		$rich   = $result['richResultsResult'] ?? array();

		return array(
			'verdict'          => $index['verdict'] ?? '',
			'coverage_state'   => $index['coverageState'] ?? '',
			'robots_state'     => $index['robotsTxtState'] ?? '',
			'indexing_state'   => $index['indexingState'] ?? '',
			'page_fetch_state' => $index['pageFetchState'] ?? '',
			'google_canonical' => $index['googleCanonical'] ?? '',
			'user_canonical'   => $index['userCanonical'] ?? '',
			'last_crawl_time'  => $index['lastCrawlTime'] ?? '',
			'mobile_verdict'   => $mobile['verdict'] ?? '',
			'mobile_issues'    => $mobile['issues'] ?? array(),
			'rich_verdict'     => $rich['verdict'] ?? '',
			'rich_items'       => $rich['detectedItems'] ?? array(),
			'raw'              => $result,
		);
	}
}
