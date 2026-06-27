<?php
/**
 * Plugin Name:       Sampoorna SEO
 * Plugin URI:        https://sampoornadigi.com/sampoorna-seo
 * Description:        The one Sampoorna plugin for your site: SEO (meta, schema, GEO/AEO, sitemaps), Google Search Console tracking + fixes, lead capture to the CRM, and — wired automatically by the platform — AdSync first-party analytics and the CRM AI chat widget. One install lights up the whole stack.
 * Version:           0.5.0
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * Author:            LSN Soft
 * License:           GPL-2.0-or-later
 * Text Domain:       sampoorna-seo
 *
 * @package Sampoorna\SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

define( 'SAMPOORNA_SEO_VERSION', '0.5.0' );
define( 'SAMPOORNA_SEO_FILE', __FILE__ );
define( 'SAMPOORNA_SEO_DIR', plugin_dir_path( __FILE__ ) );
define( 'SAMPOORNA_SEO_URL', plugin_dir_url( __FILE__ ) );
define( 'SAMPOORNA_SEO_CRON_HOOK', 'sampoorna_seo_daily_performance_sync' );
define( 'SAMPOORNA_SEO_INSPECT_HOOK', 'sampoorna_seo_inspection_tick' );
define( 'SAMPOORNA_SEO_DIGEST_HOOK', 'sampoorna_seo_email_digest' );

/**
 * PSR-4 autoloader for the Sampoorna\SEO\ namespace.
 *
 * Maps Sampoorna\SEO\Module\Class to includes/Module/Class.php. Composer is a
 * dev-only dependency (PHPCS/PHPStan/PHPUnit); the plugin carries no runtime
 * third-party dependencies, so classes load through this lightweight registrar.
 *
 * @param string $class_name Fully-qualified class name.
 * @return void
 */
function sampoorna_seo_autoload( $class_name ) {
	$prefix = 'Sampoorna\\SEO\\';
	$len    = strlen( $prefix );
	if ( 0 !== strncmp( $prefix, $class_name, $len ) ) {
		return;
	}
	$relative = substr( $class_name, $len );
	$path     = SAMPOORNA_SEO_DIR . 'includes/' . str_replace( '\\', '/', $relative ) . '.php';
	if ( is_readable( $path ) ) {
		require $path;
	}
}
spl_autoload_register( 'sampoorna_seo_autoload' );

/**
 * Register a 15-minute cron schedule for the inspection crawler.
 *
 * @param array $schedules Existing schedules.
 * @return array
 */
function sampoorna_seo_cron_schedules( $schedules ) {
	$schedules['sampoorna_seo_quarter_hour'] = array(
		'interval' => 15 * MINUTE_IN_SECONDS,
		'display'  => __( 'Every 15 minutes (Sampoorna SEO)', 'sampoorna-seo' ),
	);
	return $schedules;
}
add_filter( 'cron_schedules', 'sampoorna_seo_cron_schedules' );

/**
 * Boot the plugin.
 *
 * @return void
 */
