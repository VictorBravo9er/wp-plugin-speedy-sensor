<?php
/**
 * Plugin inventory and per-plugin option measurement.
 *
 * Measurement is two aggregate queries per plugin rather than a query log:
 * a query log needs SAVEQUERIES on a live request, which cannot be observed
 * from cron and would tax the site if it were.
 *
 * @package Speedy_Sensor
 */

namespace Speedy_Sensor\Scanner;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the plugin list and measures each plugin's database footprint.
 */
final class PluginScanner {

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
	 * Returns the plugins to measure, Speedy Sensor itself excluded.
	 *
	 * Drop-in files are skipped: there can be hundreds, they carry no plugin
	 * header, and they share the namespace of whatever loaded them.
	 *
	 * @return array List of entries keyed by a stable index.
	 */
	public function inventory() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$entries = array();

		foreach ( (array) get_plugins() as $file => $data ) {
			if ( SPEEDY_SENSOR_BASENAME === $file ) {
				continue;
			}

			$entries[] = array(
				'plugin_file' => (string) $file,
				'plugin_name' => $this->decode( isset( $data['Name'] ) ? $data['Name'] : $file ),
				'version'     => $this->decode( isset( $data['Version'] ) ? $data['Version'] : '' ),
				'slug'        => $this->slug_from_file( $file ),
				'is_active'   => is_plugin_active( $file ) ? 1 : 0,
				'is_must_use' => 0,
			);
		}

		foreach ( $this->must_use_entries() as $entry ) {
			$entries[] = $entry;
		}

		return array_values( $entries );
	}

	/**
	 * Builds entries for must-use plugins.
	 *
	 * @return array
	 */
	private function must_use_entries() {
		if ( ! function_exists( 'get_mu_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$entries = array();

		foreach ( (array) get_mu_plugins() as $name => $file ) {
			$file = (string) $file;

			if ( '' === $file || false !== strpos( $file, 'speedy-sensor' ) ) {
				continue;
			}

			$entries[] = array(
				'plugin_file' => 'mu-plugins/' . $file,
				'plugin_name' => (string) $name,
				'version'     => '',
				'slug'        => $this->slug_from_file( $file ),
				'is_active'   => 1,
				'is_must_use' => 1,
			);
		}

		return $entries;
	}

	/**
	 * Measures one plugin's option and transient footprint.
	 *
	 * @param string $slug Slug used to match option names.
	 * @return array
	 */
	public function measure( $slug ) {
		$slug = (string) $slug;

		if ( '' === $slug ) {
			return $this->empty_measurement();
		}

		$like = $this->wpdb->esc_like( $slug ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $this->wpdb->get_row(
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is interpolated because identifiers cannot be parameterised; every value below is a placeholder.
			$this->wpdb->prepare(
				"SELECT COUNT(*) AS option_count,
					COALESCE(SUM(LENGTH(option_value)),0) AS total_bytes,
					COALESCE(SUM(CASE WHEN autoload IN ('yes','on','auto','auto-on') THEN LENGTH(option_value) ELSE 0 END),0) AS autoload_bytes
				FROM `{$this->wpdb->options}` WHERE option_name LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$like
			),
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			return $this->empty_measurement();
		}

		$timeout_prefix = $this->wpdb->esc_like( '_transient_timeout_' . $slug ) . '%';
		$transient      = $this->wpdb->esc_like( '_transient_' . $slug ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$transients = (int) $this->wpdb->get_var(
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is interpolated because identifiers cannot be parameterised; every value below is a placeholder.
			$this->wpdb->prepare(
				"SELECT COUNT(*) FROM `{$this->wpdb->options}` WHERE option_name LIKE %s OR option_name LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$transient,
				$timeout_prefix
			)
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		return array(
			'options_count'    => (int) $row['option_count'],
			'total_bytes'      => (int) $row['total_bytes'],
			'autoload_bytes'   => (int) $row['autoload_bytes'],
			'transients_count' => $transients,
		);
	}

	/**
	 * Zeroed measurement, used when a plugin cannot be measured.
	 *
	 * @return array
	 */
	private function empty_measurement() {
		return array(
			'options_count'    => 0,
			'total_bytes'      => 0,
			'autoload_bytes'   => 0,
			'transients_count' => 0,
		);
	}

	/**
	 * Derives an option-name prefix from a plugin file path.
	 *
	 * A heuristic, and it is treated as one: akismet/akismet.php yields
	 * 'akismet', so the LIKE matches akismet_options and _transient_akismet_x.
	 * It will also match a longer sibling such as 'akismet_pro'. Over-counting
	 * a neighbour is safer than missing the plugin that owns the bloat.
	 *
	 * @param string $file Plugin file path relative to the plugins directory.
	 * @return string
	 */
	public function slug_from_file( $file ) {
		$file = (string) $file;
		$slug = basename( $file, '.php' );

		if ( '' === $slug ) {
			$parts = explode( '/', $file );
			$slug  = (string) end( $parts );
		}

		// Keep the LIKE pattern to characters that cannot act as wildcards.
		return preg_replace( '/[^A-Za-z0-9_\-]/', '', $slug );
	}

	/**
	 * Turns a byte count into a readable string.
	 *
	 * @param int $bytes Byte count.
	 * @return string
	 */
	public static function format_bytes( $bytes ) {
		$bytes = (int) $bytes;

		if ( $bytes <= 0 ) {
			return '0 B';
		}

		$units = array( 'B', 'KB', 'MB', 'GB', 'TB' );
		$power = min( (int) floor( log( $bytes, 1024 ) ), count( $units ) - 1 );

		return round( $bytes / pow( 1024, $power ), $power > 1 ? 1 : 0 ) . ' ' . $units[ $power ];
	}

	/**
	 * Decodes plugin header text without rendering it.
	 *
	 * @param string $value Raw header value.
	 * @return string
	 */
	private function decode( $value ) {
		return is_string( $value ) ? sanitize_text_field( $value ) : '';
	}
}
