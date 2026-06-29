<?php
/**
 * PHPUnit bootstrap for the WordPress test suite.
 *
 * Designed to run inside the `@wordpress/env` `tests-cli` container, where the
 * WordPress PHPUnit test library is mounted and its path is exposed via the
 * WP_TESTS_DIR environment variable.
 *
 * @package wp-alaveteli-stats
 */

// Composer autoloader: makes PHPUnit and the Yoast polyfills available.
$_autoload = dirname( __DIR__ ) . '/vendor/autoload.php';
if ( file_exists( $_autoload ) ) {
	require_once $_autoload;
}

// Point the WordPress test bootstrap at the polyfills package explicitly, so it
// does not have to guess where Composer installed it.
$_polyfills = dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills';
if ( is_dir( $_polyfills ) && ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $_polyfills );
}

$_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $_tests_dir ) {
	$_tests_dir = '/wordpress-phpunit';
}

$_functions = $_tests_dir . '/includes/functions.php';
if ( ! file_exists( $_functions ) ) {
	echo "Could not find the WordPress test library at {$_tests_dir}.\n";
	echo "Run these tests inside `wp-env run tests-cli` (or set WP_TESTS_DIR).\n";
	exit( 1 );
}

require_once $_functions;

/**
 * Load the plugin into the test WordPress instance before it boots.
 */
function _manually_load_plugin() {
	require dirname( __DIR__ ) . '/wp-alaveteli-stats.php';
}
tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );

require $_tests_dir . '/includes/bootstrap.php';
