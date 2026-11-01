<?php
require_once dirname( __DIR__ ) . '/includes/class-sf-snapshot-store.php';
require_once dirname( __DIR__ ) . '/includes/class-sf-user-config-runner.php';
require_once dirname( __DIR__, 2 ) . '/user-configs/loader.php';
require_once dirname( __DIR__, 2 ) . '/user-configs/normaliser.php';

/**
 * R00 regression safety net: the numbered user configurations.
 *
 * Each configuration is a different kind of real site, written to the database
 * as raw stored data. The test runs it through the scenario matrix and compares
 * every output with a golden-master snapshot in tests/user-configs/snapshots/.
 * Any release that changes what an existing user sees, or what is stored in
 * their database, fails here.
 *
 * Run it with bin/test-user-configs.sh (composer test:configs). It needs the
 * bootstrap-user-configs.php bootstrap, which keeps it away from the real site.
 * Under the default bootstrap every test here is skipped.
 *
 * @group user-configs
 */
class SwiftForms_User_Config_Regression_Test extends WP_UnitTestCase {

	/**
	 * Saved global state, restored after every test.
	 *
	 * @var array
	 */
	private $globals_backup = [];

	/**
	 * Skip unless the guarded user-config bootstrap is in use.
	 */
	public static function set_up_before_class() {
		if ( ! defined( 'SWIFTFORMS_USER_CONFIGS_BOOTSTRAP' ) ) {
			self::markTestSkipped( 'Run the user-config group with bin/test-user-configs.sh (composer test:configs); it needs the guarded bootstrap.' );
		}
		parent::set_up_before_class();
	}

	/**
	 * Expose the core test factory to the runner.
	 *
	 * @return WP_UnitTest_Factory
	 */
	public function sf_factory() {
		return self::factory();
	}

	/**
	 * Expose go_to() to the runner.
	 *
	 * @param string $url URL.
	 */
	public function sf_go_to( $url ) {
		$this->go_to( $url );
	}

	public function set_up() {
		parent::set_up();
		foreach ( [ 'wp_scripts', 'wp_styles', 'wp_registered_sidebars' ] as $name ) {
			$this->globals_backup[ $name ] = array_key_exists( $name, $GLOBALS ) ? [ true, $GLOBALS[ $name ] ] : [ false, null ];
		}
	}

	public function tear_down() {
		SF_User_Config_Loader::reset();
		foreach ( $this->globals_backup as $name => $entry ) {
			if ( $entry[0] ) {
				$GLOBALS[ $name ] = $entry[1]; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			} else {
				unset( $GLOBALS[ $name ] );
			}
		}
		parent::tear_down();
	}

	/**
	 * Every config, in numeric order, keyed by slug.
	 *
	 * @return array<string, array{string}>
	 */
	public function config_provider() {
		$rows = [];
		foreach ( SF_User_Config_Loader::list() as $slug ) {
			$rows[ $slug ] = [ $slug ];
		}
		return $rows;
	}

