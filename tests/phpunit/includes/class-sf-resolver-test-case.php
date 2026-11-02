<?php // phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Test helper: the class name follows SF_Storage_Test_Case, and tests reset core globals on purpose.
/**
 * Base class for the resolver, form index and source stack tests.
 *
 * Builds throwaway block themes in the system temp directory (templates, parts and
 * file patterns), creates pages and synced patterns, and clears the per-request state
 * of the index classes. The SwiftForms tables are the real ones of the test site; the
 * core test case rolls their rows back.
 *
 * @package SwiftForms
 */

require_once __DIR__ . '/class-sf-storage-test-case.php';

use SwiftForms\Form_Index;
use SwiftForms\Source_Edges;
use SwiftForms\Source_Stack;

/**
 * Class SF_Resolver_Test_Case
 */
abstract class SF_Resolver_Test_Case extends SF_Storage_Test_Case {

	/**
	 * Theme root created by make_theme(), or an empty string.
	 *
	 * @var string
	 */
	private $theme_root = '';

	/**
	 * Theme directories before the test.
	 *
	 * @var array
	 */
	private $theme_dirs_before = [];

	/**
	 * Patterns registered by register_pattern(), unregistered again in tear_down().
	 *
	 * @var string[]
	 */
	private $patterns = [];

	/**
	 * Sidebars registered by place_widget(), unregistered again in tear_down().
	 *
	 * @var string[]
	 */
	private $sidebars = [];

	/**
	 * Whether place_widget() already emptied the default widgets in this test.
	 *
	 * @var bool
	 */
	private $widgets_reset = false;

	/**
	 * Fixed formIds for readable tests.
	 */
	const ID_A = '11111111-1111-4111-8111-111111111111';
	const ID_B = '22222222-2222-4222-8222-222222222222';
	const ID_C = '33333333-3333-4333-8333-333333333333';

	/**
	 * Start from empty per-request state.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		$this->theme_dirs_before = isset( $GLOBALS['wp_theme_directories'] ) ? (array) $GLOBALS['wp_theme_directories'] : [];
		$this->widgets_reset     = false;
		Form_Index::flush_cache();
		Source_Edges::flush_cache();
		Source_Stack::reset();
	}

	/**
	 * Remove the temporary themes and reset the state.
	 *
	 * @return void
	 */
	public function tear_down() {
		Source_Stack::reset();
		Form_Index::flush_cache();
		foreach ( $this->patterns as $name ) {
			unregister_block_pattern( $name );
		}
		$this->patterns = [];
		foreach ( $this->sidebars as $sidebar ) {
			unregister_sidebar( $sidebar );
		}
		$this->sidebars = [];
		if ( '' !== $this->theme_root ) {
			$this->remove_dir( $this->theme_root );
			$this->theme_root                = '';
			$GLOBALS['wp_theme_directories'] = $this->theme_dirs_before;
			wp_clean_themes_cache();
		}
		parent::tear_down();
	}

