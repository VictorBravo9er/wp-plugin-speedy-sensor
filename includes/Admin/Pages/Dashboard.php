<?php
/**
 * Overview screen.
 *
 * Answers three questions in order: how the site is performing on Core Web
 * Vitals, what is costing it internally, and what the admin can do about it.
 *
 * @package Speedy_Sensor
 */

namespace Speedy_Sensor\Admin\Pages;

use Speedy_Sensor\Admin\Actions;
use Speedy_Sensor\Admin\View;
use Speedy_Sensor\Db\Repositories\DbMetricRepository;
use Speedy_Sensor\Db\Repositories\ScanRepository;
use Speedy_Sensor\Integration\WebVitalsService;
use Speedy_Sensor\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the overview.
 */
final class Dashboard {

	/**
	 * Renders the screen.
	 *
	 * @param Settings $settings Settings store.
	 * @return void
	 */
	public static function render( Settings $settings ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this screen.', 'speedy-sensor' ) );
		}

		$vitals = new WebVitalsService( $settings );
		$series = $vitals->series( WebVitalsService::WINDOW_DAYS, 'mobile', '/' );
		$latest = $vitals->latest( 'mobile', '/' );
		$last   = $vitals->last_refresh();

		$scans   = new ScanRepository();
		$scan    = $scans->get_latest( ScanRepository::STATUS_COMPLETE );
		$metrics = $scan ? ( new DbMetricRepository() )->get_by_scan( (int) $scan['id'] ) : array();

		echo '<div class="wrap speedy-wrap">';
		echo '<h1>' . esc_html__( 'Speedy Sensor', 'speedy-sensor' ) . '</h1>';
		echo '<p class="speedy-subtitle">' . esc_html__( 'Site health from the inside. Speedy Sensor runs in the background and never slows the front end.', 'speedy-sensor' ) . '</p>';

		self::render_vitals( $series, $latest, $last, $settings );
		self::render_health( $scan, $metrics );
		self::render_actions( $scan, $settings );

