<?php
/**
 * Tests for BlockForms\Source_Loader.
 *
 * @package BlockForms
 */

require_once dirname( __DIR__ ) . '/includes/class-bf-resolver-test-case.php';

use BlockForms\Source_Loader;

/**
 * Class SourceLoaderTest
 *
 * @group resolver
 */
class SourceLoaderTest extends BF_Resolver_Test_Case {

	/**
	 * Posts: blocks parsed, state describes status, viewability, password and date.
	 *
	 * @return void
	 */
	public function test_posts() {
		$page = $this->make_post( $this->form_markup( self::ID_A ), [ 'post_password' => 'secret' ] );

		$loaded = Source_Loader::load( 'post:' . $page );
		$this->assertSame( 'post:' . $page, $loaded['ref'] );
		$this->assertSame( 'blockforms/form', $loaded['blocks'][0]['blockName'] );
		$state = $loaded['state'];
		$this->assertSame( 'post', $state['kind'] );
		$this->assertSame( 'page', $state['post_type'] );
		$this->assertSame( 'publish', $state['status'] );
		$this->assertTrue( $state['viewable'] );
		$this->assertTrue( $state['password'] );
		$this->assertNotSame( '', $state['date_gmt'] );
		$this->assertSame( $page, $state['post_id'] );

		$draft = $this->make_post( 'x', [ 'post_status' => 'draft' ] );
		$this->assertFalse( Source_Loader::load( 'post:' . $draft )['state']['viewable'] );
		$private = $this->make_post( 'x', [ 'post_status' => 'private' ] );
		$this->assertFalse( Source_Loader::load( 'post:' . $private )['state']['viewable'] );
		$this->assertFalse( Source_Loader::load( 'post:' . $private )['state']['password'] );

		$block = $this->make_post( 'x', [ 'post_type' => 'wp_block' ] );
		$this->assertSame( 'wp_block', Source_Loader::load( 'post:' . $block )['state']['post_type'] );
		$this->assertFalse( Source_Loader::load( 'post:' . $block )['state']['viewable'] );

		$this->assertNull( Source_Loader::load( 'post:999999' ) );
		$this->assertNull( Source_Loader::load( 'post:abc' ) );
		$this->assertNull( Source_Loader::load( 'nonsense' ) );
		$this->assertNull( Source_Loader::state( 'post:999999' ) );
		$this->assertSame( 'publish', Source_Loader::state( 'post:' . $page )['status'] );
	}

	/**
	 * Revisions are never a source.
	 *
	 * @return void
	 */
	public function test_revisions_are_not_sources() {
		$page     = $this->make_post( 'one' );
		$revision = wp_save_post_revision( $page );
		if ( $revision ) {
			$this->assertNull( Source_Loader::load( 'post:' . $revision ) );
		}
		$this->assertTrue( true );
	}

	/**
	 * Theme-file templates and parts load from the active theme; another theme's do not.
	 *
	 * @return void
	 */
	public function test_file_templates_and_parts() {
		$this->make_theme(
			'bf-t1',
			[
				'templates/single.html' => '<!-- wp:group --><div class="wp-block-group"></div><!-- /wp:group -->' . $this->form_markup( self::ID_A ),
				'parts/header.html'     => '<!-- wp:site-title /-->',
			]
		);

		$template = Source_Loader::load( 'template:bf-t1//single' );
		$this->assertSame( 'template', $template['state']['kind'] );
		$this->assertSame( 'file', $template['state']['origin'] );
		$this->assertTrue( $template['state']['active'] );
		$names = wp_list_pluck( $template['blocks'], 'blockName' );
		$this->assertContains( 'blockforms/form', $names );

		$part = Source_Loader::load( 'template_part:bf-t1//header' );
		$this->assertSame( 'template_part', $part['state']['kind'] );
		$this->assertSame( 'core/site-title', $part['blocks'][0]['blockName'] );

		$this->assertNull( Source_Loader::load( 'template:bf-t1//missing' ) );
		$this->assertNull( Source_Loader::load( 'template:other-theme//single' ) );
		$this->assertTrue( Source_Loader::is_active_theme_template( 'template:bf-t1//single' ) );
		$this->assertFalse( Source_Loader::is_active_theme_template( 'template:other-theme//single' ) );
		$this->assertFalse( Source_Loader::is_active_theme_template( 'post:1' ) );
	}

