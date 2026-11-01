<?php // phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test helper: the class name SF_User_Config_Runner is fixed by the R00 plan; it reads stored state straight from the database on purpose.
/**
 * Runs one user config through the scenario matrix and collects its snapshots.
 *
 * R00 implements the harness self-check ("apply"), S1 (front-end render) and
 * S10 (schema and registry). Every other scenario must be listed in the
 * config's "skip" or the run fails: a scenario that is neither implemented nor
 * skipped is a gap in the safety net. S12 and S13 are Playwright-only.
 *
 * @package SwiftForms
 */

/**
 * Scenario runner.
 */
class SF_User_Config_Runner {

	/**
	 * Scenarios in run order. S12 and S13 run in the browser (tests/e2e), not here.
	 */
	const ORDER = [ 'apply', 'S1', 'S2', 'S3', 'S4', 'S5', 'S6', 'S7', 'S8', 'S9', 'S10', 'S11', 'S14', 'S15', 'S16' ];

	/**
	 * Scenarios this release implements.
	 */
	const IMPLEMENTED = [ 'apply', 'S1', 'S10' ];

	/**
	 * The test case, for go_to() and the user factory.
	 *
	 * @var WP_UnitTestCase_Base
	 */
	private $test;

	/**
	 * Snapshot texts produced so far, by path relative to the snapshot root.
	 *
	 * @var array<string, string>
	 */
	private $outputs = [];

	/**
	 * Failure messages.
	 *
	 * @var string[]
	 */
	private $failures = [];

	/**
	 * Number of assertions made.
	 *
	 * @var int
	 */
	private $checks = 0;

	/**
	 * Slug of the config being run.
	 *
	 * @var string
	 */
	private $slug = '';

	/**
	 * Constructor.
	 *
	 * @param WP_UnitTestCase_Base $test The running test case (must offer sf_go_to() and sf_factory()).
	 */
	public function __construct( $test ) {
		$this->test = $test;
	}

	/**
	 * Apply a config and run every scenario.
	 *
	 * @param array $config Loaded config.
	 * @return array{outputs: array<string, string>, failures: string[], checks: int}
	 */
	public function run( array $config ) {
		$this->slug = $config['slug'];
		SF_User_Config_Loader::apply( $config );

		$skipped = [];
		foreach ( self::ORDER as $scenario ) {
			if ( isset( $config['skip'][ $scenario ] ) ) {
				$skipped[ $scenario ] = $config['skip'][ $scenario ];
				continue;
			}
			if ( ! in_array( $scenario, self::IMPLEMENTED, true ) ) {
				$this->failures[] = 'Scenario ' . $scenario . ' is not implemented; add it or list it in skip.';
				continue;
			}
			call_user_func( [ $this, 'scenario_' . strtolower( $scenario ) ], $config );
		}
		// S12 and S13 are skipped only for the browser; a stale skip for them is still recorded.
		foreach ( [ 'S12', 'S13' ] as $browser_only ) {
			if ( isset( $config['skip'][ $browser_only ] ) ) {
				$skipped[ $browser_only ] = $config['skip'][ $browser_only ];
			}
		}

		// Removing a skip without implementing the scenario shows up here as a snapshot diff.
		$lines = [];
		foreach ( $skipped as $scenario => $reason ) {
			$lines[] = $scenario . ': ' . $reason;
		}
		$this->output( '_skipped.txt', $lines ? implode( "\n", $lines ) . "\n" : "(none)\n" );

		SF_User_Config_Loader::reset();
		wp_set_current_user( 0 );

		return [
			'outputs'  => $this->outputs,
			'failures' => $this->failures,
			'checks'   => $this->checks,
		];
	}

