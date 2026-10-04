<?php
/**
 * Scan and plugins screen.
 *
 * The attribution table: every plugin the site has, what it costs the
 * database, and why it scored what it scored.
 *
 * @package Speedy_Sensor
 */

namespace Speedy_Sensor\Admin\Pages;

use Speedy_Sensor\Admin\Actions;
use Speedy_Sensor\Admin\View;
use Speedy_Sensor\Db\Repositories\PluginMetricRepository;
use Speedy_Sensor\Db\Repositories\ScanRepository;
use Speedy_Sensor\Scanner\ScanLock;
use Speedy_Sensor\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the scan screen.
 */
final class Scan {

	/**
	 * Rows per page.
	 */
	const PER_PAGE = 50;

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

		$scans  = new ScanRepository();
		$latest = $scans->get_latest( ScanRepository::STATUS_COMPLETE );
		$page   = self::current_page();

		echo '<div class="wrap speedy-wrap">';
		echo '<h1>' . esc_html__( 'Scan & Plugins', 'speedy-sensor' ) . '</h1>';

		self::render_toolbar();

		if ( ! $latest ) {
			echo '<p>' . esc_html__( 'No scan results yet. Use the button above to run one now.', 'speedy-sensor' ) . '</p>';
			echo '</div>';

			return;
		}

		$repository = new PluginMetricRepository();
		$total      = $repository->count_by_scan( (int) $latest['id'] );
		$rows       = $repository->get_by_scan( (int) $latest['id'], self::PER_PAGE, ( $page - 1 ) * self::PER_PAGE );

		echo '<p class="speedy-muted">';
		printf(
			esc_html(
				/* translators: 1: plugins scanned, 2: issues found. */
				__( '%1$d plugins scanned, %2$d issues found. Last run %3$s.', 'speedy-sensor' )
			),
			(int) $latest['plugins_total'],
			(int) $latest['issues_found'],
			esc_html( human_time_diff( strtotime( (string) $latest['started_at'] . ' UTC' ) ) )
		);
		echo '</p>';

		self::render_table( $rows );
		self::render_pagination( $page, $total );

		echo '</div>';
	}

	/**
	 * Run scan button and in-progress state.
	 *
	 * @return void
	 */
	private static function render_toolbar() {
		$locked = ScanLock::is_locked();

		echo '<div class="speedy-toolbar">';

		if ( $locked ) {
			echo '<p class="speedy-callout">';
			esc_html_e( 'A scan is running right now. Running another one would just contend for the same rows.', 'speedy-sensor' );
			echo '</p>';

			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( Actions::NONCE_CLEARLOCK );
			echo '<input type="hidden" name="action" value="speedy_sensor_clear_lock" />';
			echo '<button type="submit" class="button">' . esc_html__( 'Release lock', 'speedy-sensor' ) . '</button>';
			echo '</form>';
		} else {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( Actions::NONCE_SCAN );
			echo '<input type="hidden" name="action" value="speedy_sensor_run_scan" />';
			echo '<button type="submit" class="button button-primary">' . esc_html__( 'Run scan now', 'speedy-sensor' ) . '</button>';
			echo '</form>';
		}

		echo '</div>';
	}

	/**
	 * The attribution table.
	 *
	 * @param array $rows Metric rows.
	 * @return void
	 */
	private static function render_table( array $rows ) {
		if ( empty( $rows ) ) {
			echo '<p>' . esc_html__( 'This scan recorded no plugin rows.', 'speedy-sensor' ) . '</p>';

			return;
		}

		echo '<table class="widefat striped speedy-table">';
		echo '<thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Plugin', 'speedy-sensor' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'State', 'speedy-sensor' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Autoload', 'speedy-sensor' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Options', 'speedy-sensor' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Temp records', 'speedy-sensor' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Tables', 'speedy-sensor' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Score', 'speedy-sensor' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Why', 'speedy-sensor' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			$name = '' !== $row['plugin_name'] ? $row['plugin_name'] : $row['plugin_file'];

			echo '<tr>';
			echo '<td><strong>' . esc_html( $name ) . '</strong><br /><span class="speedy-muted">' . esc_html( $row['plugin_file'] ) . '</span></td>';

			echo '<td>' . esc_html( self::state_label( $row ) ) . '</td>';
			echo '<td>' . esc_html( View::bytes( $row['autoload_bytes'] ) ) . '</td>';
			echo '<td>' . esc_html( number_format_i18n( $row['options_count'] ) ) . '</td>';
			echo '<td>' . esc_html( number_format_i18n( $row['transients_count'] ) ) . '</td>';
			echo '<td>' . esc_html( View::bytes( $row['table_bytes'] ) ) . '</td>';
			echo '<td><span class="speedy-score speedy-score--' . esc_attr( self::score_tone( $row['score'] ) ) . '">' . esc_html( number_format_i18n( $row['score'] ) ) . '</span></td>';

			echo '<td><ul class="speedy-findings">';

			foreach ( $row['findings'] as $finding ) {
				$label = View::finding_label( $finding['code'], isset( $finding['value'] ) ? $finding['value'] : 0 );

				if ( '' === $label ) {
					continue;
				}

				echo '<li>' . esc_html( $label ) . '</li>';
			}

			echo '</ul></td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * State label for a plugin row.
	 *
	 * @param array $row Metric row.
	 * @return string
	 */
	private static function state_label( array $row ) {
		if ( ! empty( $row['is_must_use'] ) ) {
			return __( 'Must use', 'speedy-sensor' );
		}

		return ! empty( $row['is_active'] ) ? __( 'Active', 'speedy-sensor' ) : __( 'Inactive', 'speedy-sensor' );
	}

	/**
	 * Maps a score onto a CSS tone.
	 *
	 * @param int $score Zero to one hundred.
	 * @return string
	 */
	private static function score_tone( $score ) {
		$score = (int) $score;

		if ( $score >= 50 ) {
			return 'poor';
		}

		if ( $score >= 20 ) {
			return 'needs-improvement';
		}

		return 'good';
	}

	/**
	 * Reads the requested page from the query string.
	 *
	 * @return int
	 */
	private static function current_page() {
		if ( ! isset( $_GET['paged'] ) ) {
			return 1;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$paged = absint( wp_unslash( $_GET['paged'] ) );

		return max( 1, $paged );
	}

	/**
	 * Renders pagination links.
	 *
	 * @param int $page  Current page.
	 * @param int $total Total rows.
	 * @return void
	 */
	private static function render_pagination( $page, $total ) {
		$pages = (int) ceil( $total / self::PER_PAGE );

		if ( $pages < 2 ) {
			return;
		}

		$base = add_query_arg(
			array(
				'page' => Actions::PAGE_SCAN,
				'paged' => '%#%',
			),
			admin_url( 'admin.php' )
		);

		echo '<div class="tablenav"><div class="tablenav-pages">';
		echo wp_kses_post(
			paginate_links(
				array(
					'base'      => $base,
					'format'    => '',
					'current'   => $page,
					'total'     => $pages,
					'prev_text' => '&laquo;',
					'next_text' => '&raquo;',
				)
			)
		);
		echo '</div></div>';
	}
}