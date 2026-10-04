<?php
/**
 * Subsystem wiring.
 *
 * Registration order matters: cron and REST are live on every request, admin
 * screens only inside wp-admin. Nothing here touches the front end beyond two
 * add_action calls with empty callbacks.
 *
 * @package Speedy_Sensor
 */

namespace Speedy_Sensor;

defined( 'ABSPATH' ) || exit;

/**
 * Boots every subsystem.
 */
final class Plugin {

	/**
	 * Entry point. Hooked to plugins_loaded.
	 *
	 * @return void
	 */
	public static function boot() {
		self::guard_duplicate_install();

		$settings = new Settings\Settings();

		Scanner\Cron::init( $settings );
		Integration\WebVitalsService::init( $settings );

		if ( is_admin() ) {
			Admin\AdminMenu::init( $settings );
			Admin\Notices::init( $settings );
		}

		Admin\Actions::init( $settings );

		/**
		 * Fires once every Speedy Sensor subsystem is registered.
		 *
		 * @param Settings\Settings $settings Settings store.
		 */
		do_action( 'speedy_sensor_loaded', $settings );
	}

	/**
	 * Blocks operation when the plugin folder is present twice.
	 *
	 * Two copies produce two schedulers and two writers against the same
	 * tables, which corrupts scan history. Checked on admin screens only, so
	 * it costs the front end nothing.
	 *
	 * @return void
	 */
	private static function guard_duplicate_install() {
		if ( ! is_admin() ) {
			return;
		}

		$active = (array) get_option( 'active_plugins', array() );
		$seen   = false;

		foreach ( $active as $plugin ) {
			if ( SPEEDY_SENSOR_BASENAME !== $plugin ) {
				continue;
			}

			if ( $seen ) {
				add_action( 'admin_notices', array( __CLASS__, 'render_duplicate_notice' ) );
			}

			$seen = true;
		}
	}

	/**
	 * Renders the duplicate-install warning.
	 *
	 * @return void
	 */
	public static function render_duplicate_notice() {
		echo '<div class="notice notice-error"><p>';
		esc_html_e( 'Speedy Sensor is installed in more than one location. Only one copy can run at a time, otherwise scan history is overwritten.', 'speedy-sensor' );
		echo '</p></div>';
	}
}