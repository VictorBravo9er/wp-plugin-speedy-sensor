<?php
/**
 * Attribution: which plugin is responsible for which database cost.
 *
 * Inventory based, not sampling based. Sampling would need SAVEQUERIES on a
 * live request, which is invasive and unrepresentative of cron traffic. This
 * approach is deterministic, cheap, and every number can be traced back to a
 * concrete option or table the site owner can look at.
 *
 * Findings are stored as codes plus values, never as translated text, so the
 * report stays translatable after it has been written to the database.
 *
 * @package Speedy_Sensor
 */

namespace Speedy_Sensor\Scanner;

defined( 'ABSPATH' ) || exit;

/**
 * Scores plugins and explains the score.
 */
final class Attribution {

	/**
	 * Autoload bytes at which a plugin is flagged as heavy.
	 */
	const HIGH_AUTLOAD_BYTES = 512000;

	/**
	 * Transient count at which a plugin is flagged.
	 */
	const MANY_TRANSIENTS = 25;

	/**
	 * Table bytes at which a plugin is flagged.
	 */
	const LARGE_TABLE_BYTES = 5242880;

	/**
	 * Option count at which a plugin is flagged.
	 */
	const MANY_OPTIONS = 150;

	/**
	 * Findings serious enough to count as issues.
	 */
	const ISSUE_CODES = array( 'high_autoload', 'many_transients', 'large_tables' );

	/**
	 * Builds the metric row for one plugin.
	 *
	 * @param array $entry       Inventory entry.
	 * @param array $measurement Option and transient measurement.
	 * @param array $tables      Table inventory keyed by lowercase name.
	 * @return array Row ready for insert_many().
	 */
	public function attribute( array $entry, array $measurement, array $tables ) {
		$owned       = $this->owned_tables( $entry['slug'], $tables );
		$table_bytes = 0;
		$table_rows  = 0;

		foreach ( $owned as $data ) {
			$table_bytes += $data['bytes'];
			$table_rows  += $data['rows'];
		}

		$row = array(
			'plugin_file'      => (string) $entry['plugin_file'],
			'plugin_name'      => (string) $entry['plugin_name'],
			'version'          => (string) $entry['version'],
			'is_active'        => (int) $entry['is_active'],
			'is_must_use'      => (int) $entry['is_must_use'],
			'autoload_bytes'   => (int) $measurement['autoload_bytes'],
			'options_count'    => (int) $measurement['options_count'],
			'transients_count' => (int) $measurement['transients_count'],
			'table_bytes'      => $table_bytes,
			'table_rows'       => $table_rows,
			'score'            => $this->score( $measurement, $table_bytes ),
			'findings'         => wp_json_encode(
				$this->findings( $entry, $measurement, $table_bytes )
			),
		);

		/**
		 * Filters a plugin's metric row before it is written.
		 *
		 * @param array $row    Metric row.
		 * @param array $entry  Inventory entry.
		 * @param array $tables Table inventory.
		 */
		return apply_filters( 'speedy_sensor_plugin_metrics', $row, $entry, $tables );
	}

	/**
	 * Turns measurements into a 0-100 concern score.
	 *
	 * Weights are deliberately blunt. Autoloaded options dominate because they
	 * load on every single request; table size matters but is not in the hot
	 * path.
	 *
	 * @param array $measurement Measurement.
	 * @param int   $table_bytes Bytes owned by the plugin.
	 * @return int
	 */
	public function score( array $measurement, $table_bytes ) {
		$score = 0;

		// 1 MB of autoload data saturates the autoload allowance.
		$score += (int) min( 40, round( $measurement['autoload_bytes'] / 25000 ) );

		// 10 MB of tables saturates the storage allowance.
		$score += (int) min( 25, round( $table_bytes / 400000 ) );

		// 40 transients saturate the transient allowance.
		$score += (int) min( 20, round( $measurement['transients_count'] * 0.5 ) );

		// 300 options saturate the option-count allowance.
		$score += (int) min( 15, round( $measurement['options_count'] * 0.05 ) );

		return (int) max( 0, min( 100, $score ) );
	}

