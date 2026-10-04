<?php
/**
 * Plugin Name:       Speedy Sensor
 * Plugin URI:        https://speedy.site/
 * Description:       Backend monitor for site health. Inventories plugins, measures the database footprint, tracks Web Vitals reported by Speedy.site, and raises optimisation service requests. Does nothing on the front end.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Speedy
 * Author URI:        https://speedy.site/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       speedy-sensor
 *
 * @package Speedy_Sensor
 */

defined( 'ABSPATH' ) || exit;

define( 'SPEEDY_SENSOR_VERSION', '0.1.0' );
define( 'SPEEDY_SENSOR_DB_VERSION', '1' );
define( 'SPEEDY_SENSOR_MIN_PHP', '7.4' );
define( 'SPEEDY_SENSOR_FILE', __FILE__ );
define( 'SPEEDY_SENSOR_DIR', plugin_dir_path( __FILE__ ) );
define( 'SPEEDY_SENSOR_BASENAME', plugin_basename( __FILE__ ) );
define( 'SPEEDY_SENSOR_URL', plugin_dir_url( __FILE__ ) );

if ( version_compare( PHP_VERSION, SPEEDY_SENSOR_MIN_PHP, '<' ) ) {
	/**
	 * Warns the administrator that the plugin cannot run on this PHP version.
	 *
	 * @return void
	 */
	function speedy_sensor_php_version_notice() {
		echo '<div class="notice notice-error"><p>';
		printf(
			/* translators: 1: required PHP version, 2: current PHP version. */
			esc_html__( 'Speedy Sensor requires PHP %1$s or newer. This site runs PHP %2$s, so the plugin is inactive.', 'speedy-sensor' ),
			esc_html( SPEEDY_SENSOR_MIN_PHP ),
			esc_html( PHP_VERSION )
		);
		echo '</p></div>';
	}

	add_action( 'admin_notices', 'speedy_sensor_php_version_notice' );

	return;
}

require_once SPEEDY_SENSOR_DIR . 'includes/autoload.php';

register_activation_hook( __FILE__, array( 'Speedy_Sensor\\Install\\Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Speedy_Sensor\\Install\\Uninstaller', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'Speedy_Sensor\\Plugin', 'boot' ), 5 );
