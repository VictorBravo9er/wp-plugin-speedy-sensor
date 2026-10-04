<?php
/**
 * Deactivation and uninstall.
 *
 * Deactivation is reversible: it clears schedules and the scan lock but leaves
 * tables and settings intact so reactivating resumes where the site left off.
 * Uninstall deletes everything the plugin created.
 *
 * @package Speedy_Sensor
 */

namespace Speedy_Sensor\Install;

use Speedy_Sensor\Db\Schema;
use Speedy_Sensor\Scanner\Cron;
use Speedy_Sensor\Scanner\ScanLock;
use Speedy_Sensor\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Runs on plugin deactivation and uninstall.
 */
final class Uninstaller {

	/**
	 * Clears schedules and the scan lock. Data is kept.
	 *
	 * @return void
	 */
	public static function deactivate() {
		Cron::unschedule();
		ScanLock::force_release();
	}

	/**
	 * Drops tables, options and locks. Only called from uninstall.php.
	 *
	 * @return void
	 */
	public static function uninstall() {
		global $wpdb;

		Cron::unschedule();
		ScanLock::force_release();

		( new Settings() )->delete();

		delete_option( Schema::VERSION_OPTION );

		$tables = array(
			Schema::scans_table( $wpdb ),
			Schema::plugin_metrics_table( $wpdb ),
			Schema::db_metrics_table( $wpdb ),
			Schema::web_vitals_table( $wpdb ),
		);

		foreach ( $tables as $table ) {
			// Table names come from Schema, never from user input.
			$wpdb->query( 'DROP TABLE IF EXISTS `' . str_replace( '`', '', $table ) . '`' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
	}
}