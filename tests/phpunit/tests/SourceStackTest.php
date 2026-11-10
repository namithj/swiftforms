<?php // phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Tests apply core's the_content filter and set core's current-template globals.
/**
 * Tests for SwiftForms\Source_Stack.
 *
 * @package SwiftForms
 */

require_once dirname( __DIR__ ) . '/includes/class-sf-resolver-test-case.php';

use SwiftForms\Source_Stack;

/**
 * Class SourceStackTest
 *
 * @group resolver
 */
class SourceStackTest extends SF_Resolver_Test_Case {

	/**
	 * Sources seen by the probe block, in render order.
	 *
	 * @var string[]
	 */
	private $seen = [];

	/**
	 * Markup of the probe block.
	 */
	const PROBE = '<!-- wp:sftest/probe /-->';

	/**
	 * Register a dynamic block that records Source_Stack::current() when it renders.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		$this->seen = [];
		register_block_type(
			'sftest/probe',
			[
				'render_callback' => function () {
					$this->seen[] = (string) Source_Stack::current();
					return '<p>probe</p>';
				},
			]
		);
	}

	/**
	 * Remove the probe block.
	 *
	 * @return void
	 */
	public function tear_down() {
		unregister_block_type( 'sftest/probe' );
		unset( $GLOBALS['_wp_current_template_id'], $GLOBALS['_wp_current_template_content'] );
		parent::tear_down();
	}

	/**
	 * Show a post the way a classic theme does: main loop, the_content.
	 *
	 * @param int $post_id Post ID.
	 * @return string The filtered content.
	 */
	private function show_post( $post_id ) {
		$this->go_to( get_permalink( $post_id ) );
		$this->assertTrue( have_posts() );
		the_post();
		return apply_filters( 'the_content', get_the_content() );
	}

	/**
	 * A form in a page takes the page.
	 *
	 * @return void
	 */
	public function test_page_content_uses_the_page() {
		$page = $this->make_post( self::PROBE );
		$this->show_post( $page );

		$this->assertSame( [ 'post:' . $page ], $this->seen );
		$this->assertSame( 0, Source_Stack::depth() );
	}

	/**
	 * A form in a synced pattern on a page takes the pattern's post; the rest of the page keeps the page.
	 *
	 * @return void
	 */
	public function test_synced_pattern() {
		$synced = $this->make_post( self::PROBE, [ 'post_type' => 'wp_block' ] );
		$page   = $this->make_post( self::PROBE . '<!-- wp:block {"ref":' . $synced . '} /-->' . self::PROBE );
		$this->show_post( $page );

		$this->assertSame( [ 'post:' . $page, 'post:' . $synced, 'post:' . $page ], $this->seen );
		$this->assertSame( 0, Source_Stack::depth() );
	}

	/**
	 * Nested synced patterns stack, and unwind in order.
	 *
	 * @return void
	 */
	public function test_nested_synced_patterns() {
		$inner = $this->make_post( self::PROBE, [ 'post_type' => 'wp_block' ] );
		$outer = $this->make_post( self::PROBE . '<!-- wp:block {"ref":' . $inner . '} /-->' . self::PROBE, [ 'post_type' => 'wp_block' ] );
		$page  = $this->make_post( '<!-- wp:block {"ref":' . $outer . '} /-->' . self::PROBE );
		$this->show_post( $page );

		$this->assertSame( [ 'post:' . $outer, 'post:' . $inner, 'post:' . $outer, 'post:' . $page ], $this->seen );
		$this->assertSame( 0, Source_Stack::depth() );
	}

	/**
	 * A form in a template part, and in a registered pattern, takes that part or pattern.
	 *
	 * @return void
	 */
	public function test_template_part_and_pattern() {
		$this->make_theme( 'sf-s1', [ 'parts/header.html' => self::PROBE ] );
		$this->register_pattern( 'sf/probe', self::PROBE );
		$page = $this->make_post( '<!-- wp:template-part {"slug":"header","theme":"sf-s1"} /-->' . self::PROBE . '<!-- wp:pattern {"slug":"sf/probe"} /-->' );
		$this->show_post( $page );

		$this->assertSame( [ 'template_part:sf-s1//header', 'post:' . $page, 'pattern:sf/probe' ], $this->seen );
		$this->assertSame( 0, Source_Stack::depth() );
	}

