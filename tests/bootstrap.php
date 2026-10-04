<?php
/**
 * PHPUnit bootstrap.
 *
 * Loads the WordPress test library and boots the plugin into it.
 *
 * @package Speedy_Sensor
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	echo 'Could not find the WordPress test library. Set WP_TESTS_DIR to the directory' . PHP_EOL;
	echo 'that contains includes/functions.php, then run composer test.' . PHP_EOL;
	exit( 1 );
}

require_once $_tests_dir . '/includes/functions.php';

/**
 * Loads the plugin before the test suite runs.
 *
 * @return void
 */
function speedy_sensor_manually_load_plugin() {
	require dirname( __DIR__ ) . '/speedy-sensor.php';
}

tests_add_filter( 'muplugins_loaded', 'speedy_sensor_manually_load_plugin' );

require $_tests_dir . '/includes/bootstrap.php';

tests_add_filter(
	'setup_theme',
	static function () {
		register_activation_hook( __DIR__ . '/../speedy-sensor.php', 'Speedy_Sensor\\Install\\Activator::activate' );
	}
);
