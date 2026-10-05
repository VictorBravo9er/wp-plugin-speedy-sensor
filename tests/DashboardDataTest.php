<?php
/**
 * Dashboard data-selection tests.
 *
 * Plan 0004 Step 3.2. The Overview derives its "latest" Web Vitals row from the
 * series it already fetched, and only asks the repository when the series is
 * empty, because an empty series means the newest row predates the window.
 *
 * @package Speedy_Sensor
 */

namespace Speedy_Sensor\Tests;

use Speedy_Sensor\Db\Repositories\WebVitalRepository;
use Speedy_Sensor\Db\Schema;
use WP_UnitTestCase;

/**
 * Covers the latest-row selection used by the Overview screen.
 *
 * @covers \Speedy_Sensor\Db\Repositories\WebVitalRepository
 */
final class DashboardDataTest extends WP_UnitTestCase {

	/**
	 * Cache repository under test.
	 *
	 * @var WebVitalRepository
	 */
	private $repository;

	/**
	 * Sets up an empty cache.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->repository = new WebVitalRepository();
		$this->truncate();
	}

	/**
	 * Clears the cache table between tests.
	 *
	 * @return void
	 */
	public function tear_down() {
		$this->truncate();

		parent::tear_down();
	}

	/**
	 * The series is ordered oldest first, so its last row is the latest entry.
	 *
	 * This is the property the Overview relies on to skip get_latest(). If the
	 * ordering changed, this test would fail and the optimisation would be
	 * silently returning the wrong row.
	 *
	 * @return void
	 */
	public function test_series_last_row_equals_latest() {
		$this->repository->upsert(
			array(
				array(
					'recorded_on' => gmdate( 'Y-m-d', time() - ( 3 * DAY_IN_SECONDS ) ),
					'field'       => 'mobile',
					'route'       => '/',
					'lcp_ms'      => 1000,
					'cls'         => 0.10,
					'inp_ms'      => 100,
					'ttfb_ms'     => 200,
				),
				array(
					'recorded_on' => gmdate( 'Y-m-d', time() - ( 1 * DAY_IN_SECONDS ) ),
					'field'       => 'mobile',
					'route'       => '/',
					'lcp_ms'      => 3000,
					'cls'         => 0.40,
					'inp_ms'      => 500,
					'ttfb_ms'     => 600,
				),
			)
		);

		$series = $this->repository->get_series( 7, 'mobile', '/' );
		$latest = $this->repository->get_latest( 'mobile', '/' );

		$this->assertCount( 2, $series );
		$this->assertSame( end( $series ), $latest, 'The final series row must equal get_latest().' );
		$this->assertSame( 3000, (int) $latest['lcp_ms'] );
	}

	/**
	 * With an empty series the repository must still be consulted.
	 *
	 * This is the guarded fallback: the newest row exists but predates the
	 * window, so deriving from the series would report no data at all.
	 *
	 * @return void
	 */
	public function test_latest_is_available_even_when_series_is_empty() {
		$this->repository->upsert(
			array(
				array(
					'recorded_on' => gmdate( 'Y-m-d', time() - ( 60 * DAY_IN_SECONDS ) ),
					'field'       => 'mobile',
					'route'       => '/',
					'lcp_ms'      => 2500,
					'cls'         => 0.20,
					'inp_ms'      => 300,
					'ttfb_ms'     => 400,
				),
			)
		);

		$series = $this->repository->get_series( 7, 'mobile', '/' );
		$latest = $this->repository->get_latest( 'mobile', '/' );

		$this->assertSame( array(), $series, 'A row this old must fall outside the seven day window.' );
		$this->assertIsArray( $latest, 'get_latest() must still find the older row.' );
		$this->assertSame( 2500, (int) $latest['lcp_ms'] );
	}

	/**
	 * A different field or route is a different series, not a fallback case.
	 *
	 * @return void
	 */
	public function test_series_is_scoped_to_field_and_route() {
		$this->repository->upsert(
			array(
				array(
					'recorded_on' => gmdate( 'Y-m-d' ),
					'field'       => 'mobile',
					'route'       => '/',
					'lcp_ms'      => 1100,
					'cls'         => 0.05,
					'inp_ms'      => 100,
					'ttfb_ms'     => 150,
				),
			)
		);

		$this->assertCount( 1, $this->repository->get_series( 7, 'mobile', '/' ) );
		$this->assertSame( array(), $this->repository->get_series( 7, 'desktop', '/' ) );
		$this->assertSame( array(), $this->repository->get_series( 7, 'mobile', '/other' ) );
	}

	/**
	 * Deletes every cached Web Vitals row.
	 *
	 * @return void
	 */
	private function truncate() {
		global $wpdb;

		$table = Schema::web_vitals_table( $wpdb );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Table name comes from Schema, never from input.
		$wpdb->query( "DELETE FROM `{$table}`" );
	}
}