<?php
/**
 * Web Vitals pipeline and view grading tests.
 *
 * @package Speedy_Sensor
 */

namespace Speedy_Sensor\Tests;

use Speedy_Sensor\Admin\View;
use Speedy_Sensor\Integration\WebVitalsService;
use Speedy_Sensor\Settings\Settings;
use WP_UnitTestCase;

/**
 * @covers \Speedy_Sensor\Integration\WebVitalsService
 * @covers \Speedy_Sensor\Admin\View
 */
final class WebVitalsTest extends WP_UnitTestCase {

	/**
	 * Service under test.
	 *
	 * @var WebVitalsService
	 */
	private $service;

	/**
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->service = new WebVitalsService( new Settings() );
	}

	/**
	 * A data envelope is unwrapped.
	 *
	 * @return void
	 */
	public function test_normalise_unwraps_a_data_envelope() {
		$rows = $this->service->normalise(
			array(
				'data' => array(
					array(
						'date'    => '2026-04-09',
						'field'   => 'mobile',
						'route'   => '/',
						'lcp_ms'  => 2400,
						'cls'     => 0.12,
						'inp_ms'  => 180,
						'ttfb_ms' => 450,
					),
				),
			)
		);

		$this->assertCount( 1, $rows );
		$this->assertSame( '2026-04-09', $rows[0]['recorded_on'] );
		$this->assertSame( 2400, $rows[0]['lcp_ms'] );
	}

	/**
	 * A bare list, and short field names, are both accepted.
	 *
	 * @return void
	 */
	public function test_normalise_accepts_short_field_names() {
		$rows = $this->service->normalise(
			array(
				array(
					'date'  => '2026-04-09',
					'field' => 'desktop',
					'route' => '/pricing',
					'lcp'   => 3100,
					'cls'   => 0.31,
					'inp'   => 520,
					'ttfb'  => 1900,
				),
			)
		);

		$this->assertCount( 1, $rows );
		$this->assertSame( 3100, $rows[0]['lcp_ms'] );
		$this->assertSame( 520, $rows[0]['inp_ms'] );
		$this->assertSame( 1900, $rows[0]['ttfb_ms'] );
	}

	/**
	 * A row without a usable date cannot be bucketed and is dropped.
	 *
	 * @return void
	 */
	public function test_normalise_drops_rows_without_a_valid_date() {
		$rows = $this->service->normalise(
			array(
				'data' => array(
					array(
						'lcp_ms' => 1000,
					),
					array(
						'date'   => 'yesterday',
						'lcp_ms' => 1000,
					),
					array(
						'date'   => '2026-02-30',
						'lcp_ms' => 1000,
					),
					array(
						'date'   => '2026-04-10',
						'lcp_ms' => 2000,
					),
				),
			)
		);

		// Only the single well-formed, dated row survives.
		$this->assertCount( 1, $rows );
		$this->assertSame( '2026-04-10', $rows[0]['recorded_on'] );
	}

	/**
	 * A payload the plugin cannot understand yields nothing, not an error.
	 *
	 * @return void
	 */
	public function test_normalise_tolerates_an_unknown_shape() {
		$this->assertSame( array(), $this->service->normalise( array( 'unexpected' => true ) ) );
		$this->assertSame( array(), $this->service->normalise( array() ) );
	}

	/**
	 * Refreshing without credentials fails before any network call.
	 *
	 * @return void
	 */
	public function test_refresh_without_credentials_returns_an_error() {
		( new Settings() )->delete();

		$result = ( new WebVitalsService( new Settings() ) )->refresh();

		$this->assertWPError( $result );
		$this->assertSame( 'speedy_sensor_not_linked', $result->get_error_code() );
	}

	/**
	 * Metrics are graded against the published Core Web Vitals thresholds.
	 *
	 * @dataProvider provide_grades
	 *
	 * @param string $metric   Metric key.
	 * @param float  $value    Measured value.
	 * @param string $expected Expected grade.
	 * @return void
	 */
	public function test_grades( $metric, $value, $expected ) {
		$this->assertSame( $expected, View::grade( $metric, $value ) );
	}

	/**
	 * Grade cases covering each threshold.
	 *
	 * @return array
	 */
	public function provide_grades() {
		return array(
			'lcp fast'          => array( 'lcp', 1200, 'good' ),
			'lcp slow'          => array( 'lcp', 3000, 'needs-improvement' ),
			'lcp very slow'     => array( 'lcp', 5000, 'poor' ),
			'cls small'         => array( 'cls', 0.05, 'good' ),
			'cls large'         => array( 'cls', 0.4, 'poor' ),
			'inp fast'          => array( 'inp', 100, 'good' ),
			'inp slow'          => array( 'inp', 600, 'poor' ),
			'ttfb fast'         => array( 'ttfb', 300, 'good' ),
			'missing value'     => array( 'lcp', null, '' ),
			'unknown metric'    => array( 'nope', 1, '' ),
		);
	}

	/**
	 * The reported verdict is the worst of the measured metrics.
	 *
	 * @return void
	 */
	public function test_overall_grade_reports_the_worst_metric() {
		$this->assertSame(
			'poor',
			View::overall_grade(
				array(
					'lcp' => 1000,
					'cls' => 0.05,
					'inp' => 900,
				)
			)
		);
	}

	/**
	 * A series with fewer than two points cannot be charted.
	 *
	 * @return void
	 */
	public function test_sparkline_needs_at_least_two_points() {
		$this->assertSame( '', View::sparkline( array(), 'empty' ) );

		$this->assertSame(
			'',
			View::sparkline(
				array(
					array(
						'date'  => '2026-04-09',
						'value' => 1000,
					),
				),
				'single point'
			)
		);
	}

	/**
	 * Gaps are skipped rather than plotted as zero.
	 *
	 * @return void
	 */
	public function test_sparkline_skips_gaps() {
		$svg = View::sparkline(
			array(
				array(
					'date'  => '2026-04-08',
					'value' => 1000,
				),
				array(
					'date'  => '2026-04-09',
					'value' => null,
				),
				array(
					'date'  => '2026-04-10',
					'value' => 3000,
				),
			),
			'gapped series'
		);

		$this->assertStringContainsString( '<svg', $svg );
		$this->assertStringContainsString( '<polyline', $svg );
		$this->assertStringContainsString( 'points="0,56 640,0"', $svg );
	}
}