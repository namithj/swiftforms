<?php
/**
 * Tests for SwiftForms\Backups.
 *
 * @package SwiftForms
 */

require_once dirname( __DIR__ ) . '/includes/class-sf-storage-test-case.php';

use SwiftForms\Backups;
use SwiftForms\Clock;
use SwiftForms\Install_Marker;
use SwiftForms\Migrations;
use SwiftForms\Settings;

/**
 * Class BackupsTest
 *
 * @group backups
 */
class BackupsTest extends SF_Storage_Test_Case {

	/**
	 * Raw values are copied and absent options are left out, not recorded as defaults.
	 *
	 * @return void
	 */
	public function test_records_raw_values_and_absent_options() {
		$this->clear_options();
		Clock::freeze( 1795082400 );
		add_option( 'swiftforms_spam_honeypot', '0' );
		add_option( 'swiftforms_email_notification_to', [ 'garbage' ] ); // Raw: a corrupt value is copied as it is.
		add_option( 'swiftforms_something_else', 'not a settings option' );
		add_option( Migrations::OPTION_DB_VERSION, 0 );

		$entry = Backups::create( 'Migration 1!' );

		$this->assertSame( 'migration1', $entry['reason'], 'The reason is normalised to [a-z0-9._-].' );
		$this->assertSame( 1795082400, $entry['time'] );
		$this->assertSame( SWIFTFORMS_VERSION, $entry['version'] );
		$this->assertSame(
			[
				'swiftforms_email_notification_to' => [ 'garbage' ],
				'swiftforms_spam_honeypot'         => '0',
				Migrations::OPTION_DB_VERSION      => 0,
			],
			$entry['options']
		);
		$this->assertArrayNotHasKey( Install_Marker::OPTION, $entry['options'] );
		$this->assertSame( [ $entry ], Backups::all() );
	}

	/**
	 * The ring keeps the five newest, newest last, and is not autoloaded.
	 *
	 * @return void
	 */
	public function test_ring_keeps_five() {
		global $wpdb;

		$this->clear_options();
		for ( $i = 1; $i <= 7; $i++ ) {
			Clock::freeze( 1795082400 + $i );
			Backups::create( 'r' . $i );
		}

		$all = Backups::all();
		$this->assertCount( Backups::KEEP, $all );
		$this->assertSame( [ 'r3', 'r4', 'r5', 'r6', 'r7' ], wp_list_pluck( $all, 'reason' ) );

		$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", Backups::OPTION ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertContains( $autoload, [ 'no', 'off' ] );
	}

	/**
	 * A backup never carries secrets, and a full ring of identical entries still stores.
	 *
	 * @return void
	 */
	public function test_secrets_are_never_copied_and_identical_entries_store() {
		$this->clear_options();
		add_option( 'swiftforms_secrets', [ 'captcha' => 'top-secret' ], '', false );
		Clock::freeze( 1795082400 );

		for ( $i = 0; $i < 7; $i++ ) {
			$this->assertIsArray( Backups::create( 'same' ) );
		}
		$this->assertCount( 5, Backups::all() );
		$this->assertStringNotContainsString( 'top-secret', (string) $this->raw_option( Backups::OPTION ) );
	}

	/**
	 * A damaged ring is read as empty and replaced by the next backup.
	 *
	 * @return void
	 */
	public function test_damaged_ring_does_not_fatal() {
		$this->clear_options();
		update_option( Backups::OPTION, 'junk', false );
		$this->assertSame( [], Backups::all() );

		Backups::create( 'after-junk' );
		$this->assertCount( 1, Backups::all() );
	}
}