	/**
	 * Lists the findings for a plugin as code and value pairs.
	 *
	 * @param array $entry       Inventory entry.
	 * @param array $measurement Measurement.
	 * @param int   $table_bytes Bytes owned by the plugin.
	 * @return array
	 */
	public function findings( array $entry, array $measurement, $table_bytes ) {
		$findings = array();

		if ( $measurement['autoload_bytes'] >= self::HIGH_AUTLOAD_BYTES ) {
			$findings[] = array(
				'code'  => 'high_autoload',
				'value' => (int) $measurement['autoload_bytes'],
			);
		}

		if ( $measurement['transients_count'] >= self::MANY_TRANSIENTS ) {
			$findings[] = array(
				'code'  => 'many_transients',
				'value' => (int) $measurement['transients_count'],
			);
		}

		if ( $table_bytes >= self::LARGE_TABLE_BYTES ) {
			$findings[] = array(
				'code'  => 'large_tables',
				'value' => (int) $table_bytes,
			);
		}

		if ( $measurement['options_count'] >= self::MANY_OPTIONS ) {
			$findings[] = array(
				'code'  => 'many_options',
				'value' => (int) $measurement['options_count'],
			);
		}

		if ( empty( $entry['is_active'] ) ) {
			$findings[] = array(
				'code'  => 'inactive_plugin',
				'value' => 0,
			);
		}

		if ( ! empty( $entry['is_must_use'] ) ) {
			$findings[] = array(
				'code'  => 'must_use_plugin',
				'value' => 0,
			);
		}

		/**
		 * Filters the findings attached to a plugin.
		 *
		 * @param array $findings   Findings as code and value pairs.
		 * @param array $entry      Inventory entry.
		 * @param array $measurement Measurement.
		 */
		return apply_filters( 'speedy_sensor_findings', $findings, $entry, $measurement );
	}

	/**
	 * Finds tables a plugin is likely to own.
	 *
	 * Matching on slug against the table name is a heuristic. Custom table
	 * prefixes are usually slug-derived, so this is right most of the time and
	 * wrong in a way the UI can show, never silently.
	 *
	 * @param string $slug   Plugin slug.
	 * @param array  $tables Table inventory.
	 * @return array Matching entries.
	 */
	public function owned_tables( $slug, array $tables ) {
		$slug  = strtolower( (string) $slug );
		$owned = array();

		// Too short to match safely: 'wp' or 'db' would match everything.
		if ( strlen( $slug ) < 3 ) {
			return $owned;
		}

		foreach ( $tables as $name => $data ) {
			if ( in_array( $name, DatabaseScanner::CORE_TABLES, true ) ) {
				continue;
			}

			// Strip the site prefix so wp_akismet_foo and akismet_foo both match.
			$bare = preg_replace( '/^[a-z0-9]+_/', '', $name );

			if ( false !== strpos( $name, $slug ) || ( is_string( $bare ) && false !== strpos( $bare, $slug ) ) ) {
				$owned[ $name ] = $data;
			}
		}

		return $owned;
	}

	/**
	 * Counts findings serious enough to report as issues.
	 *
	 * @param array $rows Metric rows, with findings as JSON or array.
	 * @return int
	 */
	public static function count_issues( array $rows ) {
		$count = 0;

		foreach ( $rows as $row ) {
			$findings = isset( $row['findings'] ) ? $row['findings'] : array();

			if ( is_string( $findings ) ) {
				$findings = json_decode( $findings, true );
			}

			if ( ! is_array( $findings ) ) {
				continue;
			}

			foreach ( $findings as $finding ) {
				if ( isset( $finding['code'] ) && in_array( $finding['code'], self::ISSUE_CODES, true ) ) {
					++$count;
				}
			}
		}

		return $count;
	}
}