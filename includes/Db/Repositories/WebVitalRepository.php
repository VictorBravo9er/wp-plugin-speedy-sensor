<?php
/**
 * Cached Web Vitals, one row per day, field and route.
 *
 * The plugin never measures Web Vitals itself. Speedy.site measures them at the
 * edge and this table caches the response so the dashboard reads locally and
 * never blocks on the upstream API.
 *
 * @package Speedy_Sensor
 */

namespace Speedy_Sensor\Db\Repositories;

use Speedy_Sensor\Db\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes cached Web Vitals.
 */
final class WebVitalRepository {

	/**
	 * Rows per INSERT statement.
	 */
	const INSERT_CHUNK = 50;

	/**
	 * Longest stored route pattern. Keeps the unique key inside index limits.
	 */
	const MAX_ROUTE = 191;

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
	 * Writes rows, replacing any existing entry for the same day and field.
	 *
	 * @param array $rows Rows keyed by recorded_on, field and route.
	 * @return int Rows written.
	 */
	public function upsert( array $rows ) {
		if ( empty( $rows ) ) {
			return 0;
		}

		$table   = Schema::web_vitals_table( $this->wpdb );
		$written = 0;
		$now     = current_time( 'mysql', true );

		foreach ( array_chunk( $rows, self::INSERT_CHUNK ) as $chunk ) {
			$placeholders = array();
			$values       = array();

			foreach ( $chunk as $row ) {
				$placeholders[] = '(%s,%s,%s,%d,%f,%d,%d,%s)';

				array_push(
					$values,
					$this->normalise_date( $row['recorded_on'] ),
					$this->normalise_field( $row['field'] ),
					$this->normalise_route( $row['route'] ),
					$this->to_int_or_null( $row['lcp_ms'] ),
					$this->to_float_or_null( $row['cls'] ),
					$this->to_int_or_null( $row['inp_ms'] ),
					$this->to_int_or_null( $row['ttfb_ms'] ),
					$now
				);
			}

			$columns = '(recorded_on,field,route,lcp_ms,cls,inp_ms,ttfb_ms,updated_at)';
			$update  = 'lcp_ms = VALUES(lcp_ms), cls = VALUES(cls), inp_ms = VALUES(inp_ms), ttfb_ms = VALUES(ttfb_ms), updated_at = VALUES(updated_at)';
			$sql     = "INSERT INTO `{$table}` {$columns} VALUES " . implode( ',', $placeholders ) . " ON DUPLICATE KEY UPDATE {$update}"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			$result = $this->wpdb->query( $this->wpdb->prepare( $sql, $values ) );

			if ( false !== $result ) {
				$written += (int) $result;
			}
		}

		return $written;
	}

	/**
	 * Returns one series, oldest first.
	 *
	 * @param int    $days  Number of days back from today.
	 * @param string $field Device field, mobile or desktop.
	 * @param string $route Route pattern.
	 * @return array
	 */
	public function get_series( $days, $field, $route ) {
		$table = Schema::web_vitals_table( $this->wpdb );
		$since = gmdate( 'Y-m-d', time() - ( max( 1, (int) $days ) - 1 ) * DAY_IN_SECONDS );

		$sql = $this->wpdb->prepare(
			"SELECT recorded_on, lcp_ms, cls, inp_ms, ttfb_ms FROM `{$table}`
			 WHERE field = %s AND route = %s AND recorded_on >= %s
			 ORDER BY recorded_on ASC",
			$this->normalise_field( $field ),
			$this->normalise_route( $route ),
			$since
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $this->wpdb->get_results( $sql, ARRAY_A );

		return array_map( array( $this, 'prepare_row' ), (array) $rows );
	}

	/**
	 * Most recent entry for a series, used to label the dashboard.
	 *
	 * @param string $field Device field.
	 * @param string $route Route pattern.
	 * @return array|null
	 */
	public function get_latest( $field, $route ) {
		$table = Schema::web_vitals_table( $this->wpdb );

		$sql = $this->wpdb->prepare(
			"SELECT recorded_on, lcp_ms, cls, inp_ms, ttfb_ms FROM `{$table}`
			 WHERE field = %s AND route = %s
			 ORDER BY recorded_on DESC LIMIT 1",
			$this->normalise_field( $field ),
			$this->normalise_route( $route )
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $this->wpdb->get_row( $sql, ARRAY_A );

		return $row ? $this->prepare_row( $row ) : null;
	}

	/**
	 * Distinct route patterns present in the cache.
	 *
	 * @return array
	 */
	public function get_routes() {
		$table = Schema::web_vitals_table( $this->wpdb );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $this->wpdb->get_col( "SELECT DISTINCT route FROM `{$table}` ORDER BY route ASC" );

		return array_map( array( $this, 'normalise_route' ), (array) $rows );
	}

	/**
	 * Deletes rows older than the retention window.
	 *
	 * @param int $days Days to keep.
	 * @return void
	 */
	public function delete_older_than( $days ) {
		$table  = Schema::web_vitals_table( $this->wpdb );
		$cutoff = gmdate( 'Y-m-d', time() - max( 1, (int) $days ) * DAY_IN_SECONDS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->wpdb->query(
			$this->wpdb->prepare( "DELETE FROM `{$table}` WHERE recorded_on < %s", $cutoff ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	/**
	 * Clamps a field name to a known value.
	 *
	 * @param mixed $field Raw field.
	 * @return string
	 */
	private function normalise_field( $field ) {
		return ( is_string( $field ) && 'desktop' === $field ) ? 'desktop' : 'mobile';
	}

	/**
	 * Clamps a route to the stored width.
	 *
	 * @param mixed $route Raw route.
	 * @return string
	 */
	private function normalise_route( $route ) {
		if ( ! is_string( $route ) || '' === $route ) {
			return '/';
		}

		return substr( $route, 0, self::MAX_ROUTE );
	}

	/**
	 * Validates a Y-m-d date.
	 *
	 * @param mixed $date Raw date.
	 * @return string
	 */
	private function normalise_date( $date ) {
		$date = is_string( $date ) ? $date : '';

		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return gmdate( 'Y-m-d' );
		}

		return $date;
	}

	/**
	 * Casts to a non-negative integer or null.
	 *
	 * @param mixed $value Raw value.
	 * @return int|null
	 */
	private function to_int_or_null( $value ) {
		return is_numeric( $value ) ? max( 0, (int) $value ) : null;
	}

	/**
	 * Casts to a non-negative float or null.
	 *
	 * @param mixed $value Raw value.
	 * @return float|null
	 */
	private function to_float_or_null( $value ) {
		return is_numeric( $value ) ? max( 0.0, (float) $value ) : null;
	}

	/**
	 * Normalises a raw row for output.
	 *
	 * @param array $row Raw row.
	 * @return array
	 */
	private function prepare_row( $row ) {
		return array(
			'recorded_on' => (string) $row['recorded_on'],
			'lcp_ms'      => $this->to_int_or_null( $row['lcp_ms'] ),
			'cls'         => $this->to_float_or_null( $row['cls'] ),
			'inp_ms'      => $this->to_int_or_null( $row['inp_ms'] ),
			'ttfb_ms'     => $this->to_int_or_null( $row['ttfb_ms'] ),
		);
	}
}