<?php // phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- The core privacy hooks and the plugin's own cron hook constant.
/**
 * Tests for the entry retention task.
 *
 * @package SwiftForms
 */

require_once dirname( __DIR__ ) . '/includes/class-sf-admin-test-case.php';

use SwiftForms\Clock;
use SwiftForms\Cron;
use SwiftForms\Database;
use SwiftForms\Entries_Repository;
use SwiftForms\Retention;

/**
 * Class RetentionTest
 *
 * @group admin
 * @group privacy
 */
class RetentionTest extends SF_Admin_Test_Case {

	const NOW = 1900000000;

	/**
	 * Freeze the clock.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		Clock::freeze( self::NOW );
	}

	/**
	 * Entry IDs still stored.
	 *
	 * @return int[]
	 */
	private function remaining() {
		global $wpdb;
		return array_map( 'intval', $wpdb->get_col( 'SELECT id FROM ' . Database::table( 'entries' ) . ' ORDER BY id' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Test read.
	}

	/**
	 * Forty daily entries with a 30 day retention: exactly the ten oldest go, the boundary entry stays.
	 *
	 * @return void
	 */
	public function test_boundary_with_daily_entries() {
		$this->set_settings( [ 'general' => [ 'retention_days' => 30 ] ] );
		$ids = [];
		for ( $day = 40; $day >= 1; $day-- ) {
			$ids[ $day ] = $this->add_entry( [], [ 'created_gmt' => $this->days_ago( $day ) ] );
		}

		$deleted = Retention::run();

		$this->assertSame( 10, $deleted );
		$kept = $this->remaining();
		for ( $day = 40; $day >= 31; $day-- ) {
			$this->assertNotContains( $ids[ $day ], $kept, "D-$day is deleted." );
		}
		$this->assertContains( $ids[30], $kept, 'An entry exactly 30 days old stays.' );
		$this->assertCount( 30, $kept );

		$this->assertSame( 0, Retention::run(), 'A second run finds nothing.' );
	}

	/**
	 * One second past the boundary goes.
	 *
	 * @return void
	 */
	public function test_one_second_over_goes() {
		$this->set_settings( [ 'general' => [ 'retention_days' => 1 ] ] );
		$stay = $this->add_entry( [], [ 'created_gmt' => $this->days_ago( 1 ) ] );
		$go   = $this->add_entry( [], [ 'created_gmt' => $this->days_ago( 1, 1 ) ] );
		Retention::run();
		$this->assertSame( [ $stay ], $this->remaining() );
		$this->assertNotContains( $go, $this->remaining() );
	}

	/**
	 * Retention 0 keeps everything; values and meta go with an entry; spam and trash go too.
	 *
	 * @return void
	 */
	public function test_off_by_default_and_complete_deletion() {
		global $wpdb;
		$old = $this->add_entry( [], [ 'created_gmt' => $this->days_ago( 400 ) ] );
		$wpdb->insert(
			Database::table( 'entry_meta' ),
			[
				'entry_id'   => $old,
				'meta_key'   => 'k',
				'meta_value' => 'v',
			]
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test setup.
		$spam  = $this->add_entry(
			[],
			[
				'created_gmt' => $this->days_ago( 400 ),
				'status'      => 'spam',
			]
		);
		$trash = $this->add_entry( [], [ 'created_gmt' => $this->days_ago( 400 ) ], 'trash' );

		$this->assertSame( 0, Retention::run(), 'Retention 0 is off.' );
		$this->assertCount( 3, $this->remaining() );

		$this->set_settings( [ 'general' => [ 'retention_days' => 365 ] ] );
		$this->assertSame( 3, Retention::run() );
		$this->assertSame( [], $this->remaining() );
		$this->assertSame( [], Entries_Repository::values( $old ) );
		$this->assertSame( '0', (string) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Database::table( 'entry_meta' ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Test read.
		unset( $spam, $trash );
	}

	/**
	 * More than one batch, and the daily event runs it.
	 *
	 * @return void
	 */
	public function test_batches_and_daily_event() {
		global $wpdb;
		$this->set_settings( [ 'general' => [ 'retention_days' => 10 ] ] );
		$schema = $this->schema();
		$rows   = [];
		for ( $i = 0; $i < 1203; $i++ ) {
			$rows[] = $wpdb->prepare( '(%s, %s, %s, %s)', $this->form_id, $schema->hash, 0 === $i % 3 ? 'spam' : 'new', $this->days_ago( 20, $i ) );
		}
		$wpdb->query( 'INSERT INTO ' . Database::table( 'entries' ) . ' (form_id, schema_hash, status, created_gmt) VALUES ' . implode( ',', $rows ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Test setup.
		$keep = $this->add_entry( [], [ 'created_gmt' => $this->days_ago( 2 ) ] );

		do_action( Cron::DAILY );

		$this->assertSame( [ $keep ], $this->remaining() );
	}
}
