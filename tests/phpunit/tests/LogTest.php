<?php
/**
 * Tests for SwiftForms\Log.
 *
 * @package SwiftForms
 */

require_once dirname( __DIR__ ) . '/includes/class-sf-storage-test-case.php';

use SwiftForms\Clock;
use SwiftForms\Database;
use SwiftForms\Log;
use SwiftForms\Settings;

/**
 * Class LogTest
 *
 * @group log
 */
class LogTest extends SF_Storage_Test_Case {

	/**
	 * Rows go to the log table with JSON context and the clock's time.
	 *
	 * @return void
	 */
	public function test_add_inserts_a_row() {
		$this->use_scratch_prefix();
		Database::install();
		Clock::freeze( 1795082400 );

		$id = Log::add(
			'mail',
			'warning',
			'Mail failed.',
			[
				'form'    => 'abc',
				'attempt' => 2,
			],
			'form-abc'
		);

		$this->assertIsInt( $id );
		$rows = $this->rows( 'log' );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'mail', $rows[0]['type'] );
		$this->assertSame( 'warning', $rows[0]['level'] );
		$this->assertSame( 'Mail failed.', $rows[0]['message'] );
		$this->assertSame( 'form-abc', $rows[0]['ref'] );
		$this->assertSame( '{"form":"abc","attempt":2}', $rows[0]['context'] );
		$this->assertSame( '2026-11-19 10:00:00', $rows[0]['created_gmt'] );
	}

	/**
	 * Unknown types are refused and unknown levels become "info".
	 *
	 * @return void
	 */
	public function test_type_and_level_are_checked() {
		$this->use_scratch_prefix();
		Database::install();

		$this->assertFalse( Log::add( 'entry', 'info', 'Not a log type.' ) );
		$this->assertIsInt( Log::add( 'error', 'loud', 'Odd level.' ) );

		$rows = $this->rows( 'log' );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'info', $rows[0]['level'] );
	}

	/**
	 * Debug rows are written only while advanced.debug_log is on.
	 *
	 * @return void
	 */
	public function test_debug_rows_need_the_debug_setting() {
		$this->use_scratch_prefix();
		Database::install();
		delete_option( 'swiftforms_advanced_debug_log' );
		Settings::flush_cache();

		$this->assertFalse( Log::add( 'error', 'debug', 'Hidden.' ) );
		$this->assertCount( 0, $this->rows( 'log' ) );

		update_option( 'swiftforms_advanced_debug_log', '1' );
		$this->assertIsInt( Log::add( 'error', 'debug', 'Shown.' ) );
		$this->assertCount( 1, $this->rows( 'log' ) );
	}

	/**
	 * Rows older than 30 days go; a row exactly 30 days old stays.
	 *
	 * @return void
	 */
	public function test_prune_at_exactly_thirty_days() {
		global $wpdb;

		$this->use_scratch_prefix();
		Database::install();
		$now = 1795082400;
		Clock::freeze( $now );

		foreach ( [
			'older'   => $now - 30 * DAY_IN_SECONDS - 1,
			'exact'   => $now - 30 * DAY_IN_SECONDS,
			'younger' => $now - 29 * DAY_IN_SECONDS,
		] as $ref => $time ) {
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				Database::table( 'log' ),
				[
					'type'        => 'error',
					'ref'         => $ref,
					'level'       => 'info',
					'message'     => $ref,
					'context'     => '{}',
					'created_gmt' => gmdate( 'Y-m-d H:i:s', $time ),
				]
			);
		}

		$this->assertSame( 1, Log::prune() );
		$this->assertSame( [ 'exact', 'younger' ], wp_list_pluck( $this->rows( 'log' ), 'ref' ) );
	}

	/**
	 * Without the table nothing fatals and nothing is printed.
	 *
	 * @return void
	 */
	public function test_safe_without_the_table() {
		$this->use_scratch_prefix();

		$this->assertFalse( Log::add( 'migration', 'info', 'No table yet.' ) );
		$this->assertSame( 0, Log::prune() );
	}
}
