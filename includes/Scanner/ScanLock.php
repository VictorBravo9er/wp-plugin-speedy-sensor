<?php
/**
 * Scan lock.
 *
 * Built on add_option(), which fails when the row already exists, giving an
 * atomic acquire without a database transaction. Prevents two overlapping cron
 * ticks from writing the same scan run.
 *
 * @package Speedy_Sensor
 */

namespace Speedy_Sensor\Scanner;

defined( 'ABSPATH' ) || exit;

/**
 * Mutually exclusive scan lock.
 */
final class ScanLock {

	/**
	 * Option name.
	 */
	const OPTION = 'speedy_sensor_scan_lock';

	/**
	 * Seconds before a lock is considered abandoned.
	 *
	 * Comfortably longer than one budgeted pass, so a crashed request does not
	 * wedge scanning until the next activation.
	 */
	const STALE_AFTER = 900;

	/**
	 * Takes the lock.
	 *
	 * @return bool True when the lock is now held by this caller.
	 */
	public static function acquire() {
		if ( self::try_acquire() ) {
			return true;
		}

		// The holder may have died mid-scan. Reclaim once if it is stale.
		if ( ! self::is_locked() || ! self::is_stale() ) {
			return false;
		}

		self::force_release();

		return self::try_acquire();
	}

	/**
	 * Single atomic attempt.
	 *
	 * @return bool
	 */
	private static function try_acquire() {
		return (bool) add_option( self::OPTION, time(), '', 'no' );
	}

	/**
	 * Releases a lock held by this caller.
	 *
	 * @return void
	 */
	public static function release() {
		delete_option( self::OPTION );
	}

	/**
	 * Releases the lock regardless of age.
	 *
	 * @return void
	 */
	public static function force_release() {
		delete_option( self::OPTION );
	}

	/**
	 * Whether the lock currently exists.
	 *
	 * @return bool
	 */
	public static function is_locked() {
		return false !== get_option( self::OPTION, false );
	}

	/**
	 * Whether the lock is older than the stale window.
	 *
	 * @return bool
	 */
	public static function is_stale() {
		$acquired = (int) get_option( self::OPTION, 0 );

		if ( 0 === $acquired ) {
			return true;
		}

		return ( time() - $acquired ) > self::STALE_AFTER;
	}
}