	/**
	 * Run one configuration against its snapshots.
	 *
	 * @dataProvider config_provider
	 *
	 * @param string $slug Config slug.
	 */
	public function test_user_config( $slug ) {
		$store  = new SF_Snapshot_Store( SF_User_Config_Loader::dir() . '/snapshots' );
		$runner = new SF_User_Config_Runner( $this );
		$result = $runner->run( SF_User_Config_Loader::load( $slug ) );

		$problems = $result['failures'];
		foreach ( $result['outputs'] as $relative => $text ) {
			$problem = $store->check( $relative, $text );
			if ( null !== $problem ) {
				$problems[] = $problem;
			}
		}
		foreach ( $store->stale( $slug ) as $stale ) {
			$problems[] = 'Stale snapshot (no scenario produces it any more): ' . $stale;
		}

		$this->addToAssertionCount( $result['checks'] + count( $result['outputs'] ) );
		if ( $store->is_update_mode() ) {
			fwrite( STDERR, sprintf( "  [update] %s: %d snapshot file(s) written\n", $slug, $store->written() ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		}
		$this->report( $slug, $problems );
	}

	/**
	 * Run-wide snapshots: hooks, REST routes, database schema and blocks.
	 *
	 * Run once by bin/test-user-configs.sh after the configs. They are the same
	 * on every PHP version: the block builder registers nothing below PHP 8.2, so
	 * nothing here reads from it (hooks leave out SwiftForms\Vendor\, routes list
	 * only swiftforms/v1, blocks come from SwiftForms' own configs).
	 */
	public function test_global_snapshots() {
		$store   = new SF_Snapshot_Store( SF_User_Config_Loader::dir() . '/snapshots' );
		$outputs = [
			'_global/hooks.txt'       => SF_User_Config_Runner::hooks_text(),
			'_global/rest-routes.txt' => SF_User_Config_Runner::rest_routes_text(),
			'_global/db-schema.txt'   => SF_User_Config_Runner::db_schema_text(),
			'_global/blocks.txt'      => SF_User_Config_Runner::blocks_text(),
		];

		$problems = [];
		foreach ( $outputs as $relative => $text ) {
			$problem = $store->check( $relative, $text );
			if ( null !== $problem ) {
				$problems[] = $problem;
			}
		}
		foreach ( $store->stale( '_global' ) as $stale ) {
			$problems[] = 'Stale snapshot (no scenario produces it any more): ' . $stale;
		}
		$this->addToAssertionCount( count( $outputs ) );
		$this->assert_builder_config_route_is_not_public();
		$this->assert_configured_blocks_are_registered();
		$this->report( 'global', $problems );
	}

	/**
	 * Not snapshotted (the builder only exists on PHP 8.2 and later): its config route is never public.
	 */
	private function assert_builder_config_route_is_not_public() {
		$handlers = rest_get_server()->get_routes( 'cassette-cmf-block-builder/v1' );
		if ( ! $handlers ) {
			// The builder has not started (no blocks configured, or PHP below 8.2): nothing to check.
			$this->addToAssertionCount( 1 );
			return;
		}
		foreach ( $handlers as $route => $route_handlers ) {
			foreach ( $route_handlers as $handler ) {
				$this->assertFalse( SF_User_Config_Runner::is_public( $handler ), "The builder route {$route} is public." );
			}
		}
	}

	/**
	 * Every swiftforms/* block SwiftForms configures is registered (PHP 8.2 and later only).
	 */
	private function assert_configured_blocks_are_registered() {
		if ( PHP_VERSION_ID < 80200 ) {
			// The block builder registers nothing below PHP 8.2.
			$this->addToAssertionCount( 1 );
			return;
		}
		foreach ( explode( "\n", trim( SF_User_Config_Runner::blocks_text() ) ) as $line ) {
			if ( '(none)' === $line || '' === $line ) {
				continue;
			}
			$name = strtok( $line, ' ' );
			$this->assertTrue( WP_Block_Type_Registry::get_instance()->is_registered( $name ), "{$name} is configured but not registered." );
		}
		$this->addToAssertionCount( 1 );
	}

	/**
	 * Fail with a readable report when there are problems.
	 *
	 * @param string   $label    What was run.
	 * @param string[] $problems Messages.
	 */
	private function report( $label, array $problems ) {
		if ( ! $problems ) {
			$this->assertSame( [], $problems );
			return;
		}
		// SF_FULL_REPORT=1 lists every problem instead of the first eight.
		$shown = '1' === getenv( 'SF_FULL_REPORT' ) ? $problems : array_slice( $problems, 0, 8 );
		$more  = count( $problems ) - count( $shown );
		$this->fail(
			$label . ': ' . count( $problems ) . " regression(s) against the snapshots.\n\n"
			. implode( "\n\n", $shown )
			. ( $more > 0 ? "\n\n... and " . $more . ' more.' : '' )
			. "\n\nIf the change is intended, it must be listed under \"Intended snapshot changes\" in the release plan; then regenerate with SF_UPDATE_SNAPSHOTS=1."
		);
	}
}
