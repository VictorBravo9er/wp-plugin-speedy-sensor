<?php
/**
 * Attribution tests.
 *
 * @package Speedy_Sensor
 */

namespace Speedy_Sensor\Tests;

use Speedy_Sensor\Scanner\Attribution;
use WP_UnitTestCase;

/**
 * @covers \Speedy_Sensor\Scanner\Attribution
 */
final class AttributionTest extends WP_UnitTestCase {

	/**
	 * Attribution under test.
	 *
	 * @var Attribution
	 */
	private $attribution;

	/**
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->attribution = new Attribution();
	}

	/**
	 * A plugin that costs nothing scores nothing.
	 *
	 * @return void
	 */
	public function test_empty_measurement_scores_zero() {
		$measurement = array(
			'autoload_bytes'   => 0,
			'options_count'    => 0,
			'transients_count' => 0,
		);

		$this->assertSame( 0, $this->attribution->score( $measurement, 0 ) );
	}

	/**
	 * Autoloaded data is weighted harder than table size.
	 *
	 * @return void
	 */
	public function test_autoload_outweighs_tables_of_the_same_size() {
		$measurement = array(
			'autoload_bytes'   => 250000,
			'options_count'    => 0,
			'transients_count' => 0,
		);

		$from_autoload = $this->attribution->score( $measurement, 0 );
		$from_tables   = $this->attribution->score( $measurement, 250000 );

		$this->assertGreaterThan( $from_tables, $from_autoload );
	}

	/**
	 * The score cannot run away.
	 *
	 * @return void
	 */
	public function test_score_is_capped_at_one_hundred() {
		$measurement = array(
			'autoload_bytes'   => 50000000,
			'options_count'    => 100000,
			'transients_count' => 100000,
		);

		$this->assertSame( 100, $this->attribution->score( $measurement, 50000000 ) );
	}

	/**
	 * Core tables are never claimed by a plugin.
	 *
	 * @return void
	 */
	public function test_core_tables_are_never_attributed() {
		$tables = array(
			'wp_posts'        => array(
				'bytes' => 100,
				'rows'  => 10,
			),
			'wp_postmeta'     => array(
				'bytes' => 100,
				'rows'  => 10,
			),
			'wp_acme_widgets' => array(
				'bytes' => 500,
				'rows'  => 50,
			),
		);

		$owned = $this->attribution->owned_tables( 'acme', $tables );

		$this->assertArrayHasKey( 'wp_acme_widgets', $owned );
		$this->assertArrayNotHasKey( 'wp_posts', $owned );
		$this->assertArrayNotHasKey( 'wp_postmeta', $owned );
	}

	/**
	 * A short slug would match too much, so it is refused outright.
	 *
	 * @return void
	 */
	public function test_short_slugs_are_refused() {
		$tables = array(
			'wp_posts' => array(
				'bytes' => 100,
				'rows'  => 10,
			),
			'wp_db_backup_log' => array(
				'bytes' => 100,
				'rows'  => 10,
			),
		);

		$this->assertSame( array(), $this->attribution->owned_tables( 'wp', $tables ) );
		$this->assertSame( array(), $this->attribution->owned_tables( 'db', $tables ) );
	}

	/**
	 * An inactive plugin is reported even though it costs nothing.
	 *
	 * @return void
	 */
	public function test_inactive_plugin_is_flagged() {
		$measurement = array(
			'autoload_bytes'   => 0,
			'options_count'    => 0,
			'transients_count' => 0,
		);

		$findings = $this->attribution->findings(
			array(
				'is_active'   => 0,
				'is_must_use' => 0,
			),
			$measurement,
			0
		);

		$codes = wp_list_pluck( $findings, 'code' );

		$this->assertContains( 'inactive_plugin', $codes );
		$this->assertNotContains( 'high_autoload', $codes );
	}

	/**
	 * Only the three serious findings count towards the issue total.
	 *
	 * @return void
	 */
	public function test_issue_counting_ignores_informational_findings() {
		$rows = array(
			array(
				'findings' => wp_json_encode(
					array(
						array(
							'code'  => 'high_autoload',
							'value' => 900000,
						),
						array(
							'code'  => 'inactive_plugin',
							'value' => 0,
						),
					)
				),
			),
			array(
				'findings' => wp_json_encode(
					array(
						array(
							'code'  => 'large_tables',
							'value' => 10,
						),
					)
				),
			),
		);

		$this->assertSame( 2, Attribution::count_issues( $rows ) );
	}

	/**
	 * Malformed stored findings must not fatal.
	 *
	 * @return void
	 */
	public function test_count_issues_tolerates_bad_data() {
		$this->assertSame( 0, Attribution::count_issues( array( array( 'findings' => 'not json' ) ) ) );
		$this->assertSame( 0, Attribution::count_issues( array( array() ) ) );
	}

	/**
	 * The row shape the repository expects is produced.
	 *
	 * @return void
	 */
	public function test_attribute_builds_a_complete_row() {
		$entry = array(
			'plugin_file' => 'acme/acme.php',
			'plugin_name' => 'Acme',
			'version'     => '1.2.3',
			'slug'        => 'acme',
			'is_active'   => 1,
			'is_must_use' => 0,
		);

		$measurement = array(
			'autoload_bytes'   => 1024,
			'options_count'    => 12,
			'transients_count' => 3,
			'total_bytes'      => 2048,
		);

		$tables = array(
			'wp_acme_items' => array(
				'bytes' => 4096,
				'rows'  => 99,
			),
		);

		$row = $this->attribution->attribute( $entry, $measurement, $tables );

		$this->assertSame( 'acme/acme.php', $row['plugin_file'] );
		$this->assertSame( 4096, $row['table_bytes'] );
		$this->assertSame( 99, $row['table_rows'] );
		$this->assertGreaterThan( 0, $row['score'] );
		$this->assertIsString( $row['findings'] );
		$this->assertIsArray( json_decode( $row['findings'], true ) );
	}
}