<?php
/**
 * Scan repository memoisation tests.
 *
 * Plan 0004 Step 3.1. get_latest() memoises per request, so these tests prove
 * both that the memo saves the second read and that every write path clears it.
 *
 * @package Speedy_Sensor
 */

namespace Speedy_Sensor\Tests;

use Speedy_Sensor\Db\Repositories\ScanRepository;
use Speedy_Sensor\Db\Schema;
use WP_UnitTestCase;

/**
 * Covers the request-scoped memo on ScanRepository.
 *
 * @covers \Speedy_Sensor\Db\Repositories\ScanRepository
 */
final class ScanRepositoryTest extends WP_UnitTestCase {

	/**
	 * Repository under test.
	 *
	 * @var ScanRepository
	 */
	private $repository;

	/**
	 * Sets up a repository with no scans present.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->truncate_scans();

		$this->repository = new ScanRepository();

		// The memo is static, so it survives between tests. An empty delete
		// still flushes it, which keeps each test independent of the last.
		$this->repository->delete_by_ids( array() );
	}

	/**
	 * Remove every scan row so memo assertions start from a known state.
	 *
	 * @return void
	 */
	public function tear_down() {
		$this->truncate_scans();

		parent::tear_down();
	}

	/**
	 * A second read of the same status must not issue another query.
	 *
	 * This is the regression guard for the saving itself: without it the memo
	 * could be deleted and the suite would still pass.
	 *
	 * @return void
	 */
	public function test_repeated_get_latest_for_one_status_issues_one_query() {
		global $wpdb;

		// Prime, then measure a second identical read in isolation.
		$this->repository->get_latest( ScanRepository::STATUS_COMPLETE );

		$before = $wpdb->num_queries;
		$second = $this->repository->get_latest( ScanRepository::STATUS_COMPLETE );
		$queries = $wpdb->num_queries - $before;

		$this->assertNull( $second );
		$this->assertSame( 0, $queries, 'A repeated get_latest() must be served from the memo.' );
	}

	/**
	 * A memoised null is a real answer and must not trigger a re-query.
	 *
	 * @return void
	 */
	public function test_memoised_null_does_not_requery() {
		global $wpdb;

		$this->assertNull( $this->repository->get_latest( ScanRepository::STATUS_FAILED ) );

		$before  = $wpdb->num_queries;
		$second  = $this->repository->get_latest( ScanRepository::STATUS_FAILED );
		$queries = $wpdb->num_queries - $before;

		$this->assertNull( $second );
		$this->assertSame( 0, $queries, 'A cached null must be returned without querying.' );
	}

	/**
	 * Different statuses are memoised separately.
	 *
	 * @return void
	 */
	public function test_each_status_is_memoised_independently() {
		$running = $this->repository->start();

		$resumable = $this->repository->get_resumable();
		$complete  = $this->repository->get_latest( ScanRepository::STATUS_COMPLETE );

		$this->assertIsArray( $resumable, 'The running scan must be returned for the running status.' );
		$this->assertSame( $running, (int) $resumable['id'] );
		$this->assertNull( $complete, 'A running scan must not appear under the complete status.' );
	}

	/**
	 * start() invalidates, so a scan opened later is visible immediately.
	 *
	 * @return void
	 */
	public function test_start_clears_the_memo() {
		$this->assertNull( $this->repository->get_latest( ScanRepository::STATUS_RUNNING ) );

		$this->repository->start();

		$this->assertIsArray(
			$this->repository->get_latest( ScanRepository::STATUS_RUNNING ),
			'A scan started after the first read must be visible.'
		);
	}

	/**
	 * complete() must not be masked by a memoised null for that status.
	 *
	 * @return void
	 */
	public function test_complete_clears_the_memo() {
		$id = $this->repository->start();

		// Prime the complete status with null before the write.
		$this->assertNull( $this->repository->get_latest( ScanRepository::STATUS_COMPLETE ) );

		$this->repository->complete( $id );

		$latest = $this->repository->get_latest( ScanRepository::STATUS_COMPLETE );

		$this->assertIsArray( $latest, 'complete() must invalidate the memoised null.' );
		$this->assertSame( $id, (int) $latest['id'] );
	}

	/**
	 * fail() must clear the memo for the failed status.
	 *
	 * @return void
	 */
	public function test_fail_clears_the_memo() {
		$id = $this->repository->start();

		$this->assertNull( $this->repository->get_latest( ScanRepository::STATUS_FAILED ) );

		$this->repository->fail( $id, 'exception' );

		$latest = $this->repository->get_latest( ScanRepository::STATUS_FAILED );

		$this->assertIsArray( $latest );
		$this->assertSame( $id, (int) $latest['id'] );
		$this->assertSame( 'exception', $latest['error_code'] );
	}

	/**
	 * delete_by_ids() must clear the memo, or a deleted scan lingers in memory.
	 *
	 * @return void
	 */
	public function test_delete_clears_the_memo() {
		$id = $this->repository->start();

		$this->assertIsArray( $this->repository->get_latest( ScanRepository::STATUS_RUNNING ) );

		$this->repository->delete_by_ids( array( $id ) );

		$this->assertNull(
			$this->repository->get_latest( ScanRepository::STATUS_RUNNING ),
			'A deleted scan must not survive in the memo.'
		);
	}

	/**
	 * An update that moves a row between statuses must clear both keys.
	 *
	 * update() is the subtle case: the row leaves the running key and arrives
	 * in the complete key, so clearing one would leave the other stale.
	 *
	 * @return void
	 */
	public function test_update_between_statuses_clears_every_key() {
		$id = $this->repository->start();

		$this->assertIsArray( $this->repository->get_latest( ScanRepository::STATUS_RUNNING ) );
		$this->assertNull( $this->repository->get_latest( ScanRepository::STATUS_COMPLETE ) );

		$this->repository->complete( $id );

		$this->assertNull(
			$this->repository->get_latest( ScanRepository::STATUS_RUNNING ),
			'The completed scan must not linger under the running status.'
		);
		$this->assertIsArray( $this->repository->get_latest( ScanRepository::STATUS_COMPLETE ) );
	}

	/**
	 * Deletes every scan row directly, bypassing the repository memo.
	 *
	 * @return void
	 */
	private function truncate_scans() {
		global $wpdb;

		$table = Schema::scans_table( $wpdb );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Table name comes from Schema, never from input.
		$wpdb->query( "DELETE FROM `{$table}`" );
	}
}