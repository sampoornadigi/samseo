<?php
/**
 * Constants defined by the plugin bootstrap that PHPStan won't otherwise see.
 *
 * @package Sampoorna\SEO
 */

define( 'SAMPOORNA_SEO_VERSION', '0.0.0' );
define( 'SAMPOORNA_SEO_FILE', __FILE__ );
define( 'SAMPOORNA_SEO_DIR', __DIR__ );
define( 'SAMPOORNA_SEO_URL', 'https://example.test/' );
define( 'SAMPOORNA_SEO_CRON_HOOK', 'sampoorna_seo_daily_performance_sync' );
define( 'SAMPOORNA_SEO_INSPECT_HOOK', 'sampoorna_seo_inspection_tick' );
define( 'SAMPOORNA_SEO_DIGEST_HOOK', 'sampoorna_seo_email_digest' );
