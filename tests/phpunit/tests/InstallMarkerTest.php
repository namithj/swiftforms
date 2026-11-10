<?php
/**
 * Tests for SwiftForms\Install_Marker (C-1.4).
 *
 * @package SwiftForms
 */

require_once dirname( __DIR__ ) . '/includes/class-sf-storage-test-case.php';

use SwiftForms\Clock;
use SwiftForms\Install_Marker;
use SwiftForms\Migrations;

/**
 * Class InstallMarkerTest
 *
 * @group install-marker
 */
class InstallMarkerTest extends SF_Storage_Test_Case {

	/**
	 * A site that never ran the plugin gets the marker once, and later calls change nothing.
	 *
	 * @return void
	 */
	public function test_fresh_site_writes_once() {
		$this->clear_options();
		Clock::freeze( 1795082400 );

		$this->assertTrue( Install_Marker::maybe_write() );
		$bytes = $this->raw_option( Install_Marker::OPTION );
		$this->assertSame(
			[
				'version' => SWIFTFORMS_VERSION,
				'time'    => 1795082400,
			],
			get_option( Install_Marker::OPTION )
		);

		Clock::freeze( 1795099999 );
		$this->assertFalse( Install_Marker::maybe_write() );
		$this->assertSame( $bytes, $this->raw_option( Install_Marker::OPTION ), 'The marker is never rewritten.' );
	}

	/**
	 * The marker is not autoloaded.
	 *
	 * @return void
	 */
	public function test_marker_is_not_autoloaded() {
		global $wpdb;

		$this->clear_options();
		Install_Marker::maybe_write();

		$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", Install_Marker::OPTION ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertContains( $autoload, [ 'no', 'off' ] );
	}

	/**
	 * An existing marker (here from an "older" release) is never changed.
	 *
	 * @return void
	 */
	public function test_existing_marker_is_never_updated() {
		$this->clear_options();
		add_option(
			Install_Marker::OPTION,
			[
				'version' => '0.5.0',
				'time'    => 1700000000,
			],
			'',
			false
		);
		$bytes = $this->raw_option( Install_Marker::OPTION );

		$this->assertFalse( Install_Marker::maybe_write() );
		$this->assertSame( $bytes, $this->raw_option( Install_Marker::OPTION ) );
		$this->assertSame( '0.5.0', Install_Marker::installed_version() );
	}

	/**
	 * A damaged marker is left alone as well.
	 *
	 * @return void
	 */
	public function test_damaged_marker_is_left_alone() {
		$this->clear_options();
		add_option( Install_Marker::OPTION, 'garbage', '', false );

		$this->assertFalse( Install_Marker::maybe_write() );
		$this->assertSame( 'garbage', get_option( Install_Marker::OPTION ) );
		$this->assertSame( SWIFTFORMS_VERSION, Install_Marker::installed_version() );
	}

	/**
	 * A site with a schema version but no marker counts as installed at 1.0.0, and no marker is written.
	 *
	 * @return void
	 */
	public function test_db_version_without_marker_is_1_0_0() {
		$this->clear_options();
		add_option( Migrations::OPTION_DB_VERSION, 1 );

		$this->assertSame( '1.0.0', Install_Marker::installed_version() );
		$this->assertFalse( Install_Marker::maybe_write() );
		$this->assertNull( get_option( Install_Marker::OPTION, null ), 'No marker is invented for an existing site.' );
	}

	/**
	 * A brand-new site reports the running version.
	 *
	 * @return void
	 */
	public function test_new_site_reports_running_version() {
		$this->clear_options();
		$this->assertSame( SWIFTFORMS_VERSION, Install_Marker::installed_version() );
	}
}
