<?php
/**
 * Cron scheduling.
 *
 * The scheduled hook fires ScanRunner, never the scan itself, so a failed tick
 * cannot take down the cron request.
 *
 * @package Speedy_Sensor
 */

namespace Speedy_Sensor\Scanner;

use Speedy_Sensor\Db\Schema;
use Speedy_Sensor\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Registers and clears the plugin's schedules.
 */
final class Cron {

	/**
	 * Recurring scan hook.
	 */
	const SCAN_HOOK = 'speedy_sensor_run_scan';

	/**
	 * One-shot hook used to continue a budgeted scan on the next tick.
	 */
	const RESUME_HOOK = 'speedy_sensor_resume_scan';

	/**
	 * Seconds before an unfinished scan resumes itself.
	 */
	const RESUME_DELAY = 120;

	/**
	 * Registers hooks.
	 *
	 * @param Settings $settings Settings store.
	 * @return void
	 */
	public static function init( Settings $settings ) {
		add_filter( 'cron_schedules', array( __CLASS__, 'filter_schedules' ) );
		add_action( self::SCAN_HOOK, array( __CLASS__, 'run_scan' ) );
		add_action( self::RESUME_HOOK, array( __CLASS__, 'run_scan' ) );
		add_action( 'plugins_loaded', array( __CLASS__, 'maybe_upgrade_schema' ), 20 );
	}

	/**
	 * Cron entry point.
	 *
	 * @return void
	 */
	public static function run_scan() {
		( new ScanRunner( new Settings() ) )->run();
	}

	/**
	 * Applies schema updates left behind by a plugin upgrade.
	 *
	 * Admin and cron only: reading the version option on a front-end request
	 * would add a query to every page view, which this plugin refuses to do.
	 *
	 * @return void
	 */
	public static function maybe_upgrade_schema() {
		if ( ! is_admin() && ! wp_doing_cron() ) {
			return;
		}

		if ( ! Schema::needs_upgrade() ) {
			return;
		}

		Schema::install();
	}

	/**
	 * Registers the configured interval.
	 *
	 * @param array $schedules Existing schedules.
	 * @return array
	 */
	public static function filter_schedules( $schedules ) {
		$settings = new Settings();
		$days     = (int) $settings->get( 'scan_interval_days', 7 );
		$days     = Settings::clamp( $days, 1, 90, 7 );

		$schedules[ self::interval_name( $days ) ] = array(
			'interval' => DAY_IN_SECONDS * $days,
			'display'  => sprintf(
				/* translators: %d: number of days between scans. */
				__( 'Every %d days (Speedy Sensor)', 'speedy-sensor' ),
				$days
			),
		);

		return $schedules;
	}

	/**
	 * Schedule name for an interval in days.
	 *
	 * @param int $days Interval in days.
	 * @return string
	 */
	public static function interval_name( $days ) {
		return 'speedy_sensor_every_' . (int) $days . 'd';
	}

	/**
	 * Replaces the recurring schedule with one matching current settings.
	 *
	 * @param Settings|null $settings Settings store.
	 * @return bool Whether the event is now scheduled.
	 */
	public static function schedule( $settings = null ) {
		if ( ! $settings instanceof Settings ) {
			$settings = new Settings();
		}

		// The activation hook fires after plugins_loaded, so plugins_loaded may
		// never have run for this request and the filter may be unregistered.
		// add_filter() de-duplicates identical callbacks, so this is idempotent.
		add_filter( 'cron_schedules', array( __CLASS__, 'filter_schedules' ) );

		self::unschedule();

		$days = (int) $settings->get( 'scan_interval_days', 7 );

		return false !== wp_schedule_event( time() + 60, self::interval_name( $days ), self::SCAN_HOOK );
	}

	/**
	 * Removes every event this plugin owns.
	 *
	 * @return void
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( self::SCAN_HOOK );
		wp_clear_scheduled_hook( self::RESUME_HOOK );
	}

	/**
	 * Queues the next pass of an unfinished scan.
	 *
	 * @return void
	 */
	public static function schedule_resume() {
		if ( ! wp_next_scheduled( self::RESUME_HOOK ) ) {
			wp_schedule_single_event( time() + self::RESUME_DELAY, self::RESUME_HOOK );
		}
	}
}