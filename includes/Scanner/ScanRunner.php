<?php
/**
 * Scan orchestration.
 *
 * A scan is resumable rather than atomic. On a site with hundreds of plugins the
 * per-plugin measurement cannot finish inside one cron tick without pushing the
 * request past its budget, so each pass works until its millisecond budget is
 * spent, writes what it has, records a cursor, and queues itself again.
 *
 * The frontend never triggers this. It runs from cron only.
 *
 * @package Speedy_Sensor
 */

namespace Speedy_Sensor\Scanner;

use Speedy_Sensor\Db\Repositories\DbMetricRepository;
use Speedy_Sensor\Db\Repositories\PluginMetricRepository;
use Speedy_Sensor\Db\Repositories\ScanRepository;
use Speedy_Sensor\Db\Repositories\WebVitalRepository;
use Speedy_Sensor\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Drives one budgeted pass of a scan.
 */
final class ScanRunner {

	/**
	 * Milliseconds one pass may spend measuring plugins.
	 *
	 * Deliberately far below a typical PHP time limit so the pass finishes and
	 * commits its cursor before the request is killed.
	 */
	const BUDGET_MS = 2000;

	/**
	 * Plugins measured before the cursor is written back.
	 */
	const BATCH_SIZE = 25;

	/**
	 * Days of Web Vitals history to keep.
	 */
	const VITALS_RETENTION_DAYS = 90;

	/**
	 * Settings store.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Scan run repository.
	 *
	 * @var ScanRepository
	 */
	private $scans;

	/**
	 * Plugin metric repository.
	 *
	 * @var PluginMetricRepository
	 */
	private $plugin_metrics;

	/**
	 * Database metric repository.
	 *
	 * @var DbMetricRepository
	 */
	private $db_metrics;

	/**
	 * Constructor.
	 *
	 * @param Settings|null $settings Settings store.
	 */
	public function __construct( $settings = null ) {
		$this->settings       = $settings instanceof Settings ? $settings : new Settings();
		$this->scans          = new ScanRepository();
		$this->plugin_metrics = new PluginMetricRepository();
		$this->db_metrics     = new DbMetricRepository();
	}

	/**
	 * Runs one budgeted pass.
	 *
	 * @return array Status describing what happened.
	 */
	public function run() {
		if ( ! $this->settings->get( 'scan_enabled', true ) ) {
			return array(
				'status' => 'disabled',
			);
		}

		// Another pass is already working. Exit rather than contend for rows.
		if ( ! ScanLock::acquire() ) {
			return array( 'status' => 'locked' );
		}

		$scan_id = 0;

		try {
			$scan     = $this->open_scan();
			$scan_id  = (int) $scan['id'];
			$progress = $this->measure_plugins( $scan );

			if ( empty( $progress['complete'] ) ) {
				Cron::schedule_resume();

				return array(
					'status'  => 'partial',
					'scan_id' => $scan_id,
					'cursor'  => (int) $progress['cursor'],
					'total'   => (int) $progress['total'],
				);
			}

			// Only the completing pass forwards its inventory. A scan that
			// resumes on a later cron tick must re-read table sizes rather
			// than attribute plugin tables against figures captured before
			// the gap, so a partial pass never carries one forward.
			$this->finish( $scan, $progress['tables'] );

			return array(
				'status'  => 'complete',
				'scan_id' => $scan_id,
				'total'   => (int) $progress['total'],
			);
		} catch ( \Throwable $error ) {
			if ( $scan_id > 0 ) {
				$this->scans->fail( $scan_id, 'exception' );
			}

			// Surfaced in the admin notice, never as a PHP notice on the frontend.
			return array(
				'status' => 'failed',
				'error'  => $error->getMessage(),
			);
		} finally {
			ScanLock::release();
		}
	}

