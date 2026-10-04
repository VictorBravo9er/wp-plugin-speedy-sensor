<?php
/**
 * Per-plugin metric rows, one per plugin per scan.
 *
 * @package Speedy_Sensor
 */

namespace Speedy_Sensor\Db\Repositories;

use Speedy_Sensor\Db\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes plugin metrics.
 */
final class PluginMetricRepository {

	/**
	 * Rows per INSERT statement. Keeps the statement well under the
	 * max_allowed_packet limit on a site with hundreds of plugins.
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
	 * Bulk-inserts metric rows for a scan.
	 *
	 * @param int   $scan_id Scan id.
	 * @param array $rows    Metric rows.
	 * @return int Rows written.
	 */
	public function insert_many( $scan_id, array $rows ) {
		if ( empty( $rows ) ) {
			return 0;
		}

		$table   = Schema::plugin_metrics_table( $this->wpdb );
		$columns = '(scan_id,plugin_file,plugin_name,version,is_active,is_must_use,autoload_bytes,options_count,transients_count,table_bytes,table_rows,score,findings)';
		$written = 0;

		foreach ( array_chunk( $rows, self::INSERT_CHUNK ) as $chunk ) {
			$placeholders = array();
			$values       = array();

			foreach ( $chunk as $row ) {
				$placeholders[] = '(%d,%s,%s,%s,%d,%d,%d,%d,%d,%d,%d,%d,%s)';

				array_push(
					$values,
					(int) $scan_id,
					(string) $row['plugin_file'],
					(string) $row['plugin_name'],
					(string) $row['version'],
					(int) $row['is_active'],
					(int) $row['is_must_use'],
					(int) $row['autoload_bytes'],
					(int) $row['options_count'],
					(int) $row['transients_count'],
					(int) $row['table_bytes'],
					(int) $row['table_rows'],
					(int) $row['score'],
					(string) $row['findings']
				);
			}

			$sql = "INSERT INTO `{$table}` {$columns} VALUES " . implode( ',', $placeholders ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			$result = $this->wpdb->query( $this->wpdb->prepare( $sql, $values ) );

			if ( false !== $result ) {
				$written += (int) $result;
			}
		}

		return $written;
	}

	/**
	 * Returns metric rows for a scan.
	 *
	 * @param int    $scan_id Scan id.
	 * @param int    $limit   Row limit.
	 * @param int    $offset  Row offset.
	 * @param string $order   Either 'score' or 'name'.
	 * @return array
	 */
	public function get_by_scan( $scan_id, $limit = 50, $offset = 0, $order = 'score' ) {
		$table = Schema::plugin_metrics_table( $this->wpdb );

		// Allowlist: identifiers cannot be parameterised, so they are matched
		// against literals rather than interpolated from request input.
		$order_sql = ( 'name' === $order )
			? 'plugin_name ASC, plugin_file ASC'
			: 'score DESC, autoload_bytes DESC, plugin_name ASC';

		$sql = $this->wpdb->prepare(
			"SELECT * FROM `{$table}` WHERE scan_id = %d ORDER BY {$order_sql} LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$scan_id,
			max( 1, (int) $limit ),
			max( 0, (int) $offset )
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- $sql was prepared on the line above.
		$rows = $this->wpdb->get_results( $sql, ARRAY_A );

		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_map( array( $this, 'prepare_row' ), $rows );
	}

	/**
	 * Counts rows for a scan.
	 *
	 * @param int $scan_id Scan id.
	 * @return int
	 */
	public function count_by_scan( $scan_id ) {
		$table = Schema::plugin_metrics_table( $this->wpdb );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $this->wpdb->get_var(
			$this->wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE scan_id = %d", $scan_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		);
	}

	/**
	 * Deletes rows belonging to scans that are no longer retained.
	 *
	 * @param array $scan_ids Scan ids.
	 * @return void
	 */
	public function delete_for_scans( array $scan_ids ) {
		$ids = $this->normalise_ids( $scan_ids );

		if ( empty( $ids ) ) {
			return;
		}

		$table   = Schema::plugin_metrics_table( $this->wpdb );
		$formats = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->wpdb->query(
			$this->wpdb->prepare( "DELETE FROM `{$table}` WHERE scan_id IN ({$formats})", $ids ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		);
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

	/**
	 * Normalises a raw row for output: casts, booleans and decoded findings.
	 *
	 * @param array $row Raw row.
	 * @return array
	 */
	private function prepare_row( $row ) {
		foreach ( array( 'id', 'scan_id', 'autoload_bytes', 'options_count', 'transients_count', 'table_bytes', 'table_rows', 'score' ) as $key ) {
			$row[ $key ] = (int) $row[ $key ];
		}

		$row['is_active']   = ! empty( $row['is_active'] );
		$row['is_must_use'] = ! empty( $row['is_must_use'] );
		$row['findings']    = $this->decode_findings( isset( $row['findings'] ) ? $row['findings'] : '' );

		return $row;
	}

	/**
	 * Decodes the findings column.
	 *
	 * Never unserialises: this is JSON written by this class, and a decode
	 * failure degrades to an empty list rather than a fatal.
	 *
	 * @param string $json Stored JSON.
	 * @return array
	 */
	private function decode_findings( $json ) {
		if ( '' === (string) $json ) {
			return array();
		}

		$decoded = json_decode( (string) $json, true );

		return ( is_array( $decoded ) && isset( $decoded[0] ) && is_array( $decoded[0] ) ) ? $decoded : array();
	}
}
