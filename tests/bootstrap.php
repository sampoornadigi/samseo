<?php
/**
 * PHPUnit bootstrap for the WordPress integration test suite.
 *
 * Loads the WordPress test framework (installed by dev/install-wp-tests.sh),
 * then loads the plugin under test on muplugins_loaded.
 *
 * @package Sampoorna\SEO
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $_tests_dir ) {
	$_tests_dir = '/tmp/wordpress-tests-lib';
}

// Yoast polyfills (required by recent WP test suites).
$_polyfills = getenv( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' );
if ( $_polyfills && ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $_polyfills );
}

$_functions = $_tests_dir . '/includes/functions.php';
if ( ! file_exists( $_functions ) ) {
	echo "Could not find {$_functions}.\n";
	echo "Run the test installer first:  make test-setup\n";
	exit( 1 );
}

require_once $_functions;

/**
 * Load the plugin(s) under test.
 */
function _sampoorna_seo_manually_load_plugins() {
	require dirname( __DIR__ ) . '/sampoorna-seo/sampoorna-seo.php';
}
tests_add_filter( 'muplugins_loaded', '_sampoorna_seo_manually_load_plugins' );

require $_tests_dir . '/includes/bootstrap.php';