		echo '</div>';
	}

	/**
	 * Web Vitals status and the seven day trend.
	 *
	 * @param array      $series   Cached series.
	 * @param array|null $latest   Most recent entry.
	 * @param int        $last     Timestamp of last refresh.
	 * @param Settings   $settings Settings store.
	 * @return void
	 */
	private static function render_vitals( array $series, $latest, $last, Settings $settings ) {
		echo '<div class="speedy-card">';

		echo '<div class="speedy-card__head">';
		echo '<h2>' . esc_html__( 'Web Vitals', 'speedy-sensor' ) . '</h2>';

		if ( $settings->is_linked() && $settings->get( 'vitals_enabled', true ) ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( Actions::NONCE_VITALS );
			echo '<input type="hidden" name="action" value="speedy_sensor_refresh_vitals" />';
			echo '<button type="submit" class="button">' . esc_html__( 'Refresh from Speedy', 'speedy-sensor' ) . '</button>';
			echo '</form>';
		}

		echo '</div>';

		if ( empty( $latest ) ) {
			echo '<p>' . esc_html__( 'No Web Vitals cached yet. Speedy measures them at the edge once this site has real traffic.', 'speedy-sensor' ) . '</p>';

			if ( $last > 0 ) {
				printf(
					'<p class="speedy-muted">%s</p>',
					esc_html(
						sprintf(
							/* translators: %s: human readable time difference. */
							__( 'Last checked %s ago.', 'speedy-sensor' ),
							human_time_diff( $last )
						)
					)
				);
			}

			echo '</div>';

			return;
		}

		echo '<div class="speedy-grid">';

		foreach ( array(
			'lcp'  => 'lcp_ms',
			'cls'  => 'cls',
			'inp'  => 'inp_ms',
			'ttfb' => 'ttfb_ms',
		) as $metric => $key ) {
			$grade = View::grade( $metric, $latest[ $key ] );
			$value = null === $latest[ $key ]
				? '—'
				: ( 'cls' === $metric ? number_format_i18n( (float) $latest[ $key ], 3 ) : number_format_i18n( (int) $latest[ $key ] ) );

			// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- View::tile() escapes every argument internally.
			echo View::tile(
				strtoupper( $metric ),
				$value,
				View::grade_label( $grade ),
				$grade
			);
			// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		echo '</div>';

		$points = array();

		foreach ( $series as $row ) {
			$points[] = array(
				'date'  => $row['recorded_on'],
				'value' => $row['lcp_ms'],
			);
		}

		$chart = View::sparkline(
			$points,
			__( 'Largest Contentful Paint over the last seven days', 'speedy-sensor' )
		);

		if ( '' !== $chart ) {
			echo '<h3 class="speedy-subhead">' . esc_html__( 'Largest Contentful Paint, last 7 days', 'speedy-sensor' ) . '</h3>';
			echo '<div class="speedy-chart speedy-chart--' . esc_attr( View::overall_grade( array( 'lcp' => $latest['lcp_ms'] ) ) ) . '">';
			echo $chart; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built and escaped in View::sparkline().
			echo '</div>';
		}

		echo '</div>';
	}

	/**
	 * Internal health summary from the last completed scan.
	 *
	 * @param array|null $scan    Latest completed scan.
	 * @param array      $metrics Database metrics for that scan.
	 * @return void
	 */
	private static function render_health( $scan, array $metrics ) {
		echo '<div class="speedy-card">';
		echo '<h2>' . esc_html__( 'Internal health', 'speedy-sensor' ) . '</h2>';

		if ( ! $scan ) {
			echo '<p>' . esc_html__( 'No scan has completed yet.', 'speedy-sensor' ) . '</p>';
			echo '</div>';

			return;
		}

		$orphaned = isset( $metrics['db_orphaned_transient_count'] ) ? (int) $metrics['db_orphaned_transient_count'] : 0;
		$issues   = (int) $scan['issues_found'];

		echo '<div class="speedy-grid">';

		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- View::tile() escapes every argument internally.
		echo View::tile(
			__( 'Database size', 'speedy-sensor' ),
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
			View::bytes( isset( $metrics['db_autoload_bytes'] ) ? $metrics['db_autoload_bytes'] : 0 ),
			__( 'Loaded on every request', 'speedy-sensor' ),
			( isset( $metrics['db_autoload_bytes'] ) && $metrics['db_autoload_bytes'] > 819200 ) ? 'poor' : ''
		);
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped

		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- View::tile() escapes every argument internally.
		echo View::tile(
			__( 'Plugins scanned', 'speedy-sensor' ),
			number_format_i18n( (int) $scan['plugins_total'] ),
			sprintf(
				/* translators: %s: scan date. */
				__( 'Scanned %s', 'speedy-sensor' ),
				date_i18n( get_option( 'date_format' ), strtotime( (string) $scan['started_at'] . ' UTC' ) )
			)
		);
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped

		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- View::tile() escapes every argument internally.
		echo View::tile(
			__( 'Issues found', 'speedy-sensor' ),
			number_format_i18n( $issues ),
			__( 'Across all plugins', 'speedy-sensor' ),
			$issues > 0 ? 'poor' : 'good'
		);
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped

		echo '</div>';

		if ( $orphaned > 0 ) {
			printf(
				'<p class="speedy-callout">%s</p>',
				esc_html(
					sprintf(
						/* translators: %s: number of orphaned transient rows. */
						__( '%s expired temporary records have no timeout row left. WordPress cannot clear these automatically, so they accumulate and slow every page load.', 'speedy-sensor' ),
						number_format_i18n( $orphaned )
					)
				)
			);
		}

		echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=' . Actions::PAGE_SCAN ) ) . '">' . esc_html__( 'See which plugins are responsible', 'speedy-sensor' ) . '</a></p>';
		echo '</div>';
	}

	/**
	 * The escalation path: hand the findings to Speedy.
	 *
	 * @param array|null $scan     Latest completed scan.
	 * @param Settings   $settings Settings store.
	 * @return void
	 */
	private static function render_actions( $scan, Settings $settings ) {
		echo '<div class="speedy-card">';
		echo '<h2>' . esc_html__( 'Need a hand?', 'speedy-sensor' ) . '</h2>';

		if ( ! $settings->get( 'service_requests_enabled', true ) ) {
			echo '<p>' . esc_html__( 'Service requests are switched off in settings.', 'speedy-sensor' ) . '</p>';
			echo '</div>';

			return;
		}

		if ( ! $scan ) {
			echo '<p>' . esc_html__( 'Run a scan first. Speedy needs the findings before it can help.', 'speedy-sensor' ) . '</p>';
			echo '</div>';

			return;
		}

		echo '<p>' . esc_html__( 'Send the latest scan to Speedy and a specialist will review this site and get back to you.', 'speedy-sensor' ) . '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="speedy-form">';
		wp_nonce_field( Actions::NONCE_REQUEST );
		echo '<input type="hidden" name="action" value="speedy_sensor_service_request" />';

		echo '<p><label for="speedy-sensor-note">' . esc_html__( 'Anything Speedy should know? (optional)', 'speedy-sensor' ) . '</label>';
		echo '<textarea id="speedy-sensor-note" name="note" rows="3" class="large-text" maxlength="1000"></textarea></p>';

		echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Request optimisation from Speedy', 'speedy-sensor' ) . '</button></p>';
		echo '</form>';

		printf(
			'<p class="speedy-muted">%s</p>',
			esc_html__( 'The request contains your WordPress and PHP versions plus the scan findings. It does not contain your API key, visitor data or any page content.', 'speedy-sensor' )
		);

		echo '</div>';
	}
}