	/**
	 * Returns a run to continue, or opens a new one.
	 *
	 * @return array Scan row.
	 */
	private function open_scan() {
		$resumable = $this->scans->get_resumable();

		if ( $resumable ) {
			return $resumable;
		}

		$id = $this->scans->start();

		return $this->scans->get( $id );
	}

	/**
	 * Measures plugins until the budget is spent or the list is exhausted.
	 *
	 * The table inventory it reads is returned alongside the progress figures so
	 * the completing pass can hand it to DatabaseScanner::metrics() instead of
	 * reading information_schema a second time.
	 *
	 * @param array $scan Scan row.
	 * @return array Progress with complete, cursor, total and tables.
	 */
	private function measure_plugins( array $scan ) {
		$deadline    = microtime( true ) + ( self::BUDGET_MS / 1000 );
		$scanner     = new PluginScanner();
		$database    = new DatabaseScanner();
		$attribution = new Attribution();

		$inventory = $scanner->inventory();
		$total     = count( $inventory );
		$tables    = $database->table_inventory();

		$cursor = (int) $scan['cursor_index'];

		$this->scans->update( (int) $scan['id'], array( 'plugins_total' => $total ) );

		while ( $cursor < $total ) {
			if ( microtime( true ) >= $deadline ) {
				break;
			}

			$end   = min( $cursor + self::BATCH_SIZE, $total );
			$chunk = array();

			for ( $index = $cursor; $index < $end; $index++ ) {
				$entry = $inventory[ $index ];

				$chunk[] = $attribution->attribute(
					$entry,
					$scanner->measure( $entry['slug'] ),
					$tables
				);
			}

			$this->plugin_metrics->insert_many( (int) $scan['id'], $chunk );

			$cursor = $end;

			$this->scans->update(
				(int) $scan['id'],
				array(
					'cursor_index'    => $cursor,
					'plugins_scanned' => $cursor,
				)
			);
		}

		return array(
			'complete' => $cursor >= $total,
			'cursor'   => $cursor,
			'total'    => $total,
			'tables'   => $tables,
		);
	}

	/**
	 * Closes a completed run and prunes history.
	 *
	 * @param array      $scan      Scan row.
	 * @param array|null $inventory Table inventory already read by the completing
	 *                             pass, or null to read it here.
	 * @return void
	 */
	private function finish( array $scan, $inventory = null ) {
		$scan_id = (int) $scan['id'];
		$metrics = ( new DatabaseScanner() )->metrics( $inventory );

		$this->db_metrics->replace_for_scan( $scan_id, $metrics );

		$rows   = $this->plugin_metrics->get_by_scan( $scan_id, 1000, 0 );
		$issues = Attribution::count_issues( $rows );

		$this->scans->complete(
			$scan_id,
			array(
				'duration_ms'    => $this->elapsed_ms( $scan ),
				'tables_scanned' => (int) $metrics['db_table_count'],
				'issues_found'   => $issues,
			)
		);

		$this->prune_history();
		( new WebVitalRepository() )->delete_older_than( self::VITALS_RETENTION_DAYS );
	}

	/**
	 * Milliseconds since the run opened, across every resumed pass.
	 *
	 * @param array $scan Scan row.
	 * @return int
	 */
	private function elapsed_ms( array $scan ) {
		$started = isset( $scan['started_at'] ) ? strtotime( $scan['started_at'] . ' UTC' ) : false;

		if ( ! $started ) {
			return 0;
		}

		return max( 0, ( time() - $started ) * 1000 );
	}

	/**
	 * Drops scan runs beyond the retention setting and their metric rows.
	 *
	 * @return void
	 */
	private function prune_history() {
		$keep = (int) $this->settings->get( 'retain_scans', 12 );
		$ids  = $this->scans->get_ids_beyond( $keep );

		if ( empty( $ids ) ) {
			return;
		}

		$this->plugin_metrics->delete_for_scans( $ids );
		$this->db_metrics->delete_for_scans( $ids );
		$this->scans->delete_by_ids( $ids );
	}
}
