<?php
/**
 * Scripts, styles and the view module.
 *
 * @package SwiftForms
 */

namespace SwiftForms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Assets
 *
 * Registers SwiftForms' assets and decides where they print (ADR-002):
 *
 * - The front-end stylesheet `swiftforms-form` and the view module `@swiftforms/view` are
 *   attached to the form block with viewStyle / viewScriptModule, never with `style`, which
 *   a classic theme would enqueue on every page. WordPress enqueues them when a form renders,
 *   so a page without a form carries nothing of ours.
 * - A stylesheet that is enqueued while the body renders prints in the footer on a classic
 *   theme. So that it prints in <head> on the pages that matter, prescan() enqueues it on `wp`
 *   for a singular view whose post holds a form (also through a synced pattern it references)
 *   or when an active block widget holds one. A form in a template, part or archive loop
 *   prints its stylesheet when it renders.
 * - `general.load_styles` off: no SwiftForms front-end CSS anywhere. The handle stays
 *   registered (the editor needs it) and is dropped from every front-end print.
 * - Editor: the editor script (toolbar toggle, notices) and the editor stylesheet are
 *   attached with editorScript / editorStyle.
 */
class Assets {

	/**
	 * Front-end stylesheet handle (also loaded in the editor).
	 */
	const STYLE = 'swiftforms-form';

	/**
	 * Editor-only stylesheet handle.
	 */
	const EDITOR_STYLE = 'swiftforms-editor';

	/**
	 * Editor script handle.
	 */
	const EDITOR_SCRIPT = 'swiftforms-editor';

	/**
	 * View script module ID.
	 */
	const VIEW_MODULE = '@swiftforms/view';

	/**
	 * Most synced patterns the prescan looks into.
	 */
	const MAX_PATTERN_REFS = 10;

	/**
	 * Hook the assets.
	 *
	 * @return void
	 */
	public static function init() {
		// Before the block builder registers the blocks (init 5/10): the blocks only name these handles.
		add_action( 'init', [ self::class, 'register' ], 4 );
		add_action( 'wp', [ self::class, 'prescan' ] );
		add_filter( 'print_styles_array', [ self::class, 'filter_print_styles' ] );
	}

	/**
	 * Register the styles, the editor script and the view module.
	 *
	 * @return void
	 */
	public static function register() {
		$form = self::asset_data( 'style/form' );
		wp_register_style( self::STYLE, self::url( 'style/form.css' ), [], $form['version'] );
		wp_style_add_data( self::STYLE, 'rtl', 'replace' );

		$editor_style = self::asset_data( 'style/editor' );
		wp_register_style( self::EDITOR_STYLE, self::url( 'style/editor.css' ), [ self::STYLE ], $editor_style['version'] );
		wp_style_add_data( self::EDITOR_STYLE, 'rtl', 'replace' );

		$script = self::asset_data( 'editor/index' );
		wp_register_script( self::EDITOR_SCRIPT, self::url( 'editor/index.js' ), $script['dependencies'], $script['version'], true );
		wp_set_script_translations( self::EDITOR_SCRIPT, 'swiftforms', plugin_dir_path( SWIFTFORMS_FILE ) . 'languages' );

		$view = self::asset_data( 'view/index' );
		wp_register_script_module( self::VIEW_MODULE, self::url( 'view/index.js' ), $view['dependencies'], $view['version'] );
	}

	/**
	 * Enqueue the stylesheet early (in <head>) when the request will show a form.
	 *
	 * @return void
	 */
	public static function prescan() {
		if ( is_admin() || ! self::styles_enabled() ) {
			return;
		}
		if ( self::queried_post_has_form() || self::widgets_have_form() ) {
			wp_enqueue_style( self::STYLE );
		}
	}

	/**
	 * Drop the front-end stylesheet from a front-end print when the site switched it off.
	 *
	 * @param string[] $handles Handles about to print.
	 * @return string[]
	 */
	public static function filter_print_styles( $handles ) {
		if ( is_admin() || self::styles_enabled() || ! is_array( $handles ) ) {
			return $handles;
		}
		return array_values( array_diff( $handles, [ self::STYLE ] ) );
	}

	/**
	 * Whether the site wants SwiftForms' front-end CSS (Settings: general.load_styles).
	 *
	 * @return bool
	 */
	public static function styles_enabled() {
		return false !== Settings::get_value( 'general.load_styles', true );
	}

	/**
	 * Whether the queried post, or a synced pattern it references, contains a form.
	 *
	 * @return bool
	 */
	private static function queried_post_has_form() {
		if ( ! is_singular() ) {
			return false;
		}
		$post = get_queried_object();
		if ( ! $post instanceof \WP_Post || '' === $post->post_content ) {
			return false;
		}
		if ( has_block( Field_Types::FORM_BLOCK, $post->post_content ) ) {
			return true;
		}

		// Synced patterns: <!-- wp:block {"ref":123} /-->, one level deep.
		if ( false === strpos( $post->post_content, '<!-- wp:block' ) ) {
			return false;
		}
		if ( ! preg_match_all( '/<!--\s+wp:block\s+\{[^}]*"ref":\s*(\d+)/', $post->post_content, $matches ) ) {
			return false;
		}
		foreach ( array_slice( array_unique( $matches[1] ), 0, self::MAX_PATTERN_REFS ) as $ref ) {
			$pattern = get_post( (int) $ref );
			if ( $pattern instanceof \WP_Post && 'wp_block' === $pattern->post_type && 'publish' === $pattern->post_status && has_block( Field_Types::FORM_BLOCK, $pattern->post_content ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether an active block widget contains a form.
	 *
	 * @return bool
	 */
	private static function widgets_have_form() {
		$widgets = get_option( 'widget_block' );
		if ( ! is_array( $widgets ) || [] === $widgets ) {
			return false;
		}
		foreach ( (array) get_option( 'sidebars_widgets', [] ) as $sidebar => $ids ) {
			if ( 'wp_inactive_widgets' === $sidebar || 'array_version' === $sidebar || ! is_array( $ids ) ) {
				continue;
			}
			foreach ( $ids as $id ) {
				if ( 1 !== preg_match( '/^block-(\d+)$/D', (string) $id, $m ) ) {
					continue;
				}
				$widget = $widgets[ (int) $m[1] ] ?? null;
				if ( is_array( $widget ) && isset( $widget['content'] ) && is_string( $widget['content'] ) && has_block( Field_Types::FORM_BLOCK, $widget['content'] ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * URL of a build file.
	 *
	 * @param string $file Path below build/.
	 * @return string
	 */
	private static function url( $file ) {
		return plugins_url( 'build/' . $file, SWIFTFORMS_FILE );
	}

	/**
	 * Dependencies and version from the asset file next to a build entry.
	 *
	 * @param string $entry Entry name, for example "editor/index".
	 * @return array{dependencies: array<int, mixed>, version: string}
	 */
	private static function asset_data( $entry ) {
		$file = plugin_dir_path( SWIFTFORMS_FILE ) . 'build/' . $entry . '.asset.php';
		$data = is_readable( $file ) ? include $file : [];

		return [
			'dependencies' => isset( $data['dependencies'] ) && is_array( $data['dependencies'] ) ? $data['dependencies'] : [],
			'version'      => isset( $data['version'] ) && is_string( $data['version'] ) ? $data['version'] : SWIFTFORMS_VERSION,
		];
	}
}