	/**
	 * Create a block theme from a map of relative paths to file contents and optionally activate it.
	 *
	 * @param string               $slug     Theme directory name.
	 * @param array<string,string> $files    Path => contents (templates/index.html is added when missing).
	 * @param bool                 $activate Whether to switch to it.
	 * @return void
	 */
	protected function make_theme( $slug, array $files = [], $activate = true ) {
		if ( '' === $this->theme_root ) {
			$this->theme_root = sys_get_temp_dir() . '/sf-themes-' . getmypid() . '-' . wp_generate_password( 6, false );
			mkdir( $this->theme_root, 0777, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
		}
		$files += [
			'style.css'            => "/*\nTheme Name: {$slug}\nVersion: 1.0\n*/\n",
			'templates/index.html' => '<!-- wp:paragraph --><p>index</p><!-- /wp:paragraph -->',
		];
		foreach ( $files as $path => $contents ) {
			$full = $this->theme_root . '/' . $slug . '/' . $path;
			if ( ! is_dir( dirname( $full ) ) ) {
				mkdir( dirname( $full ), 0777, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
			}
			file_put_contents( $full, $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		register_theme_directory( $this->theme_root );
		wp_clean_themes_cache();
		if ( $activate ) {
			switch_theme( $slug );
		}
	}

	/**
	 * Create a post with raw content (slashed for wp_insert_post).
	 *
	 * @param string $content Block markup.
	 * @param array  $args    Post fields (post_status defaults to publish, post_type to page).
	 * @return int Post ID.
	 */
	protected function make_post( $content, array $args = [] ) {
		$id = wp_insert_post(
			wp_slash(
				array_merge(
					[
						'post_type'    => 'page',
						'post_status'  => 'publish',
						'post_title'   => 'Test page',
						'post_content' => $content,
					],
					$args
				)
			),
			true
		);
		$this->assertIsInt( $id );
		return $id;
	}

	/**
	 * Create a database template or template part for a theme (as the Site Editor saves it).
	 *
	 * @param string $post_type  wp_template or wp_template_part.
	 * @param string $theme      Theme stylesheet.
	 * @param string $slug       Slug.
	 * @param string $content    Block markup.
	 * @param string $status     Post status.
	 * @return int Post ID.
	 */
	protected function make_template_post( $post_type, $theme, $slug, $content, $status = 'publish' ) {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		return $this->make_post(
			$content,
			[
				'post_type'   => $post_type,
				'post_name'   => $slug,
				'post_title'  => $slug,
				'post_status' => $status,
				'tax_input'   => [ 'wp_theme' => [ $theme ] ],
			]
		);
	}

	/**
	 * Register a block pattern for the test.
	 *
	 * @param string $name    Pattern name.
	 * @param string $content Block markup.
	 * @return void
	 */
	protected function register_pattern( $name, $content ) {
		register_block_pattern(
			$name,
			[
				'title'   => $name,
				'content' => $content,
			]
		);
		$this->patterns[] = $name;
	}

	/**
	 * Unregister a pattern (registered by the code under test) when the test ends.
	 *
	 * @param string $name Pattern name.
	 * @return void
	 */
	protected function register_pattern_cleanup( $name ) {
		$this->patterns[] = $name;
	}

	/**
	 * Create a block widget and put it in a sidebar (or leave it in the inactive area).
	 *
	 * @param int    $number  Widget number.
	 * @param string $content Block markup.
	 * @param string $sidebar Sidebar ID, or wp_inactive_widgets.
	 * @return void
	 */
	protected function place_widget( $number, $content, $sidebar = 'sf-sidebar' ) {
		global $wp_registered_sidebars, $_wp_sidebars_widgets;

		if ( ! $this->widgets_reset ) {
			// The test site ships default block widgets in sidebar-1/2; start from nothing.
			$this->widgets_reset = true;
			update_option( 'widget_block', [ '_multiwidget' => 1 ] );
			update_option(
				'sidebars_widgets',
				[
					'wp_inactive_widgets' => [],
					'array_version'       => 3,
				]
			);
		}

		if ( 'wp_inactive_widgets' !== $sidebar && ! isset( $wp_registered_sidebars[ $sidebar ] ) ) {
			register_sidebar(
				[
					'id'   => $sidebar,
					'name' => $sidebar,
				]
			);
			$this->sidebars[] = $sidebar;
		}

		$widgets            = (array) get_option( 'widget_block', [] );
		$widgets[ $number ] = [ 'content' => $content ];
		$widgets            = $widgets + [ '_multiwidget' => 1 ];
		update_option( 'widget_block', $widgets );

		$sidebars             = (array) get_option( 'sidebars_widgets', [] );
		$sidebars[ $sidebar ] = array_merge( (array) ( $sidebars[ $sidebar ] ?? [] ), [ 'block-' . $number ] );
		update_option( 'sidebars_widgets', $sidebars );
		$_wp_sidebars_widgets = null;
	}

	/**
	 * Markup of an empty form block with a formId (and optional extra attributes and inner blocks).
	 *
	 * @param string $form_id Stored formId, or an empty string for none.
	 * @param string $inner   Inner block markup.
	 * @param array  $attrs   Extra attributes.
	 * @return string
	 */
	protected function form_markup( $form_id, $inner = '', array $attrs = [] ) {
		if ( '' !== $form_id ) {
			$attrs = array_merge( [ 'formId' => $form_id ], $attrs );
		}
		$json = $attrs ? ' ' . wp_json_encode( $attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) : '';
		return '<!-- wp:swiftforms/form' . $json . ' -->' . $inner . '<!-- /wp:swiftforms/form -->';
	}

	/**
	 * Markup of a self-closing field block.
	 *
	 * @param string $type  Field type.
	 * @param array  $attrs Attributes.
	 * @return string
	 */
	protected function field_markup( $type, array $attrs = [] ) {
		$json = $attrs ? ' ' . wp_json_encode( $attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) : '';
		return '<!-- wp:swiftforms/field-' . $type . $json . ' /-->';
	}

	/**
	 * Raw stored post_content.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	protected function stored_content( $post_id ) {
		global $wpdb;
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT post_content FROM {$wpdb->posts} WHERE ID = %d", $post_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Index row by effective ID, straight from the table.
	 *
	 * @param string $form_id Effective formId.
	 * @return array|null
	 */
	protected function row( $form_id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM `' . SwiftForms\Database::table( 'forms' ) . '` WHERE form_id = %s', $form_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Test read.
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Remove a directory tree.
	 *
	 * @param string $dir Directory.
	 * @return void
	 */
	private function remove_dir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST ) as $item ) {
			if ( $item->isDir() ) {
				rmdir( $item->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
			} else {
				unlink( $item->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			}
		}
		rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	}
}
