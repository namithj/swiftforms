<?php // phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Tests fire core's front-end hooks and reset core's style globals.
/**
 * Tests for SwiftForms\Assets: where the stylesheet, the view module and the editor assets load.
 *
 * Pages without a form carry nothing of ours (block and classic theme). A form page prints the
 * stylesheet in the head when the request is a singular view that holds the form (directly, through
 * a synced pattern, or in a block widget) and in the footer everywhere else. general.load_styles off
 * removes every front-end stylesheet and leaves the editor alone.
 *
 * @package SwiftForms
 */

require_once dirname( __DIR__ ) . '/includes/class-sf-render-test-case.php';

use SwiftForms\Assets;

/**
 * Class AssetsTest
 *
 * @group blocks
 * @group assets
 */
class AssetsTest extends SF_Render_Test_Case {

	/**
	 * Theme directories before the test.
	 *
	 * @var array
	 */
	private $theme_dirs = [];

	/**
	 * Fresh style and script queues, our assets registered again.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		$this->theme_dirs = isset( $GLOBALS['wp_theme_directories'] ) ? (array) $GLOBALS['wp_theme_directories'] : [];

		$GLOBALS['wp_styles']  = new WP_Styles();
		$GLOBALS['wp_scripts'] = new WP_Scripts();
		wp_dequeue_script_module( Assets::VIEW_MODULE );
		Assets::register();
		wp_set_current_user( 0 );
	}

	/**
	 * Restore the themes and the queues.
	 *
	 * @return void
	 */
	public function tear_down() {
		wp_dequeue_script_module( Assets::VIEW_MODULE );
		unset( $GLOBALS['current_screen'] );
		$GLOBALS['wp_theme_directories'] = $this->theme_dirs;
		wp_clean_themes_cache();
		parent::tear_down();
	}

