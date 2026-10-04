<?php
/**
 * Schema definition and versioning.
 *
 * Four tables: one run log, one row per plugin per run, a key/value table for
 * database metrics so the metric set can grow without a migration, and one row
 * per day/field/route bucket for cached Web Vitals.
 *
 * @package Speedy_Sensor
 */

namespace Speedy_Sensor\Db;

defined( 'ABSPATH' ) || exit;

/**
 * Table names, dbDelta spec and version bookkeeping.
 */
final class Schema {

	/**
	 * Option holding the installed schema version.
	 */
	const VERSION_OPTION = 'speedy_sensor_db_version';

	/**
	 * Fully qualified scan run log table name.
	 *
	 * @param \wpdb $wpdb WordPress database handle.
	 * @return string
	 */
	public static function scans_table( $wpdb ) {
		return $wpdb->prefix . 'speedy_sensor_scans';
	}

	/**
	 * Fully qualified per-plugin metrics table name.
	 *
	 * @param \wpdb $wpdb WordPress database handle.
	 * @return string
	 */
	public static function plugin_metrics_table( $wpdb ) {
		return $wpdb->prefix . 'speedy_sensor_plugin_metrics';
	}

	/**
	 * Fully qualified database metrics table name.
	 *
	 * @param \wpdb $wpdb WordPress database handle.
	 * @return string
	 */
	public static function db_metrics_table( $wpdb ) {
		return $wpdb->prefix . 'speedy_sensor_db_metrics';
	}

	/**
	 * Fully qualified cached Web Vitals table name.
	 *
	 * @param \wpdb $wpdb WordPress database handle.
	 * @return string
	 */
	public static function web_vitals_table( $wpdb ) {
		return $wpdb->prefix . 'speedy_sensor_web_vitals';
	}

	/**
	 * Creates or updates every table.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		$sql = array();

		$sql[] = 'CREATE TABLE ' . self::scans_table( $wpdb ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			started_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			finished_at datetime DEFAULT NULL,
			status varchar(20) NOT NULL DEFAULT 'running',
			duration_ms int(10) unsigned NOT NULL DEFAULT 0,
			plugins_scanned int(10) unsigned NOT NULL DEFAULT 0,
			plugins_total int(10) unsigned NOT NULL DEFAULT 0,
			cursor_index int(10) unsigned NOT NULL DEFAULT 0,
			tables_scanned int(10) unsigned NOT NULL DEFAULT 0,
			issues_found int(10) unsigned NOT NULL DEFAULT 0,
			error_code varchar(64) DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY started_at (started_at),
			KEY status (status)
		) {$charset_collate};";

		$sql[] = 'CREATE TABLE ' . self::plugin_metrics_table( $wpdb ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			scan_id bigint(20) unsigned NOT NULL,
			plugin_file varchar(191) NOT NULL DEFAULT '',
			plugin_name varchar(191) NOT NULL DEFAULT '',
			version varchar(64) NOT NULL DEFAULT '',
			is_active tinyint(1) NOT NULL DEFAULT 0,
			is_must_use tinyint(1) NOT NULL DEFAULT 0,
			autoload_bytes bigint(20) unsigned NOT NULL DEFAULT 0,
			options_count int(10) unsigned NOT NULL DEFAULT 0,
			transients_count int(10) unsigned NOT NULL DEFAULT 0,
			table_bytes bigint(20) unsigned NOT NULL DEFAULT 0,
			table_rows bigint(20) unsigned NOT NULL DEFAULT 0,
			score tinyint(3) unsigned NOT NULL DEFAULT 0,
			findings longtext DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY scan_id (scan_id),
			KEY plugin_file (plugin_file),
			KEY score (score)
		) {$charset_collate};";

		$sql[] = 'CREATE TABLE ' . self::db_metrics_table( $wpdb ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			scan_id bigint(20) unsigned NOT NULL,
			metric_key varchar(100) NOT NULL DEFAULT '',
			metric_value bigint(20) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY scan_id (scan_id),
			KEY metric_key_scan (metric_key,scan_id)
		) {$charset_collate};";

		$sql[] = 'CREATE TABLE ' . self::web_vitals_table( $wpdb ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			recorded_on date NOT NULL DEFAULT '0000-00-00',
			field varchar(64) NOT NULL DEFAULT '',
			route varchar(191) NOT NULL DEFAULT '',
			lcp_ms int(10) unsigned DEFAULT NULL,
			cls decimal(10,4) DEFAULT NULL,
			inp_ms int(10) unsigned DEFAULT NULL,
			ttfb_ms int(10) unsigned DEFAULT NULL,
			updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY bucket (recorded_on,field,route),
			KEY route (route)
		) {$charset_collate};";

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}

		update_option( self::VERSION_OPTION, SPEEDY_SENSOR_DB_VERSION, false );
	}

	/**
	 * Returns true when the installed schema is behind the code.
	 *
	 * @return bool
	 */
	public static function needs_upgrade() {
		return (string) get_option( self::VERSION_OPTION, '' ) !== (string) SPEEDY_SENSOR_DB_VERSION;
	}
}
