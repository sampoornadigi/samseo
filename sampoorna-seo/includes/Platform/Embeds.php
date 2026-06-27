<?php
/**
 * Platform embeds: make this the single Sampoorna plugin a site needs.
 *
 * Beyond SEO, the Sampoorna platform has two other client-side pieces a site would
 * otherwise paste by hand — the AdSync first-party analytics SDK and the CRM AI
 * chat widget. This module injects them into the site head when their keys are set,
 * so installing one plugin lights up SEO + analytics + the chat widget. The keys are
 * control-plane-templatable options (see ControlPlane\Settings), so the plane can
 * provision them on enrollment — the client pastes nothing.
 *
 * @package Sampoorna\SEO
 */

namespace Sampoorna\SEO\Platform;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Injects the AdSync analytics + CRM widget scripts based on configured keys.
 */
class Embeds {

	const OPT_ANALYTICS_KEY  = 'sampoorna_seo_analytics_key';
	const OPT_ANALYTICS_BASE = 'sampoorna_seo_analytics_base';
	const OPT_WIDGET_KEY     = 'sampoorna_seo_widget_key';
	const OPT_WIDGET_BASE    = 'sampoorna_seo_widget_base';

	const DEFAULT_ANALYTICS_BASE = 'https://platform.sampoornadigi.in';
	const DEFAULT_WIDGET_BASE    = 'https://app.sampoornadigi.in';

	/**
	 * Singleton instance.
	 *
	 * @var Embeds|null
	 */
	private static $instance = null;

	/**
	 * Retrieve the singleton instance.
	 *
	 * @return Embeds
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Wire the front-end injection (runs late in <head>).
	 */
	private function __construct() {
		add_action( 'wp_head', array( $this, 'output' ), 20 );
	}

	/**
	 * Emit the platform <script> tags for whichever embeds are configured.
	 *
	 * @return void
	 */
	public function output() {
		if ( is_admin() ) {
			return;
		}

		// These are third-party platform loaders (the AdSync SDK + the CRM widget):
		// each reads its own data-* attributes and derives its base from script.src,
		// so they must be direct tags, not wp_enqueue_script handles.
		// phpcs:disable WordPress.WP.EnqueuedResources.NonEnqueuedScript
		$analytics_key = (string) get_option( self::OPT_ANALYTICS_KEY, '' );
		if ( '' !== $analytics_key ) {
			$base = self::base( self::OPT_ANALYTICS_BASE, self::DEFAULT_ANALYTICS_BASE );
			printf(
				'<script src="%s/analytics/sdk.js" data-key="%s" defer></script>' . "\n",
				esc_url( $base ),
				esc_attr( $analytics_key )
			);
		}

		$widget_key = (string) get_option( self::OPT_WIDGET_KEY, '' );
		if ( '' !== $widget_key ) {
			$base = self::base( self::OPT_WIDGET_BASE, self::DEFAULT_WIDGET_BASE );
			printf(
				'<script src="%s/embed/sampoorna-widget.js" data-widget-key="%s" data-api-base="%s" defer></script>' . "\n",
				esc_url( $base ),
				esc_attr( $widget_key ),
				esc_url( $base )
			);
		}
		// phpcs:enable WordPress.WP.EnqueuedResources.NonEnqueuedScript
	}

	/**
	 * Which embeds are currently active (for the site descriptor).
	 *
	 * @return array<string,bool>
	 */
	public static function active() {
		return array(
			'analytics' => '' !== (string) get_option( self::OPT_ANALYTICS_KEY, '' ),
			'widget'    => '' !== (string) get_option( self::OPT_WIDGET_KEY, '' ),
		);
	}

	/**
	 * Resolve a configured base URL, falling back to the platform default.
	 *
	 * @param string $option   Option key holding the base.
	 * @param string $fallback Default base URL.
	 * @return string
	 */
	private static function base( $option, $fallback ) {
		$value = (string) get_option( $option, '' );
		return '' !== $value ? untrailingslashit( $value ) : $fallback;
	}
}