	/**
	 * Harness self-check: read the stored state back from the database.
	 *
	 * Dumps the site settings the config sets, every swiftforms_* option as the raw serialised bytes and every
	 * post's content, so a change to what a config writes (or to how it is
	 * written) is a diff. Post IDs are left out: they depend on the database.
	 */
	private function scenario_apply() {
		global $wpdb;

		$text = "== site ==\n";
		foreach ( [ 'home', 'siteurl', 'stylesheet', 'template', 'timezone_string', 'permalink_structure', 'WPLANG' ] as $name ) {
			$text .= $name . ' = ' . $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) ) . "\n";
		}

		$text .= "\n== options ==\n";
		$rows  = $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'swiftforms\\_%' ORDER BY option_name", ARRAY_A );
		foreach ( $rows as $row ) {
			$text .= $row['option_name'] . ' = ' . $row['option_value'] . "\n";
		}
		if ( ! $rows ) {
			$text .= "(none)\n";
		}

		$text .= "\n== posts ==\n";
		foreach ( SF_User_Config_Loader::ids() as $key => $id ) {
			$post  = $wpdb->get_row( $wpdb->prepare( "SELECT post_type, post_status, post_name, post_title, post_content FROM {$wpdb->posts} WHERE ID = %d", $id ), ARRAY_A );
			$text .= '-- ' . $key . ' (' . $post['post_type'] . '/' . $post['post_status'] . ', slug ' . $post['post_name'] . ', title ' . $post['post_title'] . ")\n" . $post['post_content'] . "\n";
		}
		if ( ! SF_User_Config_Loader::ids() ) {
			$text .= "(none)\n";
		}
		++$this->checks;
		$this->output( 'apply.txt', SF_Normaliser::text( $text ) );
	}

	/**
	 * S1: render every post as guest and as admin.
	 *
	 * R00 has no form source stack, so "render" is the_content on the post's own
	 * page. Pages without a SwiftForms block must enqueue no SwiftForms asset.
	 */
	private function scenario_s1() {
		$users = SF_User_Config_Loader::user_ids();
		$admin = $users['admin'] ?? $this->test->sf_factory()->user->create( [ 'role' => 'administrator' ] );

		foreach ( [
			'guest' => 0,
			'admin' => $admin,
		] as $who => $user_id ) {
			wp_set_current_user( $user_id );
			$text = '';
			foreach ( SF_User_Config_Loader::ids() as $key => $id ) {
				if ( 'page' !== get_post_type( $id ) && 'post' !== get_post_type( $id ) ) {
					continue;
				}
				$html = $this->render_post( $id, $has_form );
				if ( ! $has_form ) {
					$this->assert_no_assets( $key, $who );
				}
				$text .= '== ' . $key . " ==\n" . SF_Normaliser::html( $html ) . "\n\n";
			}
			$this->output( 'S1.' . $who . '.txt', '' === $text ? "(none)\n" : $text );
		}
		wp_set_current_user( 0 );
	}

	/**
	 * S10: schema and registry of this site (tables and blocks).
	 */
	private function scenario_s10() {
		global $wpdb;

		$text   = "== tables ==\n";
		$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'swiftforms_' ) . '%' ) );
		sort( $tables );
		foreach ( $tables as $table ) {
			$text .= '{prefix}' . substr( $table, strlen( $wpdb->prefix ) ) . "\n";
		}
		$text .= $tables ? '' : "(none)\n";

		$text .= "\n== blocks ==\n" . self::blocks_text();
		++$this->checks;
		$this->output( 'S10.txt', $text );
	}

	/**
	 * Render one post's content on its own page, as the current user.
	 *
	 * @param int  $post_id  Post ID.
	 * @param bool $has_form Set to whether the content holds a SwiftForms block.
	 * @return string
	 */
	private function render_post( $post_id, &$has_form ) {
		$this->reset_assets();
		$this->test->sf_go_to( get_permalink( $post_id ) );
		$post     = get_post( $post_id );
		$has_form = false !== strpos( $post->post_content, '<!-- wp:swiftforms/' );
		setup_postdata( $post );
		$html = apply_filters( 'the_content', $post->post_content ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.
		do_action( 'wp_enqueue_scripts' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.
		wp_reset_postdata();
		return $html;
	}

	/**
	 * Start a page with empty script and style queues.
	 */
	private function reset_assets() {
		wp_scripts()->queue = [];
		wp_styles()->queue  = [];
	}

	/**
	 * Fail when a SwiftForms script, style or script module is enqueued.
	 *
	 * @param string $key Post key.
	 * @param string $who "guest" or "admin".
	 */
	private function assert_no_assets( $key, $who ) {
		$found = [];
		foreach ( array_merge( wp_scripts()->queue, wp_styles()->queue ) as $handle ) {
			if ( 0 === stripos( $handle, 'swiftforms' ) ) {
				$found[] = $handle;
			}
		}
		ob_start();
		wp_script_modules()->print_enqueued_script_modules();
		$modules = ob_get_clean();
		if ( false !== stripos( $modules, 'swiftforms' ) ) {
			$found[] = 'script module';
		}
		++$this->checks;
		if ( $found ) {
			$this->failures[] = 'S1 (' . $who . '): post "' . $key . '" has no SwiftForms block but enqueues SwiftForms assets: ' . implode( ', ', $found );
		}
	}

	/**
	 * The swiftforms/* block names and attribute schemas SwiftForms hands to the builder.
	 *
	 * Read from SwiftForms' own block configs, not from WP_Block_Type_Registry: the
	 * block builder registers nothing on PHP below 8.2, and a snapshot must be the
	 * same on every PHP cell. Whether they are registered is asserted separately.
	 *
	 * @return string
	 */
	public static function blocks_text() {
		$configs = SwiftForms\Plugin::instance()->block_configs();
		$blocks  = isset( $configs['blocks'] ) && is_array( $configs['blocks'] ) ? $configs['blocks'] : $configs;
		$lines   = [];
		foreach ( $blocks as $block ) {
			if ( is_array( $block ) && isset( $block['name'] ) && 0 === strpos( (string) $block['name'], 'swiftforms/' ) ) {
				$lines[ $block['name'] ] = $block['name'] . ' ' . SF_Normaliser::json( $block['attributes'] ?? ( $block['fields'] ?? [] ) );
			}
		}
		ksort( $lines );
		return $lines ? implode( '', $lines ) : "(none)\n";
	}

	/**
	 * Hooks text for the _global snapshot: every swiftforms_* hook with callbacks
	 * and every SwiftForms\ callback on any hook, as "hook priority callback".
	 *
	 * @return string
	 */
	public static function hooks_text() {
		global $wp_filter;
		$lines = [];
		foreach ( $wp_filter as $hook => $filter ) {
			foreach ( $filter->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $entry ) {
					if ( 0 === strpos( $hook, 'swiftforms_' ) || SF_User_Config_Loader::is_swiftforms_callback( $entry['function'] ) ) {
						$lines[] = $hook . ' ' . ( PHP_INT_MAX === $priority ? 'PHP_INT_MAX' : $priority ) . ' ' . self::describe_callback( $entry['function'] );
					}
				}
			}
		}
		sort( $lines, SORT_STRING );
		return $lines ? implode( "\n", $lines ) . "\n" : "(none)\n";
	}

	/**
	 * REST routes in the swiftforms/v1 namespace, with methods and whether a logged-out caller passes.
	 *
	 * @return string
	 */
	public static function rest_routes_text() {
		wp_set_current_user( 0 );
		$lines = [];
		foreach ( rest_get_server()->get_routes( 'swiftforms/v1' ) as $route => $handlers ) {
			foreach ( $handlers as $handler ) {
				$methods = array_keys( array_filter( (array) $handler['methods'] ) );
				sort( $methods );
				$lines[] = $route . ' ' . implode( '|', $methods ) . ' ' . ( self::is_public( $handler ) ? 'public' : 'private' );
			}
		}
		sort( $lines, SORT_STRING );
		return $lines ? implode( "\n", $lines ) . "\n" : "(none)\n";
	}

	/**
	 * Whether a route handler lets a logged-out caller through.
	 *
	 * @param array $handler Route handler (from WP_REST_Server::get_routes()).
	 * @return bool
	 */
	public static function is_public( array $handler ) {
		if ( ! isset( $handler['permission_callback'] ) ) {
			return true;
		}
		if ( '__return_true' === $handler['permission_callback'] ) {
			return true;
		}
		wp_set_current_user( 0 );
		return true === call_user_func( $handler['permission_callback'], new WP_REST_Request( 'GET', '/' ) );
	}

	/**
	 * CREATE TABLE statements of every swiftforms_* table, prefix and AUTO_INCREMENT normalised.
	 *
	 * @return string
	 */
	public static function db_schema_text() {
		global $wpdb;
		$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'swiftforms_' ) . '%' ) );
		sort( $tables );
		$text = '';
		foreach ( $tables as $table ) {
			$row   = $wpdb->get_row( 'SHOW CREATE TABLE `' . esc_sql( $table ) . '`', ARRAY_N );
			$sql   = str_replace( '`' . $wpdb->prefix, '`{prefix}', $row[1] );
			$sql   = preg_replace( '/ AUTO_INCREMENT=\d+/', '', $sql );
			$text .= $sql . ";\n\n";
		}
		return SF_Normaliser::text( '' === $text ? "(none)\n" : $text );
	}

	/**
	 * Name a callback without anything that changes between runs (object ids, line numbers).
	 *
	 * @param mixed $callback A WordPress callback.
	 * @return string
	 */
	public static function describe_callback( $callback ) {
		if ( $callback instanceof Closure ) {
			$reflection = new ReflectionFunction( $callback );
			$scope      = $reflection->getClosureScopeClass();
			return 'Closure(' . ( $scope ? $scope->getName() : $reflection->getNamespaceName() ) . ')';
		}
		if ( is_array( $callback ) ) {
			return ( is_object( $callback[0] ) ? get_class( $callback[0] ) : $callback[0] ) . '::' . $callback[1];
		}
		if ( is_object( $callback ) ) {
			return get_class( $callback ) . '::__invoke';
		}
		return (string) $callback;
	}

	/**
	 * Record a snapshot text.
	 *
	 * @param string $name File name inside the config's snapshot directory.
	 * @param string $text Text.
	 */
	private function output( $name, $text ) {
		$this->outputs[ $this->slug . '/' . $name ] = $text;
	}
}
