<?php
/**
 * The security lint: its fixtures and the plugin's own code.
 *
 * @package SwiftForms
 */

/**
 * Class SecurityLintTest
 *
 * @group security
 */
class SecurityLintTest extends WP_UnitTestCase {

	/**
	 * Load the lint functions (the script only runs as a command).
	 *
	 * @return void
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();
		require_once dirname( __DIR__, 4 ) . '/bin/check-security.php';
	}

	/**
	 * Each fixture trips exactly the rules it names.
	 *
	 * @return void
	 */
	public function test_self_test() {
		$result = sf_lint_self_test( dirname( __DIR__, 4 ) );
		$this->assertSame( [], $result['failures'] );
		$this->assertGreaterThanOrEqual( 7, $result['fixtures'] );
	}

	/**
	 * The plugin's own code is clean.
	 *
	 * @return void
	 */
	public function test_plugin_is_clean() {
		$root   = dirname( __DIR__, 4 );
		$result = sf_lint_run( [ $root . '/includes', $root . '/swiftforms.php' ], $root );
		$this->assertSame( [], $result['hits'] );
		$this->assertGreaterThan( 40, $result['files'] );
	}

	/**
	 * A bad file is reported, rule by rule.
	 *
	 * @return void
	 */
	public function test_violations_are_reported() {
		$code  = "<?php\nregister_rest_route( 'x/v1', '/y', [ 'callback' => 'f' ] );\nadd_action( 'wp_ajax_nopriv_z', 'f' );\n\$wpdb->query( \"DELETE FROM t WHERE a = \$a\" );\n";
		$rules = array_column( sf_lint_source( $code, 'includes/x.php' ), 'rule' );
		sort( $rules );
		$this->assertSame( [ 'R1', 'R3', 'R5' ], $rules );
	}

	/**
	 * Comments never trip a rule, and the public route class is the one place for __return_true.
	 *
	 * @return void
	 */
	public function test_allow_lists() {
		$this->assertSame( [], sf_lint_source( "<?php\n// wp_ajax_nopriv_x __return_true\n/** \$wpdb->query( 'x' ) */\n", 'includes/x.php' ) );
		$this->assertSame( [], sf_lint_source( "<?php\n\$a = '__return_true';\n", 'includes/rest/class-rest-public.php' ) );
		$this->assertCount( 1, sf_lint_source( "<?php\n\$a = '__return_true';\n", 'includes/rest/class-rest-other.php' ) );
		$this->assertSame( [], sf_lint_source( "<?php\nadd_action( 'wp_ajax_swiftforms_rest_nonce', 'f' );\n", 'includes/x.php' ) );
	}
}
