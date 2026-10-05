<?php
/**
 * PSR-4 autoloader for the Speedy_Sensor namespace.
 *
 * Ships with the plugin so the runtime has no Composer dependency. Dev tooling
 * (PHPCS, PHPUnit) uses the same map via composer.json.
 *
 * @package Speedy_Sensor
 */

defined( 'ABSPATH' ) || exit;

spl_autoload_register(
	/**
	 * Maps Speedy_Sensor\Foo\Bar to includes/Foo/Bar.php.
	 *
	 * @param string $class_name Fully qualified class name.
	 * @return void
	 */
	static function ( $class_name ) {
		$prefix = 'Speedy_Sensor\\';
		$length = strlen( $prefix );

		if ( 0 !== strncmp( $prefix, $class_name, $length ) ) {
			return;
		}

		$relative = substr( $class_name, $length );

		// A PSR-4 relative name may contain backslashes — they are the
		// namespace separator — but never a dot or a forward slash. Rejecting
		// those two stops a malformed class name from traversing out of
		// includes/, since '..' needs a dot to climb.
		if ( false !== strpos( $relative, '.' ) || false !== strpos( $relative, '/' ) ) {
			return;
		}

		$path = SPEEDY_SENSOR_DIR . 'includes/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);
