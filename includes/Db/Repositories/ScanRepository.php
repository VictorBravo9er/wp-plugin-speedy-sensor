<?php
/**
 * Scan run log.
 *
 * One row per scan. Status moves running -> complete or failed, and cursor_index
 * carries the resume position so a budgeted scan can continue across ticks.
 *
 * @package Speedy_Sensor
 */

namespace Speedy_Sensor\Db\Repositories;

use Speedy_Sensor\Db\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes scan runs.
 */
final class ScanRepository {

	/**
	 * Status values.
	 */
	const STATUS_RUNNING   = 'running';
	const STATUS_COMPLETE  = 'complete';
	const STATUS_FAILED    = 'failed';
	const STATUS_CANCELLED = 'cancelled';

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
	 * Opens a new scan run.
	 *
	 * @return int Inserted scan id, or 0 on failure.
	 */
	public function start() {
		$result = $this->wpdb->insert(
			Schema::scans_table( $this->wpdb ),
			array(
				'started_at'     => current_time( 'mysql', true ),
				'status'         => self::STATUS_RUNNING,
				'plugins_total'  => 0,
				'cursor_index'   => 0,
				'plugins_scanned' => 0,
			),
			array( '%s', '%s', '%d', '%d', '%d' )
		);

		return $result ? (int) $this->wpdb->insert_id : 0;
	}

	/**
	 * Returns one run.
	 *
	 * @param int $scan_id Scan id.
	 * @return array|null
	 */
	public function get( $scan_id ) {
		$table = Schema::scans_table( $this->wpdb );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d", $scan_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return $row ? $this->cast_row( $row ) : null;
	}

	/**
	 * Returns the most recent run with a given status.
	 *
	 * @param string $status Status constant.
	 * @return array|null
	 */
	public function get_latest( $status = self::STATUS_COMPLETE ) {
		$table = Schema::scans_table( $this->wpdb );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM `{$table}` WHERE status = %s ORDER BY id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$status
			),
			ARRAY_A
		);

		return $row ? $this->cast_row( $row ) : null;
	}

	/**
	 * Returns a run left unfinished by an earlier tick.
	 *
	 * @return array|null
	 */
	public function get_resumable() {
		return $this->get_latest( self::STATUS_RUNNING );
	}

	/**
	 * Updates progress columns on a run.
	 *
	 * @param int   $scan_id Scan id.
	 * @param array $fields  Column values.
	 * @return bool
	 */
	public function update( $scan_id, array $fields ) {
		return false !== $this->wpdb->update(
			Schema::scans_table( $this->wpdb ),
			$fields,
			array( 'id' => (int) $scan_id ),
			null,
			array( '%d' )
		);
	}

	/**
	 * Closes a run as successful.
	 *
	 * @param int   $scan_id Scan id.
	 * @param array $fields  Extra columns.
	 * @return bool
	 */
	public function complete( $scan_id, array $fields = array() ) {
		return $this->update(
			$scan_id,
			array_merge(
				$fields,
				array(
					'status'     => self::STATUS_COMPLETE,
					'finished_at' => current_time( 'mysql', true ),
				)
			)
		);
	}

	/**
	 * Closes a run as failed.
	 *
	 * @param int    $scan_id   Scan id.
	 * @param string $error_code Short machine code.
	 * @return bool
	 */
	public function fail( $scan_id, $error_code ) {
		return $this->update(
			$scan_id,
			array(
				'status'      => self::STATUS_FAILED,
				'error_code'  => substr( (string) $error_code, 0, 64 ),
				'finished_at' => current_time( 'mysql', true ),
			)
		);
	}

	/**
	 * Returns ids of completed runs beyond the newest $keep entries.
	 *
	 * Only completed runs are considered, so an in-progress run and its rows
	 * survive pruning even when retention is set to a small number.
	 *
	 * @param int $keep Number of completed runs to keep.
	 * @return int[]
	 */
	public function get_ids_beyond( $keep ) {
		$table = Schema::scans_table( $this->wpdb );
		$keep  = max( 1, (int) $keep );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $this->wpdb->get_col(
			$this->wpdb->prepare(
				"SELECT id FROM `{$table}`
				 WHERE status = %s AND id NOT IN (
					SELECT id FROM (SELECT id FROM `{$table}` WHERE status = %s ORDER BY id DESC LIMIT %d) AS keep_rows
				 )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				self::STATUS_COMPLETE,
				self::STATUS_COMPLETE,
				$keep
			)
		);

		$clean = array();

		foreach ( (array) $ids as $id ) {
			if ( is_numeric( $id ) && (int) $id > 0 ) {
				$clean[] = (int) $id;
			}
		}

		return $clean;
	}

	/**
	 * Deletes runs by id.
	 *
	 * @param int[] $ids Scan ids.
	 * @return int Rows deleted.
	 */
	public function delete_by_ids( array $ids ) {
		$ids = array_values(
			array_filter(
				array_map( 'intval', $ids ),
				static function ( $id ) {
					return $id > 0;
				}
			)
		);

		if ( empty( $ids ) ) {
			return 0;
		}

		$table   = Schema::scans_table( $this->wpdb );
		$formats = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $this->wpdb->query(
			$this->wpdb->prepare( "DELETE FROM `{$table}` WHERE id IN ({$formats})", $ids ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	/**
	 * Normalises a raw row for output: casts numeric strings to integers.
	 *
	 * @param array $row Raw row.
	 * @return array
	 */
	private function cast_row( $row ) {
		foreach ( array( 'id', 'duration_ms', 'plugins_scanned', 'plugins_total', 'cursor_index', 'tables_scanned', 'issues_found' ) as $key ) {
			$row[ $key ] = (int) $row[ $key ];
		}

		return $row;
	}
}