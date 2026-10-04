<?php
/**
 * Database metrics, stored as key/value pairs per scan.
 *
 * Key/value rather than typed columns so a new measurement can be added in a
 * later release without a schema migration.
 *
 * @package Speedy_Sensor
 */

namespace Speedy_Sensor\Db\Repositories;

use Speedy_Sensor\Db\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes database metrics.
 */
final class DbMetricRepository {

	/**
	 * Rows per INSERT statement.
	 */
	const INSERT_CHUNK = 100;

	/**
	 * Database handle.
	 *
	 * @var \wpdb
	 */
	private $wpdb;

	/**
	 * Constructor.
	 */
	public function __construct() {
		global $wpdb;

		$this->wpdb = $wpdb;
	}

	/**
	 * Replaces every metric row for a scan.
	 *
	 * @param int   $scan_id Scan id.
	 * @param array $metrics Map of metric_key => numeric value.
	 * @return int Rows written.
	 */
	public function replace_for_scan( $scan_id, array $metrics ) {
		$this->delete_for_scan( $scan_id );

		if ( empty( $metrics ) ) {
			return 0;
		}

		$table        = Schema::db_metrics_table( $this->wpdb );
		$placeholders = array();
		$values       = array();

		foreach ( $metrics as $key => $value ) {
			$placeholders[] = '(%d,%s,%d)';

			array_push( $values, (int) $scan_id, (string) $key, (int) $value );
		}

		$written = 0;

		foreach ( array_chunk( $placeholders, self::INSERT_CHUNK ) as $chunk_index => $chunk ) {
			$sql = "INSERT INTO `{$table}` (scan_id,metric_key,metric_value) VALUES " . implode( ',', $chunk ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			// Flatten the matching slice of values so prepare() sees pairs only.
			$slice = array_slice( $values, $chunk_index * self::INSERT_CHUNK * 3, count( $chunk ) * 3 );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			$result = $this->wpdb->query( $this->wpdb->prepare( $sql, $slice ) );

			if ( false !== $result ) {
				$written += (int) $result;
			}
		}

		return $written;
	}

	/**
	 * Returns metrics for a scan as a key => value map.
	 *
	 * @param int $scan_id Scan id.
	 * @return array
	 */
	public function get_by_scan( $scan_id ) {
		$table = Schema::db_metrics_table( $this->wpdb );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $this->wpdb->get_results(
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- -- Table name is interpolated because identifiers cannot be parameterised; every value below is a placeholder.
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is interpolated because identifiers cannot be parameterised; every value below is a placeholder.
			$this->wpdb->prepare(
				"SELECT metric_key, metric_value FROM `{$table}` WHERE scan_id = %d ORDER BY metric_key ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$scan_id
			),
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		$metrics = array();

		foreach ( (array) $rows as $row ) {
			$metrics[ (string) $row['metric_key'] ] = (int) $row['metric_value'];
		}

		return $metrics;
	}

	/**
	 * Deletes every metric row for one scan.
	 *
	 * @param int $scan_id Scan id.
	 * @return void
	 */
	public function delete_for_scan( $scan_id ) {
		$table = Schema::db_metrics_table( $this->wpdb );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->wpdb->query(
			$this->wpdb->prepare( "DELETE FROM `{$table}` WHERE scan_id = %d", $scan_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		);
	}

	/**
	 * Deletes metric rows for scans that are no longer retained.
	 *
	 * @param array $scan_ids Scan ids.
	 * @return void
	 */
	public function delete_for_scans( array $scan_ids ) {
		foreach ( $this->normalise_ids( $scan_ids ) as $scan_id ) {
			$this->delete_for_scan( $scan_id );
		}
	}

	/**
	 * Reduces a mixed list to unique positive integers.
	 *
	 * @param array $ids Raw ids.
	 * @return int[]
	 */
	private function normalise_ids( array $ids ) {
		$clean = array();

		foreach ( $ids as $id ) {
			if ( is_numeric( $id ) && (int) $id > 0 ) {
				$clean[] = (int) $id;
			}
		}

		return array_values( array_unique( $clean ) );
	}
}
