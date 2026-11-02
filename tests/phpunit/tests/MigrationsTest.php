<?php
/**
 * Tests for SwiftForms\Migrations (scenario S11 at unit level).
 *
 * @package SwiftForms
 */

require_once dirname( __DIR__ ) . '/includes/class-sf-storage-test-case.php';

use SwiftForms\Backups;
use SwiftForms\Clock;
use SwiftForms\Database;
use SwiftForms\Install_Marker;
use SwiftForms\Migrations;
use SwiftForms\Settings;

/**
 * Class MigrationsTest
 *
 * @group migrations
 */
class MigrationsTest extends SF_Storage_Test_Case {

	/**
	 * Start every test as a site that has not run the plugin, with no SwiftForms tables.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		$this->use_scratch_prefix();
		$this->clear_options();
		Clock::freeze( 1795082400 );
	}

	/**
	 * From nothing: tables, version 1, one migration log row, no backup.
	 *
	 * @return void
	 */
	public function test_fresh_install() {
		$this->assertTrue( Migrations::maybe_run() );

		$this->assertSame( [], Database::missing() );
		$this->assertSame( 1, (int) get_option( Migrations::OPTION_DB_VERSION ) );
		$this->assertSame( [], Backups::all(), 'A new install has nothing to back up.' );

		$log = $this->rows( 'log' );
		$this->assertCount( 1, $log );
		$this->assertSame( 'migration', $log[0]['type'] );
		$this->assertSame( 'info', $log[0]['level'] );
		$this->assertSame( 'migration-1', $log[0]['ref'] );
		$this->assertSame( '2026-11-19 10:00:00', $log[0]['created_gmt'] );
		$this->assertSame( [], Settings::stored_options(), 'Migrations never create a settings option.' );
		$this->assertNull( get_option( Migrations::OPTION_LOCK, null ), 'The lock is released.' );
	}

	/**
	 * The schema version is autoloaded; the lock is not.
	 *
	 * @return void
	 */
	public function test_version_option_autoload() {
		global $wpdb;

		Migrations::maybe_run();

		$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", Migrations::OPTION_DB_VERSION ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertContains( $autoload, [ 'on', 'yes', 'auto-on', 'auto' ] );
	}

	/**
	 * A second run is a no-op: no backup, no log row, the marker is untouched.
	 *
	 * @return void
	 */
	public function test_second_run_changes_nothing() {
		add_option(
			Install_Marker::OPTION,
			[
				'version' => '1.0.0',
				'time'    => 1700000000,
			],
			'',
			false
		);
		add_option( 'swiftforms_spam_honeypot', '0' );

		$this->assertTrue( Migrations::maybe_run() );
		$marker  = $this->raw_option( Install_Marker::OPTION );
		$backups = $this->raw_option( Backups::OPTION );
		$logs    = $this->rows( 'log' );
		$this->assertCount( 1, Backups::all() );

		Clock::freeze( 1795099999 );
		$this->assertFalse( Migrations::maybe_run() );

		$this->assertSame( $marker, $this->raw_option( Install_Marker::OPTION ) );
		$this->assertSame( $backups, $this->raw_option( Backups::OPTION ) );
		$this->assertSame( $logs, $this->rows( 'log' ) );
	}

	/**
	 * A site that has settings but no schema version is backed up once before it migrates.
	 *
	 * @return void
	 */
	public function test_existing_site_is_backed_up_once() {
		add_option( 'swiftforms_email_notification_to', 'owner@example.org' );
		add_option( Migrations::OPTION_DB_VERSION, 0 );

		$this->assertTrue( Migrations::maybe_run() );

		$backups = Backups::all();
		$this->assertCount( 1, $backups );
		$this->assertSame( 'migration-1', $backups[0]['reason'] );
		$this->assertSame(
			[
				'swiftforms_email_notification_to' => 'owner@example.org',
				Migrations::OPTION_DB_VERSION      => '0', // WordPress hands scalar options back as strings.
			],
			$backups[0]['options'],
			'The backup holds the options as they were before the migration.'
		);
		$this->assertSame( 1, (int) get_option( Migrations::OPTION_DB_VERSION ) );
		$this->assertSame( 'owner@example.org', get_option( 'swiftforms_email_notification_to' ), 'The settings are not rewritten.' );
	}

	/**
	 * The internal action fires once with the old and new version.
	 *
	 * @return void
	 */
	public function test_version_changed_action() {
		$calls = [];
		add_action(
			'swiftforms_db_version_changed',
			static function ( $from, $to ) use ( &$calls ) {
				$calls[] = [ $from, $to ];
			},
			10,
			2
		);

		Migrations::maybe_run();
		Migrations::maybe_run();

		$this->assertSame( [ [ 0, 1 ] ], $calls );
	}

	/**
	 * A lock held by another request is respected; nothing runs and the lock stays.
	 *
	 * @return void
	 */
	public function test_live_lock_is_respected() {
		$lock = ( Clock::now() + 100 ) . ':someone-else';
		add_option( Migrations::OPTION_LOCK, $lock, '', false );

		$this->assertFalse( Migrations::maybe_run() );

		$this->assertNull( get_option( Migrations::OPTION_DB_VERSION, null ) );
		$this->assertCount( 6, Database::missing() );
		$this->assertSame( $lock, get_option( Migrations::OPTION_LOCK ), 'Another request\'s lock is not released by this one.' );
	}

	/**
	 * A lock past its expiry belongs to a crashed run and is taken over.
	 *
	 * @return void
	 */
	public function test_expired_lock_is_taken_over() {
		add_option( Migrations::OPTION_LOCK, ( Clock::now() - 1 ) . ':crashed', '', false );

		$this->assertTrue( Migrations::maybe_run() );

		$this->assertSame( 1, (int) get_option( Migrations::OPTION_DB_VERSION ) );
		$this->assertNull( get_option( Migrations::OPTION_LOCK, null ) );
	}

	/**
	 * A migration that cannot create its tables leaves the version alone and releases the lock.
	 *
	 * @return void
	 */
	public function test_failed_migration_does_not_bump_the_version() {
		global $wpdb;

		$block = static function ( $query ) {
			return 0 === stripos( trim( $query ), 'CREATE TABLE' ) ? 'SELECT 1 FROM sftests_no_such_table_for_swiftforms' : $query;
		};
		add_filter( 'query', $block, 1 );
		$suppress = $wpdb->suppress_errors();

		$this->assertFalse( Migrations::maybe_run() );

		$wpdb->suppress_errors( $suppress );
		remove_filter( 'query', $block, 1 );

		$this->assertNull( get_option( Migrations::OPTION_DB_VERSION, null ), 'The version only moves after a migration succeeded.' );
		$this->assertNull( get_option( Migrations::OPTION_LOCK, null ), 'The lock is released after a failure.' );

		// The next request retries and succeeds.
		$this->assertTrue( Migrations::maybe_run() );
		$this->assertSame( 1, (int) get_option( Migrations::OPTION_DB_VERSION ) );
	}
}