	/**
	 * A theme-file template is the base; core/post-content inside it switches to the post; the template part inside takes the part.
	 *
	 * @return void
	 */
	public function test_file_template_with_post_content() {
		global $_wp_current_template_id, $_wp_current_template_content;

		$template = self::PROBE
			. '<!-- wp:template-part {"slug":"header","theme":"sf-s2"} /-->'
			. '<!-- wp:post-content /-->'
			. self::PROBE;
		$this->make_theme(
			'sf-s2',
			[
				'templates/single.html' => $template,
				'parts/header.html'     => self::PROBE,
			]
		);
		$page = $this->make_post( self::PROBE, [ 'post_type' => 'post' ] );

		$this->go_to( get_permalink( $page ) );
		$_wp_current_template_id      = 'sf-s2//single';
		$_wp_current_template_content = $template;
		$html                         = get_the_block_template_html();

		$this->assertStringContainsString( 'probe', $html );
		$this->assertSame(
			[ 'template:sf-s2//single', 'template_part:sf-s2//header', 'post:' . $page, 'template:sf-s2//single' ],
			$this->seen
		);
		$this->assertSame( 0, Source_Stack::depth() );
	}

	/**
	 * A template that is not the base gives the base source back once rendering is done.
	 *
	 * @return void
	 */
	public function test_base_is_the_template_then_the_post() {
		global $_wp_current_template_id;

		$page = $this->make_post( 'x' );
		$this->go_to( get_permalink( $page ) );
		$this->assertSame( 'post:' . $page, Source_Stack::current(), 'Classic theme: the queried post.' );

		$_wp_current_template_id = 'sf-s3//page';
		$this->assertSame( 'template:sf-s3//page', Source_Stack::current() );

		$_wp_current_template_id = '../bad id';
		$this->assertSame( 'post:' . $page, Source_Stack::current() );
	}

	/**
	 * A block widget takes widget:block-{n}.
	 *
	 * @return void
	 */
	public function test_widget() {
		global $wp_widget_factory;

		$this->place_widget( 7, self::PROBE );
		$wp_widget_factory->widgets['WP_Widget_Block']->_register(); // Registers block-7 next to the widgets core registered at init.
		$this->assertTrue( is_registered_sidebar( 'sf-sidebar' ) );

		ob_start();
		dynamic_sidebar( 'sf-sidebar' );
		ob_end_clean();

		$this->assertSame( [ 'widget:block-7' ], $this->seen );
		$this->assertSame( 0, Source_Stack::depth() );
	}

	/**
	 * The same post gives post:ID on a blog index and on its single view.
	 *
	 * @return void
	 */
	public function test_same_post_on_index_and_single() {
		$post  = $this->make_post( self::PROBE, [ 'post_type' => 'post' ] );
		$other = $this->make_post( self::PROBE, [ 'post_type' => 'post' ] );

		$this->go_to( home_url( '/' ) );
		$this->assertTrue( is_home() );
		while ( have_posts() ) {
			the_post();
			apply_filters( 'the_content', get_the_content() );
			$this->assertSame( 'post:' . get_the_ID(), end( $this->seen ) );
		}
		$on_index = $this->seen;
		$this->assertContains( 'post:' . $post, $on_index );
		$this->assertContains( 'post:' . $other, $on_index );

		$this->seen = [];
		$this->show_post( $post );
		$this->assertSame( [ 'post:' . $post ], $this->seen );
		$this->assertSame( 0, Source_Stack::depth() );
	}

	/**
	 * Another pre_render_block callback that short-circuits a block does not leave a frame behind.
	 *
	 * @return void
	 */
	public function test_short_circuiting_pre_render_block_is_balanced() {
		$synced = $this->make_post( self::PROBE, [ 'post_type' => 'wp_block' ] );
		$page   = $this->make_post( '<!-- wp:block {"ref":' . $synced . '} /-->' . self::PROBE . '<!-- wp:pattern {"slug":"sf/none"} /-->' . self::PROBE );

		add_filter(
			'pre_render_block',
			static function ( $pre, $block ) {
				return in_array( $block['blockName'], [ 'core/block', 'core/pattern' ], true ) ? '<!-- cached -->' : $pre;
			},
			10,
			2
		);
		$html = $this->show_post( $page );

		$this->assertStringContainsString( 'cached', $html );
		$this->assertSame( [ 'post:' . $page, 'post:' . $page ], $this->seen, 'The synced pattern never rendered, and the rest still sees the page.' );
		$this->assertSame( 0, Source_Stack::depth() );
	}

