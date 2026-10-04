<?php
/**
 * Database screen.
 *
 * Shows the whole-site database footprint and the two bloat signals that
 * actually cost a WordPress site something: autoloaded options and orphaned
 * transients.
 *
 * @package Speedy_Sensor
 */

namespace Speedy_Sensor\Admin\Pages;

use Speedy_Sensor\Admin\View;
use Speedy_Sensor\Db\Repositories\DbMetricRepository;
use Speedy_Sensor\Db\Repositories\ScanRepository;
use Speedy_Sensor\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the database screen.
 */
final class Database {

	/**
	 * Autoload bytes above which the tile is flagged.
	 */
	const AUTOLOAD_WARN = 819200;

	/**
	 * Renders the screen.
	 *
	 * @param Settings $settings Settings store.
	 * @return void
	 */
	public static function render( Settings $settings ) {
		unset( $settings );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this screen.', 'speedy-sensor' ) );
		}

		$scan    = ( new ScanRepository() )->get_latest( ScanRepository::STATUS_COMPLETE );
		$metrics = $scan ? ( new DbMetricRepository() )->get_by_scan( (int) $scan['id'] ) : array();

		echo '<div class="wrap speedy-wrap">';
		echo '<h1>' . esc_html__( 'Database', 'speedy-sensor' ) . '</h1>';

		if ( ! $scan ) {
			echo '<p>' . esc_html__( 'No scan results yet. Run a scan to measure the database.', 'speedy-sensor' ) . '</p>';
			echo '</div>';

			return;
		}

		self::render_tiles( $metrics );
		self::render_bloat( $metrics );

		echo '</div>';
	}

	/**
	 * Size tiles.
	 *
	 * @param array $metrics Database metrics.
	 * @return void
	 */
	private static function render_tiles( array $metrics ) {
		$autoload = isset( $metrics['db_autoload_bytes'] ) ? (int) $metrics['db_autoload_bytes'] : 0;

		echo '<div class="speedy-card">';
		echo '<h2>' . esc_html__( 'Size', 'speedy-sensor' ) . '</h2>';
		echo '<div class="speedy-grid">';

		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- View::tile() escapes every argument internally.
		echo View::tile(
			__( 'Total size', 'speedy-sensor' ),
			View::bytes( isset( $metrics['db_total_bytes'] ) ? $metrics['db_total_bytes'] : 0 ),
			sprintf(
				/* translators: %d: number of tables. */
				__( '%d tables', 'speedy-sensor' ),
				isset( $metrics['db_table_count'] ) ? (int) $metrics['db_table_count'] : 0
			)
		);
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped

		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- View::tile() escapes every argument internally.
		echo View::tile(
			__( 'Autoloaded options', 'speedy-sensor' ),
			View::bytes( $autoload ),
			sprintf(
				/* translators: %s: number of options. */
				__( '%s options, loaded on every request', 'speedy-sensor' ),
				number_format_i18n( isset( $metrics['db_autoload_option_count'] ) ? $metrics['db_autoload_option_count'] : 0 )
			),
			$autoload > self::AUTOLOAD_WARN ? 'poor' : 'good'
		);
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped

		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- View::tile() escapes every argument internally.
		echo View::tile(
			__( 'All options', 'speedy-sensor' ),
			number_format_i18n( isset( $metrics['db_option_count'] ) ? $metrics['db_option_count'] : 0 ),
			__( 'Rows in the options table', 'speedy-sensor' )
		);
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped

		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- View::tile() escapes every argument internally.
		echo View::tile(
			__( 'Largest table', 'speedy-sensor' ),
			View::bytes( isset( $metrics['db_largest_table_bytes'] ) ? $metrics['db_largest_table_bytes'] : 0 ),
			sprintf(
				/* translators: %s: approximate row count. */
				__( 'About %s rows', 'speedy-sensor' ),
				number_format_i18n( isset( $metrics['db_largest_table_rows'] ) ? $metrics['db_largest_table_rows'] : 0 )
			)
		);
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped

		echo '</div></div>';
	}

	/**
	 * Transient bloat, which is the part a site owner can actually clear.
	 *
	 * @param array $metrics Database metrics.
	 * @return void
	 */
	private static function render_bloat( array $metrics ) {
		$orphaned = isset( $metrics['db_orphaned_transient_count'] ) ? (int) $metrics['db_orphaned_transient_count'] : 0;
		$expired  = isset( $metrics['db_expired_transient_count'] ) ? $metrics['db_expired_transient_count'] : 0;

		echo '<div class="speedy-card">';
		echo '<h2>' . esc_html__( 'Temporary data', 'speedy-sensor' ) . '</h2>';
		echo '<div class="speedy-grid">';

		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- View::tile() escapes every argument internally.
		echo View::tile(
			__( 'Transient records', 'speedy-sensor' ),
			number_format_i18n( isset( $metrics['db_transient_count'] ) ? $metrics['db_transient_count'] : 0 ),
			__( 'Cached data with a timeout', 'speedy-sensor' )
		);
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped

		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- View::tile() escapes every argument internally.
		echo View::tile(
			__( 'Expired, not yet cleared', 'speedy-sensor' ),
			number_format_i18n( (int) $expired ),
			__( 'Cleared on the next request that reads them', 'speedy-sensor' ),
			(int) $expired > 0 ? 'needs-improvement' : 'good'
		);
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped

		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- View::tile() escapes every argument internally.
		echo View::tile(
			__( 'Orphaned records', 'speedy-sensor' ),
			number_format_i18n( $orphaned ),
			__( 'Timeout row missing, so they never clear', 'speedy-sensor' ),
			$orphaned > 0 ? 'poor' : 'good'
		);
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped

		echo '</div>';

		if ( $orphaned > 0 ) {
			echo '<p class="speedy-callout">';
			esc_html_e( 'Orphaned transients only disappear if the plugin that created them deletes them cleanly. Speedy can clean these up for you as part of an optimisation request.', 'speedy-sensor' );
			echo '</p>';
		}

		echo '</div>';
	}
}
