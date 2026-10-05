<?php
/**
 * Database scanner inventory tests.
 *
 * Plan 0004 Step 3.3. metrics() accepts an inventory the scan pass already read,
 * so a completing scan reads information_schema once instead of twice. The
 * no-argument call must still work, and a supplied inventory must be used as-is.
 *
 * @package Speedy_Sensor
 */

namespace Speedy_Sensor\Tests;

use Speedy_Sensor\Scanner\DatabaseScanner;
use WP_UnitTestCase;

/**
 * Covers the optional inventory argument on DatabaseScanner::metrics().
 *
 * @covers \Speedy_Sensor\Scanner\DatabaseScanner
 */
final class DatabaseScannerTest extends WP_UnitTestCase {

	/**
	 * Scanner under test.
	 *
	 * @var DatabaseScanner
	 */
	private $scanner;

	/**
	 * Sets up the scanner.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->scanner = new DatabaseScanner();
	}

	/**
	 * Calling with no argument must still work and return the full metric set.
	 *
	 * Guards the back-compatibility of the new optional parameter.
	 *
	 * @return void
	 */
	public function test_metrics_without_argument_still_reads_its_own_inventory() {
		$metrics = $this->scanner->metrics();

		$this->assertIsArray( $metrics );
		$this->assertArrayHasKey( 'db_total_bytes', $metrics );
		$this->assertArrayHasKey( 'db_table_count', $metrics );
		$this->assertArrayHasKey( 'db_autoload_bytes', $metrics );
		$this->assertArrayHasKey( 'db_largest_table_bytes', $metrics );
		$this->assertGreaterThan( 0, (int) $metrics['db_table_count'] );
	}

	/**
	 * A supplied inventory must drive the totals rather than a fresh read.
	 *
	 * A synthetic inventory is passed in, so if the implementation ignored the
	 * argument the reported totals would reflect the real database instead and
	 * this assertion would fail.
	 *
	 * @return void
	 */
	public function test_supplied_inventory_drives_the_totals() {
		$inventory = array(
			'speedy_fake_one' => array(
				'bytes' => 1000,
				'rows'  => 10,
			),
			'speedy_fake_two' => array(
				'bytes' => 500,
				'rows'  => 5,
			),
		);

		$metrics = $this->scanner->metrics( $inventory );

		$this->assertSame( 1500, (int) $metrics['db_total_bytes'], 'Total bytes must come from the supplied inventory.' );
		$this->assertSame( 2, (int) $metrics['db_table_count'], 'Table count must come from the supplied inventory.' );
		$this->assertSame( 1000, (int) $metrics['db_largest_table_bytes'] );
		$this->assertSame( 10, (int) $metrics['db_largest_table_rows'] );
	}

	/**
	 * An empty inventory is honoured, not treated as missing.
	 *
	 * The guard is is_array(), so an empty array must pass straight through and
	 * report a zero total rather than triggering a read.
	 *
	 * @return void
	 */
	public function test_empty_inventory_is_honoured() {
		$metrics = $this->scanner->metrics( array() );

		$this->assertSame( 0, (int) $metrics['db_total_bytes'] );
		$this->assertSame( 0, (int) $metrics['db_table_count'] );
	}

	/**
	 * A non-array argument falls back to reading the inventory.
	 *
	 * @return void
	 */
	public function test_non_array_inventory_falls_back_to_reading() {
		$metrics = $this->scanner->metrics( 'not-an-array' );

		$this->assertGreaterThan( 0, (int) $metrics['db_table_count'] );
	}
}