	/**
	 * Other render_block filters that render blocks themselves do not unbalance the stack, and they see the block's own source.
	 *
	 * @return void
	 */
	public function test_render_block_filters_that_render_blocks() {
		$this->register_pattern( 'sf/probe', self::PROBE );
		$synced = $this->make_post( self::PROBE, [ 'post_type' => 'wp_block' ] );
		$page   = $this->make_post( '<!-- wp:block {"ref":' . $synced . '} /-->' . self::PROBE );

		$during = [];
		add_filter(
			'render_block',
			static function ( $content, $block ) use ( &$during ) {
				if ( 'core/block' === $block['blockName'] ) {
					$during[] = Source_Stack::current();
					$content .= do_blocks( '<!-- wp:pattern {"slug":"sf/probe"} /-->' );
				}
				return $content;
			},
			20,
			2
		);
		$this->show_post( $page );

		$this->assertSame( 'post:' . $synced, $during[0], 'A later filter still sees the block\'s own source.' );
		// Newer core fires render_block twice for a synced pattern, so the filter's own blocks may render twice.
		$this->assertSame( [ 'post:' . $synced, 'pattern:sf/probe' ], array_slice( $this->seen, 0, 2 ) );
		$this->assertSame( 'post:' . $page, end( $this->seen ) );
		$this->assertSame( 0, Source_Stack::depth() );
	}

	/**
	 * A block reference that cannot be read still gets a frame that repeats the source below, and unwinds.
	 *
	 * @return void
	 */
	public function test_unreadable_references_inherit() {
		$page = $this->make_post( '<!-- wp:block {"ref":"x"} /-->' . self::PROBE . '<!-- wp:template-part /-->' . self::PROBE );
		$this->show_post( $page );

		$this->assertSame( [ 'post:' . $page, 'post:' . $page ], $this->seen );
		$this->assertSame( 0, Source_Stack::depth() );
	}

	/**
	 * Ordinals count per frame, and a repeated signature (a loop iteration) keeps its ordinal.
	 *
	 * @return void
	 */
	public function test_next_ordinal() {
		$page = $this->make_post( 'x' );
		$this->go_to( get_permalink( $page ) );

		$this->assertSame( 1, Source_Stack::next_ordinal( 'a' ) );
		$this->assertSame( 2, Source_Stack::next_ordinal( 'b' ) );
		$this->assertSame( 1, Source_Stack::next_ordinal( 'a' ), 'The same block again (a loop).' );
		$this->assertSame( 3, Source_Stack::next_ordinal() );
		$this->assertSame( 4, Source_Stack::next_ordinal() );

		// Inside a frame the counting starts again.
		Source_Stack::on_pre_render_block(
			null,
			[
				'blockName' => 'core/block',
				'attrs'     => [ 'ref' => 99 ],
			]
		);
		$this->assertSame( 'post:99', Source_Stack::current() );
		$this->assertSame( 1, Source_Stack::next_ordinal( 'a' ) );
		Source_Stack::on_render_block( '', [ 'blockName' => 'core/block' ] );
		$this->assertSame( 0, Source_Stack::depth() );
		$this->assertSame( 2, Source_Stack::next_ordinal( 'b' ) );
	}

	/**
	 * Popping with the wrong name leaves the stack alone.
	 *
	 * @return void
	 */
	public function test_mismatched_pop_is_ignored() {
		Source_Stack::on_pre_render_block(
			null,
			[
				'blockName' => 'core/pattern',
				'attrs'     => [ 'slug' => 'a/b' ],
			]
		);
		Source_Stack::on_render_block( '', [ 'blockName' => 'core/block' ] );
		$this->assertSame( 1, Source_Stack::depth() );
		Source_Stack::on_render_block( '', [ 'blockName' => 'core/pattern' ] );
		$this->assertSame( 0, Source_Stack::depth() );
	}
}