	/**
	 * A database template wins over the file, and a template saved for an inactive theme does not load.
	 *
	 * @return void
	 */
	public function test_database_templates() {
		$this->make_theme( 'bf-t2', [ 'templates/single.html' => '<!-- wp:paragraph --><p>file</p><!-- /wp:paragraph -->' ] );
		$this->make_template_post( 'wp_template', 'bf-t2', 'single', $this->form_markup( self::ID_A ) );
		$this->make_template_post( 'wp_template_part', 'bf-t2', 'footer', $this->form_markup( self::ID_B ) );
		$this->make_template_post( 'wp_template', 'bf-old', 'single', $this->form_markup( self::ID_C ) );

		$template = Source_Loader::load( 'template:bf-t2//single' );
		$this->assertSame( 'db', $template['state']['origin'] );
		$this->assertSame( 'blockforms/form', $template['blocks'][0]['blockName'] );
		$this->assertSame( 'db', Source_Loader::load( 'template_part:bf-t2//footer' )['state']['origin'] );
		$this->assertNull( Source_Loader::load( 'template:bf-old//single' ), 'A template of an inactive theme is not a source.' );
	}

	/**
	 * Registered patterns load; unregistered names do not.
	 *
	 * @return void
	 */
	public function test_patterns() {
		$this->register_pattern( 'bf/hero', $this->form_markup( self::ID_A ) );

		$loaded = Source_Loader::load( 'pattern:bf/hero' );
		$this->assertSame( 'pattern', $loaded['state']['kind'] );
		$this->assertSame( 'registry', $loaded['state']['origin'] );
		$this->assertSame( 'blockforms/form', $loaded['blocks'][0]['blockName'] );
		$this->assertNotNull( Source_Loader::state( 'pattern:bf/hero' ) );
		$this->assertNull( Source_Loader::load( 'pattern:bf/none' ) );
		$this->assertNull( Source_Loader::state( 'pattern:bf/none' ) );
	}

	/**
	 * A pattern in the active theme's patterns folder (a file) loads.
	 *
	 * @return void
	 */
	public function test_file_pattern() {
		$this->make_theme(
			'bf-t3',
			[
				'patterns/contact.php' => "<?php\n/**\n * Title: Contact\n * Slug: bf-t3/contact\n * Inserter: no\n */\n?>\n" . $this->form_markup( self::ID_A ),
			]
		);
		_register_theme_block_patterns();
		$this->register_pattern_cleanup( 'bf-t3/contact' );

		$loaded = Source_Loader::load( 'pattern:bf-t3/contact' );
		$this->assertNotNull( $loaded );
		$this->assertSame( 'blockforms/form', $loaded['blocks'][0]['blockName'] );
	}

	/**
	 * Widgets load when the entry exists; "active" only for a registered, non-inactive sidebar.
	 *
	 * @return void
	 */
	public function test_widgets() {
		$this->place_widget( 3, $this->form_markup( self::ID_A ), 'bf-sidebar' );
		$this->place_widget( 4, $this->form_markup( self::ID_B ), 'wp_inactive_widgets' );

		$active = Source_Loader::load( 'widget:block-3' );
		$this->assertSame( 'widget', $active['state']['kind'] );
		$this->assertTrue( $active['state']['active'] );
		$this->assertSame( 'blockforms/form', $active['blocks'][0]['blockName'] );

		$this->assertFalse( Source_Loader::load( 'widget:block-4' )['state']['active'] );
		$this->assertNull( Source_Loader::load( 'widget:block-99' ) );

		// A sidebar that is not registered does not count either.
		update_option( 'sidebars_widgets', [ 'ghost-sidebar' => [ 'block-3' ] ] );
		$GLOBALS['_wp_sidebars_widgets'] = null; // Core caches the placement for the request.
		$this->assertFalse( Source_Loader::load( 'widget:block-3' )['state']['active'] );
	}
}
