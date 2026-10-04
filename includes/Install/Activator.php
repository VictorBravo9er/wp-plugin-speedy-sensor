<?php
/**
 * Activation and deactivation.
 *
 * @package Speedy_Sensor
 */

namespace Speedy_Sensor\Install;

use Speedy_Sensor\Db\Schema;
use Speedy_Sensor\Scanner\Cron;
use Speedy_Sensor\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Runs on plugin activation.
 */
final class Activator {

	/**
	 * Creates tables, seeds defaults and schedules the first scan.
	 *
	 * @return void
	 */
	public static function activate() {
		Schema::install();

		$settings = new Settings();
		$settings->install_defaults();

		Cron::schedule( $settings );
	}
}
