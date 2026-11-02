<?php
/**
 * Tests for SwiftForms\Activator and SwiftForms\Cron.
 *
 * @package SwiftForms
 */

require_once dirname( __DIR__ ) . '/includes/class-sf-storage-test-case.php';

use SwiftForms\Activator;
use SwiftForms\Backups;
use SwiftForms\Clock;
use SwiftForms\Cron;
use SwiftForms\Database;
use SwiftForms\Install_Marker;
use SwiftForms\Migrations;
use SwiftForms\Settings;

/**
 * Class ActivatorTest
 *
 * @group activator
 */
class ActivatorTest extends SF_Storage_Test_Case {

	/**
	 * Start as a site that has not run the plugin.
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
	 * The hooks are wired by Plugin::boot() and the activation hooks by the main file.
	 *
	 * @return void
	 */
	public function test_hooks_are_wired() {
		$this->assertSame( 1, has_action( 'init', [ Activator::class, 'maybe_install' ] ) );
		$this->assertSame( 900, has_action( 'wp_initialize_site', [ Activator::class, 'initialize_site' ] ) );
		$this->assertSame( 10, has_filter( 'wpmu_drop_tables', [ Activator::class, 'drop_tables' ] ) );
		$this->assertSame( 10, has_action( 'init', [ Cron::class, 'maybe_schedule' ] ) );
		$this->assertSame( 10, has_action( Cron::DAILY, [ Cron::class, 'run_daily' ] ) );

		$hook = 'activate_' . plugin_basename( SWIFTFORMS_FILE );
		$this->assertSame( 10, has_action( $hook, [ Activator::class, 'activate' ] ) );
		$this->assertSame( 10, has_action( 'deactivate_' . plugin_basename( SWIFTFORMS_FILE ), [ Activator::class, 'deactivate' ] ) );
	}

	/**
	 * Single-site activation installs now: marker, tables, version; and never writes the settings row.
	 *
	 * @return void
	 */
	public function test_activation_installs_a_single_site() {
		Activator::activate( false );

		$this->assertSame(
			[
				'version' => SWIFTFORMS_VERSION,
				'time'    => 1795082400,
			],
			get_option( Install_Marker::OPTION )
		);
		$this->assertSame( 1, (int) get_option( Migrations::OPTION_DB_VERSION ) );
		$this->assertSame( [], Database::missing() );
		$this->assertSame( [], Settings::stored_options(), 'Activation never writes a settings option (C-5.2).' );
		$this->assertSame( [], Backups::all() );
	}

	/**
	 * Activating again (deactivate, activate) changes nothing.
	 *
	 * @return void
	 */
	public function test_reactivation_is_a_noop() {
		Activator::activate( false );
		$marker = $this->raw_option( Install_Marker::OPTION );
		$logs   = $this->rows( 'log' );

		Clock::freeze( 1795099999 );
		Activator::deactivate();
		Activator::activate( false );

		$this->assertSame( $marker, $this->raw_option( Install_Marker::OPTION ) );
		$this->assertSame( $logs, $this->rows( 'log' ) );
		$this->assertSame( [], Backups::all() );
	}

	/**
	 * Deactivation keeps every option and table and removes the cron event.
	 *
	 * @return void
	 */
	public function test_deactivation_leaves_data_and_clears_cron() {
		Activator::activate( false );
		add_option( 'swiftforms_spam_honeypot', '0' );
		Cron::maybe_schedule();
		$this->assertNotFalse( wp_next_scheduled( Cron::DAILY ) );

		Activator::deactivate();

		$this->assertFalse( wp_next_scheduled( Cron::DAILY ) );
		$this->assertSame( 1, (int) get_option( Migrations::OPTION_DB_VERSION ) );
		$this->assertNotNull( get_option( Install_Marker::OPTION, null ) );
		$this->assertSame( '0', get_option( 'swiftforms_spam_honeypot' ) );
		$this->assertSame( [], Database::missing() );
	}

	/**
	 * The lazy install on init covers a site the activation hook never saw.
	 *
	 * @return void
	 */
	public function test_lazy_install_when_the_version_is_missing() {
		Activator::maybe_install();

		$this->assertNotNull( get_option( Install_Marker::OPTION, null ) );
		$this->assertSame( 1, (int) get_option( Migrations::OPTION_DB_VERSION ) );
		$this->assertSame( [], Database::missing() );
		$this->assertCount( 1, $this->rows( 'log' ) );

		Activator::maybe_install();
		$this->assertCount( 1, $this->rows( 'log' ), 'The second request does nothing.' );
	}

	/**
	 * An existing site without a marker is not given one (it counts as installed at 1.0.0).
	 *
	 * @return void
	 */
	public function test_lazy_install_does_not_invent_a_marker_for_an_existing_site() {
		add_option( Migrations::OPTION_DB_VERSION, 0 );
		add_option( 'swiftforms_general_store_ip', '1' );

		Activator::maybe_install();

		$this->assertNull( get_option( Install_Marker::OPTION, null ) );
		$this->assertSame( '1.0.0', Install_Marker::installed_version() );
		$this->assertCount( 1, Backups::all() );
	}

	/**
	 * Core is told to drop all six tables of a deleted site.
	 *
	 * @return void
	 */
	public function test_drop_tables_filter() {
		global $wpdb;

		$tables = apply_filters( 'wpmu_drop_tables', [ $wpdb->prefix . 'options' ], 1 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.

		$this->assertContains( $wpdb->prefix . 'options', $tables );
		foreach ( Database::tables( 1 ) as $table ) {
			$this->assertContains( $table, $tables );
		}
		$this->assertCount( 7, $tables );
		$this->assertSame( $tables, Activator::drop_tables( $tables, 1 ), 'No duplicates when it runs twice.' );
	}

	/**
	 * The daily event is scheduled lazily, once.
	 *
	 * @return void
	 */
	public function test_cron_is_scheduled_once() {
		Cron::unschedule();
		$this->assertFalse( wp_next_scheduled( Cron::DAILY ) );

		Cron::maybe_schedule();
		$first = wp_next_scheduled( Cron::DAILY );
		Cron::maybe_schedule();

		$this->assertSame( 1795082400 + HOUR_IN_SECONDS, $first );
		$this->assertSame( $first, wp_next_scheduled( Cron::DAILY ) );
		$this->assertSame( 'daily', wp_get_schedule( Cron::DAILY ) );

		Cron::unschedule();
		$this->assertFalse( wp_next_scheduled( Cron::DAILY ) );
	}

	/**
	 * The daily run prunes the log.
	 *
	 * @return void
	 */
	public function test_daily_run_prunes_the_log() {
		global $wpdb;

		Database::install();
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Database::table( 'log' ),
			[
				'type'        => 'error',
				'message'     => 'old',
				'context'     => '{}',
				'created_gmt' => gmdate( 'Y-m-d H:i:s', Clock::now() - 31 * DAY_IN_SECONDS ),
			]
		);

		Cron::run_daily();

		$this->assertCount( 0, $this->rows( 'log' ) );
	}
}