	/**
	 * Output of an action.
	 *
	 * @param string $action Action name.
	 * @return string
	 */
	private function printed( $action ) {
		ob_start();
		do_action( $action ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.
		return (string) ob_get_clean();
	}

	/**
	 * Whether the SwiftForms stylesheet is in an output.
	 *
	 * @param string $html Output.
	 * @return bool
	 */
	private function has_style( $html ) {
		return false !== strpos( $html, "id='swiftforms-form-css'" ) || false !== strpos( $html, 'id="swiftforms-form-css"' );
	}

	/**
	 * Anything of ours (style, script or module) in an output.
	 *
	 * @param string $html Output.
	 * @return bool
	 */
	private function has_any( $html ) {
		return 1 === preg_match( '#(id=[\'"]swiftforms|@swiftforms/view|/swiftforms/build/)#', $html );
	}

	/**
	 * Use the block fixture theme or the classic fixture theme.
	 *
	 * @param string $slug sf-test-block or sf-test-classic.
	 * @return void
	 */
	private function use_theme( $slug ) {
		register_theme_directory( dirname( __DIR__, 2 ) . '/user-configs/themes' );
		wp_clean_themes_cache();
		switch_theme( $slug );
	}

	/**
	 * Everything is registered, with the RTL variant.
	 *
	 * @return void
	 */
	public function test_registration() {
		$this->assertTrue( wp_style_is( Assets::STYLE, 'registered' ) );
		$this->assertTrue( wp_style_is( Assets::EDITOR_STYLE, 'registered' ) );
		$this->assertTrue( wp_script_is( Assets::EDITOR_SCRIPT, 'registered' ) );
		$this->assertSame( 'replace', wp_styles()->registered[ Assets::STYLE ]->extra['rtl'] );
		$this->assertSame( 'replace', wp_styles()->registered[ Assets::EDITOR_STYLE ]->extra['rtl'] );
		$this->assertStringEndsWith( 'build/style/form.css', wp_styles()->registered[ Assets::STYLE ]->src );
		$this->assertSame( [ Assets::STYLE ], wp_styles()->registered[ Assets::EDITOR_STYLE ]->deps );
		$this->assertFalse( wp_styles()->registered[ Assets::STYLE ]->args === 'print', 'Not a print-only stylesheet.' );

		ob_start();
		wp_script_modules()->print_enqueued_script_modules();
		$this->assertStringNotContainsString( 'swiftforms', (string) ob_get_clean(), 'Registering a module prints nothing.' );
	}

	/**
	 * A page without a form: nothing of ours, on a block theme and on a classic theme.
	 *
	 * @return void
	 */
	public function test_pages_without_a_form_load_nothing() {
		foreach ( [ 'sf-test-block', 'sf-test-classic' ] as $theme ) {
			$GLOBALS['wp_styles']  = new WP_Styles();
			$GLOBALS['wp_scripts'] = new WP_Scripts();
			Assets::register();
			$this->use_theme( $theme );

			$post_id = $this->make_post( '<!-- wp:paragraph --><p>No form here</p><!-- /wp:paragraph -->', [ 'post_title' => $theme ] );
			$this->go_to( get_permalink( $post_id ) );
			$this->render_current();

			$out = $this->printed( 'wp_head' ) . $this->printed( 'wp_footer' );
			$this->assertFalse( $this->has_any( $out ), $theme . ' prints no SwiftForms asset.' );
			$this->assertSame( [], array_filter( wp_styles()->queue, [ $this, 'is_ours' ] ), $theme );
			$this->assertSame( [], array_filter( wp_scripts()->queue, [ $this, 'is_ours' ] ), $theme );
		}
	}

	/**
	 * Whether the view module is in the module queue.
	 *
	 * @return bool
	 */
	private function module_queued() {
		$modules = wp_script_modules();
		if ( method_exists( $modules, 'get_queue' ) ) {
			$queue = $modules->get_queue();
		} else {
			// WordPress 6.8 has no public getter.
			$property = new ReflectionProperty( $modules, 'registered' );
			$property->setAccessible( true );
			$queue = [];
			foreach ( $property->getValue( $modules ) as $id => $module ) {
				if ( ! empty( $module['enqueue'] ) ) {
					$queue[] = $id;
				}
			}
		}
		return in_array( Assets::VIEW_MODULE, array_keys( $queue ), true ) || in_array( Assets::VIEW_MODULE, $queue, true );
	}

	/**
	 * Array filter: a handle of ours.
	 *
	 * @param string $handle Handle.
	 * @return bool
	 */
	public function is_ours( $handle ) {
		return 0 === strpos( $handle, 'swiftforms' );
	}

	/**
	 * A singular view that holds a form prints the stylesheet in the head, once, and the module with the render.
	 *
	 * @return void
	 */
	public function test_singular_view_prints_the_style_in_the_head() {
		foreach ( [ 'sf-test-block', 'sf-test-classic' ] as $theme ) {
			$GLOBALS['wp_styles']  = new WP_Styles();
			$GLOBALS['wp_scripts'] = new WP_Scripts();
			wp_dequeue_script_module( Assets::VIEW_MODULE );
			Assets::register();
			$this->use_theme( $theme );

			$post_id = $this->make_post(
				$this->form_markup( self::ID_A, $this->field_markup( 'text', [ 'name' => 'a' ] ) ),
				[ 'post_title' => $theme ]
			);
			$this->go_to( get_permalink( $post_id ) );

			$head = $this->printed( 'wp_head' );
			$this->assertTrue( $this->has_style( $head ), $theme . ': the stylesheet is in the head.' );
			$this->assertSame( 1, substr_count( $head, "id='swiftforms-form-css'" ), $theme . ': once.' );

			$this->assertStringContainsString( '<form', $this->render_current() );
			$footer = $this->printed( 'wp_footer' );
			$this->assertFalse( $this->has_style( $footer ), $theme . ': not again in the footer.' );
			$this->assertTrue( $this->module_queued(), $theme . ': the view module is enqueued by the render.' );
		}
	}

	/**
	 * The module is not enqueued before a form renders.
	 *
	 * @return void
	 */
	public function test_view_module_loads_only_when_a_form_renders() {
		$post_id = $this->make_post( $this->form_markup( self::ID_A, '' ) );
		$this->go_to( get_permalink( $post_id ) );
		$this->printed( 'wp_head' );
		$this->assertFalse( $this->module_queued() );

		$this->render_current();
		$this->assertTrue( $this->module_queued() );
	}

	/**
	 * A form inside a synced pattern that the page references is found by the pre-scan.
	 *
	 * @return void
	 */
	public function test_synced_pattern_is_prescanned() {
		$pattern = $this->make_post(
			$this->form_markup( self::ID_B, '' ),
			[
				'post_type' => 'wp_block',
			]
		);
		$post_id = $this->make_post( '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph --><!-- wp:block {"ref":' . $pattern . '} /-->' );
		$this->go_to( get_permalink( $post_id ) );

		$this->assertTrue( $this->has_style( $this->printed( 'wp_head' ) ) );
	}

	/**
	 * A synced pattern without a form does not load the stylesheet.
	 *
	 * @return void
	 */
	public function test_synced_pattern_without_a_form() {
		$pattern = $this->make_post( '<!-- wp:paragraph --><p>plain</p><!-- /wp:paragraph -->', [ 'post_type' => 'wp_block' ] );
		$post_id = $this->make_post( '<!-- wp:block {"ref":' . $pattern . '} /-->' );
		$this->go_to( get_permalink( $post_id ) );

		$out = $this->printed( 'wp_head' );
		$this->render_current();
		$this->assertFalse( $this->has_any( $out . $this->printed( 'wp_footer' ) ) );
	}

	/**
	 * An active block widget with a form is found by the pre-scan.
	 *
	 * @return void
	 */
	public function test_block_widget_is_prescanned() {
		$this->place_widget( 2, $this->form_markup( self::ID_C, '' ) );
		$this->go_to( home_url( '/' ) );

		$this->assertTrue( $this->has_style( $this->printed( 'wp_head' ) ) );
	}

	/**
	 * A form in an inactive widget is not.
	 *
	 * @return void
	 */
	public function test_inactive_widget_is_not_prescanned() {
		$this->place_widget( 2, $this->form_markup( self::ID_C, '' ), 'wp_inactive_widgets' );
		$this->go_to( home_url( '/' ) );

		$this->assertFalse( $this->has_any( $this->printed( 'wp_head' ) ) );
	}

	/**
	 * Elsewhere (an archive, a template) the stylesheet prints when the form renders: in the footer.
	 *
	 * @return void
	 */
	public function test_other_views_print_the_style_in_the_footer() {
		$this->use_theme( 'sf-test-classic' );
		$this->make_post( 'x', [ 'post_title' => 'Archive entry' ] );
		$this->go_to( home_url( '/' ) );

		$head = $this->printed( 'wp_head' );
		$this->assertFalse( $this->has_style( $head ) );

		$html = do_blocks( $this->form_markup( self::ID_A, $this->field_markup( 'text', [ 'name' => 'a' ] ) ) );
		$this->assertStringContainsString( '<form', $html );

		$footer = $this->printed( 'wp_footer' );
		$this->assertTrue( $this->has_style( $footer ), 'The stylesheet prints in the footer.' );
	}

	/**
	 * general.load_styles off: no SwiftForms front-end CSS in the head, in the footer or through the pre-scan.
	 *
	 * @return void
	 */
	public function test_load_styles_off() {
		update_option( 'swiftforms_general_load_styles', '0' );
		$this->assertFalse( Assets::styles_enabled() );

		$post_id = $this->make_post( $this->form_markup( self::ID_A, $this->field_markup( 'text', [ 'name' => 'a' ] ) ) );
		$this->go_to( get_permalink( $post_id ) );
		$head = $this->printed( 'wp_head' );
		$this->assertFalse( $this->has_style( $head ), 'Not in the head.' );
		$this->assertNotContains( Assets::STYLE, wp_styles()->queue, 'Not queued by the pre-scan.' );

		$this->assertStringContainsString( '<form', $this->render_current() );
		$this->assertContains( Assets::STYLE, wp_styles()->queue, 'WordPress still queues the view style of the block.' );
		$this->assertFalse( $this->has_style( $this->printed( 'wp_footer' ) ), 'But it never prints.' );

		// The editor keeps it.
		set_current_screen( 'edit-post' );
		$this->assertSame( [ Assets::STYLE ], Assets::filter_print_styles( [ Assets::STYLE ] ) );
		unset( $GLOBALS['current_screen'] );
		set_current_screen( 'front' );
		$this->assertSame( [], Assets::filter_print_styles( [ Assets::STYLE ] ) );
		$this->assertSame( [ 'other' ], Assets::filter_print_styles( [ Assets::STYLE, 'other' ] ) );
		$this->assertTrue( wp_style_is( Assets::STYLE, 'registered' ), 'Still registered for the editor.' );
	}

	/**
	 * load_styles on (the default): the filter lets everything through.
	 *
	 * @return void
	 */
	public function test_load_styles_default_is_on() {
		$this->assertTrue( Assets::styles_enabled() );
		$this->assertSame( [ Assets::STYLE, 'other' ], Assets::filter_print_styles( [ Assets::STYLE, 'other' ] ) );
	}

	/**
	 * The stylesheet is far below the budget and the editor script is a classic script with an asset file.
	 *
	 * @return void
	 */
	public function test_build_files_exist() {
		$root = dirname( __DIR__, 3 ) . '/build/';
		if ( ! is_readable( $root . 'style/form.css' ) ) {
			$this->markTestSkipped( 'Run npm run build first.' );
		}
		$this->assertLessThan( 4096, strlen( gzencode( (string) file_get_contents( $root . 'style/form.css' ), 9 ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local build file.
		$this->assertFileExists( $root . 'style/form-rtl.css' );
		$this->assertFileExists( $root . 'editor/index.js' );
		$this->assertFileExists( $root . 'editor/index.asset.php' );
		$this->assertFileExists( $root . 'view/index.js' );
		$this->assertFileExists( $root . 'view/index.asset.php' );
	}
}