function sampoorna_seo_init() {
	\Sampoorna\SEO\Core\Database::maybe_upgrade();

	// Meta engine (Phase 0 spine): per-object meta + server-side wp_head output.
	\Sampoorna\SEO\Meta\MetaStore::instance();
	\Sampoorna\SEO\Meta\Renderer::instance();

	// Technical SEO (Phase 1): sitemaps, redirects/404, robots.txt, hreflang, IndexNow.
	\Sampoorna\SEO\Technical\Sitemap::instance();
	\Sampoorna\SEO\Technical\Redirects::instance();
	\Sampoorna\SEO\Technical\Robots::instance();
	\Sampoorna\SEO\Technical\Hreflang::instance();
	\Sampoorna\SEO\Technical\IndexNow::instance();
	\Sampoorna\SEO\Technical\BingSubmit::instance();

	// GEO / AI visibility (Phase 5): llms.txt / llms-full.txt + AI-crawler logging.
	\Sampoorna\SEO\Geo\LlmsTxt::instance();
	\Sampoorna\SEO\Geo\CrawlerLog::instance();

	// Schema (Phase 2): connected JSON-LD @graph (front-end) + extra node types.
	\Sampoorna\SEO\Schema\Graph::instance();
	\Sampoorna\SEO\Schema\Faq::instance();
	\Sampoorna\SEO\Schema\HowTo::instance();
	\Sampoorna\SEO\Schema\Product::instance();
	\Sampoorna\SEO\Schema\LocalBusiness::instance();
	\Sampoorna\SEO\Schema\Custom::instance(); // AEO/GEO JSON-LD deployed per post

	\Sampoorna\SEO\Integrations\GSC\OAuth::instance();
	\Sampoorna\SEO\Integrations\GSC\Sync::instance();
	\Sampoorna\SEO\Integrations\GSC\Inspector::instance();
	\Sampoorna\SEO\Integrations\GSC\Suggestions::instance();
	\Sampoorna\SEO\Integrations\GSC\Reports::instance();

	// Control-plane handshake: signed REST endpoints (registers on all requests).
	\Sampoorna\SEO\ControlPlane\Handshake::instance();

	// Lead capture: forward form submissions (CF7/WPForms/Gravity/Ninja) to the
	// control plane -> seo.lead.captured -> CRM contact (Phase 4 SEO->CRM flow).
	\Sampoorna\SEO\Integrations\Leads\LeadCapture::instance()->register();

	// Platform embeds: the single-plugin story — inject the AdSync analytics SDK +
	// the CRM chat widget when their (control-plane-provisioned) keys are set.
	\Sampoorna\SEO\Platform\Embeds::instance();

	if ( is_admin() ) {
		\Sampoorna\SEO\Admin\Screens::instance();
		\Sampoorna\SEO\Admin\MetaBox::instance();
		\Sampoorna\SEO\Migration\AdminPage::instance();
		\Sampoorna\SEO\Geo\CrawlerScreen::instance();
	}
	// Ensure the inspection tick is scheduled (covers upgrades from earlier versions).
	if ( ! wp_next_scheduled( SAMPOORNA_SEO_INSPECT_HOOK ) ) {
		wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'sampoorna_seo_quarter_hour', SAMPOORNA_SEO_INSPECT_HOOK );
	}
	// Keep the digest schedule aligned with settings if enabled but unscheduled.
	if ( \Sampoorna\SEO\Integrations\GSC\Reports::is_enabled() && ! wp_next_scheduled( SAMPOORNA_SEO_DIGEST_HOOK ) ) {
		\Sampoorna\SEO\Integrations\GSC\Reports::reschedule();
	}
}
add_action( 'plugins_loaded', 'sampoorna_seo_init' );

/**
 * Activation: create tables and schedule the daily sync.
 *
 * @return void
 */
function sampoorna_seo_activate() {
	\Sampoorna\SEO\Core\Database::create_tables();
	update_option( \Sampoorna\SEO\Core\Database::OPT_DB_VERSION, \Sampoorna\SEO\Core\Database::DB_VERSION );
	// Generate the per-site control-plane HMAC key on first activation.
	\Sampoorna\SEO\ControlPlane\Keys::ensure_key();
	if ( ! wp_next_scheduled( SAMPOORNA_SEO_CRON_HOOK ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', SAMPOORNA_SEO_CRON_HOOK );
	}
	// The 15-min schedule is registered on plugins_loaded; the inspection tick
	// is scheduled in sampoorna_seo_init once that schedule exists.

	// Register technical-SEO rewrite rules and flush so pretty URLs resolve immediately.
	\Sampoorna\SEO\Technical\Sitemap::instance()->register_rules();
	\Sampoorna\SEO\Technical\IndexNow::instance()->register_rules();
	\Sampoorna\SEO\Technical\IndexNow::ensure_key();
	\Sampoorna\SEO\Geo\LlmsTxt::instance()->register_rules();
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'sampoorna_seo_activate' );

/**
 * Deactivation: clear scheduled events (keep data).
 *
 * @return void
 */
function sampoorna_seo_deactivate() {
	wp_clear_scheduled_hook( SAMPOORNA_SEO_CRON_HOOK );
	wp_clear_scheduled_hook( SAMPOORNA_SEO_INSPECT_HOOK );
	wp_clear_scheduled_hook( SAMPOORNA_SEO_DIGEST_HOOK );
	// Remove our sitemap rewrite rules.
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'sampoorna_seo_deactivate' );
