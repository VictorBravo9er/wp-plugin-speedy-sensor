<?php
/**
 * Whole-site database footprint.
 *
 * Runs once per scan, not per plugin: table sizes are read in a single pass and
 * then matched against plugin slugs in PHP. Reading information_schema per
 * plugin would be the single most expensive thing the plugin could do.
 *
 * @package Speedy_Sensor
 */

namespace Speedy_Sensor\Scanner;

defined( 'ABSPATH' ) || exit;

/**
 * Measures database size and health signals.
 */
final class DatabaseScanner {

	/**
	 * Tables that belong to WordPress itself. Never attributed to a plugin.
	 */
	const CORE_TABLES = array(
		'posts',
		'postmeta',
		'options',
		'users',
		'usermeta',
		'comments',
		'commentmeta',
		'terms',
		'term_taxonomy',
		'term_relationships',
		'links',
		'blogmeta',
		'blogs',
		'signups',
		'sitemeta',
		'termmeta',
	);

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
	 * Autoload values WordPress writes, historical and current.
	 *
	 * WordPress 6.6 introduced auto-on, auto-off and on. Covering all of them
	 * keeps the number correct across the versions a plugin like this ships to.
	 */
	const AUTOLOAD_VALUES = "'yes','on','auto','auto-on'";

	/**
	 * Returns every database metric for a scan.
	 *
	 * @return array Map of metric key to integer value.
	 */
	public function metrics() {
		$metrics = array();

		$inventory = $this->table_inventory();
		$total     = 0;

		foreach ( $inventory as $data ) {
			$total += $data['bytes'];
		}

		$metrics['db_total_bytes'] = $total;
		$metrics['db_table_count'] = count( $inventory );

		$autoload = $this->autoload_totals();

		$metrics['db_autoload_bytes']        = $autoload['bytes'];
		$metrics['db_autoload_option_count'] = $autoload['count'];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$metrics['db_option_count'] = (int) $this->wpdb->get_var(
			$this->wpdb->prepare( 'SELECT COUNT(*) FROM `' . $this->wpdb->options . '`' ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);

		$transient = $this->wpdb->esc_like( '_transient_' ) . '%';
		$timeout   = $this->wpdb->esc_like( '_transient_timeout_' ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$metrics['db_transient_count'] = (int) $this->wpdb->get_var(
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is interpolated because identifiers cannot be parameterised; every value below is a placeholder.
			$this->wpdb->prepare(
				"SELECT COUNT(*) FROM `{$this->wpdb->options}`
				 WHERE option_name LIKE %s AND option_name NOT LIKE %s",
				$transient,
				$timeout
			)
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$metrics['db_expired_transient_count'] = (int) $this->wpdb->get_var(
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is interpolated because identifiers cannot be parameterised; every value below is a placeholder.
			$this->wpdb->prepare(
				"SELECT COUNT(*) FROM `{$this->wpdb->options}`
				 WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) < UNIX_TIMESTAMP()",
				$timeout
			)
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		$metrics['db_orphaned_transient_count'] = $this->count_orphaned_transients();

		$largest = $this->largest_from( $inventory );

		$metrics['db_largest_table_bytes'] = (int) $largest['bytes'];
		$metrics['db_largest_table_rows']  = (int) $largest['rows'];

		/**
		 * Filters the database metrics collected for a scan.
		 *
		 * @param array $metrics Metric map.
		 */
		return apply_filters( 'speedy_sensor_db_metrics', $metrics );
	}

	/**
	 * Returns size and row count for every table in this database.
	 *
	 * TABLE_ROWS is an InnoDB estimate, not an exact count. Counting rows for
	 * every table would be the most expensive operation in the plugin.
	 *
	 * @return array Map of lowercase table name to bytes and rows.
	 */
	public function table_inventory() {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $this->wpdb->get_results(
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is interpolated because identifiers cannot be parameterised; every value below is a placeholder.
			$this->wpdb->prepare(
				'SELECT TABLE_NAME,
					COALESCE(DATA_LENGTH,0) + COALESCE(INDEX_LENGTH,0) AS bytes,
					COALESCE(TABLE_ROWS,0) AS rows
				 FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s',
				DB_NAME
			),
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		$inventory = array();

		foreach ( (array) $rows as $row ) {
			$name = (string) $row['TABLE_NAME'];

			if ( '' === $name ) {
				continue;
			}

			$inventory[ strtolower( $name ) ] = array(
				'bytes' => (int) $row['bytes'],
				'rows'  => (int) $row['rows'],
			);
		}

		return $inventory;
	}

	/**
	 * Largest table by bytes, for the dashboard headline.
	 *
	 * @return array Name, bytes and rows.
	 */
	public function get_largest_table() {
		return $this->largest_from( $this->table_inventory() );
	}

	/**
	 * Picks the largest entry from an inventory.
	 *
	 * @param array $inventory Table inventory.
	 * @return array
	 */
	private function largest_from( array $inventory ) {
		$largest = array(
			'name'  => '',
			'bytes' => 0,
			'rows'  => 0,
		);

		foreach ( $inventory as $name => $data ) {
			if ( $data['bytes'] > $largest['bytes'] ) {
				$largest = array(
					'name'  => $name,
					'bytes' => $data['bytes'],
					'rows'  => $data['rows'],
				);
			}
		}

		return $largest;
	}

	/**
	 * Autoload byte total and row count.
	 *
	 * @return array
	 */
	private function autoload_totals() {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $this->wpdb->get_row(
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is interpolated because identifiers cannot be parameterised; every value below is a placeholder.
			$this->wpdb->prepare(
				"SELECT COUNT(*) AS total, COALESCE(SUM(LENGTH(option_value)),0) AS bytes
				 FROM `{$this->wpdb->options}` WHERE autoload IN (" . self::AUTOLOAD_VALUES . ')' // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			),
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return array(
			'count' => is_array( $row ) ? (int) $row['total'] : 0,
			'bytes' => is_array( $row ) ? (int) $row['bytes'] : 0,
		);
	}

	/**
	 * Counts transients whose timeout row has already been deleted.
	 *
	 * These rows can never expire again and sit in the options table forever.
	 *
	 * @return int
	 */
	private function count_orphaned_transients() {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $this->wpdb->get_var(
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is interpolated because identifiers cannot be parameterised; every value below is a placeholder.
			$this->wpdb->prepare(
				"SELECT COUNT(*) FROM `{$this->wpdb->options}` t
				 LEFT JOIN `{$this->wpdb->options}` o
					ON o.option_name = CONCAT('_transient_timeout_', SUBSTRING(t.option_name, %d))
				 WHERE t.option_name LIKE %s
					AND t.option_name NOT LIKE %s
					AND o.option_id IS NULL",
				strlen( '_transient_' ),
				$this->wpdb->esc_like( '_transient_' ) . '%',
				$this->wpdb->esc_like( '_transient_timeout_' ) . '%'
			)
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}
}
