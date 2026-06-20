<?php
/**
 * Control-plane bulk maintenance actions.
 *
 * The plane fans a single maintenance action out across many sites (regenerate
 * sitemaps, refresh llms.txt, flush rewrites). Each action is idempotent and
 * safe to re-run; this class is the allow-listed dispatcher invoked by the
 * signed /action route. Unknown actions are rejected.
 *
 * @package Sampoorna\SEO
 */

namespace Sampoorna\SEO\ControlPlane;

use Sampoorna\SEO\Technical\Sitemap;
use Sampoorna\SEO\Geo\LlmsTxt;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dispatches allow-listed bulk maintenance actions.
 */
class Actions {

	/**
	 * Run a single named action.
	 *
	 * @param string $action Action key.
	 * @return array<string,mixed>
	 */
	public static function run( $action ) {
		$action = (string) $action;
		switch ( $action ) {
			case 'sitemap_regen':
				Sitemap::instance()->bump_version();
				return self::done( $action, array( 'sitemap_version' => (int) get_option( Sitemap::OPT_VERSION, 1 ) ) );

			case 'llms_refresh':
				LlmsTxt::instance()->bump_version();
				return self::done( $action, array( 'llms_version' => (int) get_option( LlmsTxt::OPT_VERSION, 1 ) ) );

			case 'flush_rewrites':
				flush_rewrite_rules( false );
				return self::done( $action, array() );

			default:
				return array(
					'action' => $action,
					'ok'     => false,
					'error'  => 'unknown_action',
				);
		}
	}

	/**
	 * Standard success payload.
	 *
	 * @param string              $action Action key.
	 * @param array<string,mixed> $extra  Action-specific detail.
	 * @return array<string,mixed>
	 */
	private static function done( $action, array $extra ) {
		return array_merge(
			array(
				'action' => $action,
				'ok'     => true,
			),
			$extra
		);
	}
}
