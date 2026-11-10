<?php
/**
 * Multisite behaviour of the storage layer (ADR-003).
 *
 * Skipped unless the suite runs as a network: composer test:multisite.
 *
 * @package SwiftForms
 */

require_once dirname( __DIR__ ) . '/includes/class-sf-storage-test-case.php';

use SwiftForms\Activator;
use SwiftForms\Database;
use SwiftForms\Install_Marker;
use SwiftForms\Migrations;
use SwiftForms\Settings;

/**
 * Class MultisiteTest
 *
 * @group multisite
 */
class MultisiteTest extends SF_Storage_Test_Case {

	/**
	 * Skip on a single-site run.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only: run composer test:multisite.' );
		}
	}

	/**
	 * Mark SwiftForms network-active, the way a network admin does.
	 *
	 * @return void
	 */
	private function network_activate() {
		update_site_option( 'active_sitewide_plugins', [ plugin_basename( SWIFTFORMS_FILE ) => time() ] );
	}

	/**
	 * Whether a site has all six tables.
	 *
	 * @param int $blog_id Site ID.
	 * @return bool
	 */
	private function has_tables( $blog_id ) {
		switch_to_blog( $blog_id );
		$missing = Database::missing();
		restore_current_blog();
		return [] === $missing;
	}

	/**
	 * Whether a site has any SwiftForms bookkeeping option.
	 *
	 * @param int $blog_id Site ID.
	 * @return bool
	 */
	private function has_options( $blog_id ) {
		switch_to_blog( $blog_id );
		$found = null !== get_option( Migrations::OPTION_DB_VERSION, null ) || null !== get_option( Install_Marker::OPTION, null );
		restore_current_blog();
		return $found;
	}

	/**
	 * A site that has no tables yet installs itself on its first init, with its own prefix.
	 *
	 * @return void
	 */
	public function test_lazy_install_on_init_for_a_site_without_tables() {
		$blog_id = self::factory()->blog->create();
		$this->assertFalse( $this->has_tables( $blog_id ) );
		$this->assertFalse( $this->has_options( $blog_id ) );

		switch_to_blog( $blog_id );
		$this->assertSame( 1, has_action( 'init', [ Activator::class, 'maybe_install' ] ) );
		Activator::maybe_install();
		$this->assertSame( 1, (int) get_option( Migrations::OPTION_DB_VERSION ) );
		$this->assertNotNull( get_option( Install_Marker::OPTION, null ) );
		$this->assertStringStartsWith( $GLOBALS['wpdb']->get_blog_prefix( $blog_id ), Database::table( 'entries' ) );
		restore_current_blog();

		$this->assertTrue( $this->has_tables( $blog_id ) );
	}

	/**
	 * A site created while the plugin is network-active is installed on wp_initialize_site.
	 *
	 * @return void
	 */
	public function test_new_site_is_installed_when_network_active() {
		$this->network_activate();

		$blog_id = self::factory()->blog->create();

		$this->assertTrue( $this->has_tables( $blog_id ) );
		switch_to_blog( $blog_id );
		$this->assertSame( 1, (int) get_option( Migrations::OPTION_DB_VERSION ) );
		$this->assertSame( SWIFTFORMS_VERSION, Install_Marker::installed_version() );
		$this->assertSame( [], Settings::stored_options(), 'No settings option is written for a new site either.' );
		restore_current_blog();
	}

	/**
	 * Without network activation a new site is left alone.
	 *
	 * @return void
	 */
	public function test_new_site_is_not_installed_when_not_network_active() {
		$blog_id = self::factory()->blog->create();

		$this->assertFalse( $this->has_tables( $blog_id ) );
		$this->assertFalse( $this->has_options( $blog_id ) );
	}

	/**
	 * Network activation loops over nothing: other sites are untouched.
	 *
	 * @return void
	 */
	public function test_network_activation_touches_no_other_site() {
		$first  = self::factory()->blog->create();
		$second = self::factory()->blog->create();

		Activator::activate( true );

		foreach ( [ $first, $second ] as $blog_id ) {
			$this->assertFalse( $this->has_tables( $blog_id ) );
			$this->assertFalse( $this->has_options( $blog_id ) );
		}
	}

	/**
	 * The drop-tables filter lists the six tables with the deleted site's prefix.
	 *
	 * @return void
	 */
	public function test_drop_tables_lists_the_sites_tables() {
		global $wpdb;

		$blog_id = self::factory()->blog->create();
		$tables  = apply_filters( 'wpmu_drop_tables', [], $blog_id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.

		$expected = [];
		foreach ( Database::TABLES as $name ) {
			$expected[] = $wpdb->get_blog_prefix( $blog_id ) . 'swiftforms_' . $name;
		}
		$this->assertSame( $expected, $tables );
	}

	/**
	 * Deleting a site drops its SwiftForms tables.
	 *
	 * @return void
	 */
	public function test_deleting_a_site_drops_its_tables() {
		$this->network_activate();
		$blog_id = self::factory()->blog->create();
		$this->assertTrue( $this->has_tables( $blog_id ) );

		wp_delete_site( $blog_id );

		$this->assertFalse( $this->has_tables( $blog_id ) );
	}
}
