<?php // phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Test helper: the class name SF_Storage_Test_Case follows the SF_ helpers of the R00 harness.
/**
 * Base class for the storage tests (tables, options, migrations, log).
 *
 * The core test case turns CREATE TABLE into CREATE TEMPORARY TABLE and rolls
 * every option write back, but the six SwiftForms tables of the bootstrap site
 * are real (the plugin installs itself on init, like on any site). So a test
 * that needs "a site without tables" switches $wpdb->prefix to a scratch prefix
 * for its own duration: dbDelta then creates temporary tables that are dropped
 * again in tear_down(), and the real tables are never touched. Options are not
 * affected by the switch ($wpdb->options keeps its table) and roll back as usual.
 *
 * @package SwiftForms
 */

use SwiftForms\Backups;
use SwiftForms\Clock;
use SwiftForms\Database;
use SwiftForms\Install_Marker;
use SwiftForms\Migrations;
use SwiftForms\Settings;

/**
 * Class SF_Storage_Test_Case
 */
abstract class SF_Storage_Test_Case extends WP_UnitTestCase {

	/**
	 * The real prefix while a scratch prefix is active, otherwise null.
	 *
	 * @var string|null
	 */
	private $real_prefix = null;

	/**
	 * Start every test from a known state.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		Clock::unfreeze();
		Settings::flush_cache();
	}

	/**
	 * Drop scratch tables, restore the prefix and the clock.
	 *
	 * @return void
	 */
	public function tear_down() {
		global $wpdb;

		if ( null !== $this->real_prefix ) {
			foreach ( Database::tables() as $table ) {
				$wpdb->query( 'DROP TABLE IF EXISTS `' . $table . '`' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Scratch table of this test.
			}
			$wpdb->prefix      = $this->real_prefix;
			$this->real_prefix = null;
		}
		Clock::unfreeze();
		Settings::flush_cache();
		parent::tear_down();
	}

	/**
	 * Use a prefix under which no SwiftForms table exists yet.
	 *
	 * @return void
	 */
	protected function use_scratch_prefix() {
		global $wpdb;

		if ( null === $this->real_prefix ) {
			$this->real_prefix = $wpdb->prefix;
			$wpdb->prefix      = $this->real_prefix . 'scratch_';
		}
	}

	/**
	 * Delete every SwiftForms bookkeeping option, as on a site that never ran the plugin.
	 *
	 * @return void
	 */
	protected function clear_options() {
		foreach ( Settings::paths() as $path ) {
			delete_option( Settings::option_name( $path ) );
		}
		foreach ( [ Migrations::OPTION_DB_VERSION, Install_Marker::OPTION, Backups::OPTION, Migrations::OPTION_LOCK ] as $name ) {
			delete_option( $name );
		}
		Settings::flush_cache();
	}

	/**
	 * Rows of a SwiftForms table.
	 *
	 * @param string $name Table name without prefix.
	 * @return array<int, array<string, string>>
	 */
	protected function rows( $name ) {
		global $wpdb;
		return (array) $wpdb->get_results( 'SELECT * FROM `' . Database::table( $name ) . '` ORDER BY 1', ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Test read of an own table.
	}

	/**
	 * Raw stored bytes of an option, bypassing every cache and filter.
	 *
	 * @param string $name Option name.
	 * @return string|null
	 */
	protected function raw_option( $name ) {
		global $wpdb;
		return $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Change settings for one test: writes the per-key options (booleans as "1"/"0", the way cassette-cmf stores them).
	 *
	 * @param array $change Partial settings, for example [ 'spam' => [ 'min_seconds' => 0 ] ].
	 * @return void
	 */
	protected function set_settings( array $change ) {
		foreach ( $change as $section => $keys ) {
			foreach ( (array) $keys as $key => $value ) {
				update_option( Settings::option_name( $section . '.' . $key ), is_bool( $value ) ? ( $value ? '1' : '0' ) : $value );
			}
		}
		Settings::flush_cache();
	}

	/**
	 * Count write attempts to the settings options (update attempts, even a no-op, and adds) while a test runs.
	 *
	 * @param array $writes Counter, passed by reference and incremented per write attempt.
	 * @return void
	 */
	protected function count_settings_writes( array &$writes ) {
		foreach ( Settings::paths() as $path ) {
			add_filter(
				'pre_update_option_' . Settings::option_name( $path ),
				static function ( $value ) use ( &$writes ) {
					++$writes['update'];
					return $value;
				}
			);
		}
		add_action(
			'add_option',
			static function ( $name ) use ( &$writes ) {
				if ( null !== Settings::path_of( $name ) ) {
					++$writes['add'];
				}
			}
		);
	}
}
