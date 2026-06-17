<?php
/**
 * Robots.txt editor.
 *
 * Lets an admin replace the served robots.txt body. The Sitemap module's
 * higher-priority robots_txt filter still appends the Sitemap: line afterward,
 * so the sitemap stays advertised regardless of custom rules.
 *
 * @package Sampoorna\SEO
 */

namespace Sampoorna\SEO\Technical;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Serves an admin-managed robots.txt body.
 */
class Robots {

	const OPT_BODY = 'sampoorna_seo_robots_txt';

	/**
	 * Singleton instance.
	 *
	 * @var Robots|null
	 */
	private static $instance = null;

	/**
	 * Retrieve the singleton instance.
	 *
	 * @return Robots
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Wire the robots.txt filter (priority 9 — before Sitemap appends its line).
	 */
	private function __construct() {
		add_filter( 'robots_txt', array( $this, 'filter' ), 9, 2 );
	}

	/**
	 * Replace the robots.txt body with the admin-defined rules when set.
	 *
	 * @param string $output    Default robots.txt body.
	 * @param bool   $is_public Whether the site is public.
	 * @return string
	 */
	public function filter( $output, $is_public ) {
		if ( ! $is_public ) {
			return $output;
		}
		$custom = trim( (string) get_option( self::OPT_BODY, '' ) );
		return '' !== $custom ? $custom . "\n" : $output;
	}
}
