<?php
/**
 * Custom post type and taxonomy registration.
 *
 * @package SwiftForms
 */

declare(strict_types=1);

namespace SwiftForms;

use Pedalcms\CassetteCmf\Core\Manager;

/**
 * Registers the two post types SwiftForms is built on — `swf_form` (the
 * block-editor form builder) and `swf_entry` (one post per submission) —
 * plus the `swf_entry_form` taxonomy that links each entry back to its
 * source form, all through Cassette-CMF. Per-form meta (settings, design
 * overrides) is registered field-by-field by Cassette-CMF itself — see
 * Settings\FormSettingsMetabox — not here. The taxonomy's admin column and
 * `restrict_manage_posts` dropdown (render_entry_form_filter()) are what
 * give the Entries list its "filter by form" control — entries otherwise
 * use WordPress's own list table and Custom Fields metabox, no bespoke
 * admin screen.
 */
final class PostTypes implements Registrable {

	public const FORM_POST_TYPE      = 'swf_form';
	public const ENTRY_POST_TYPE     = 'swf_entry';
	public const ENTRY_FORM_TAXONOMY = 'swf_entry_form';

	public function register(): void {
		add_action( 'init', array( $this, 'register_post_types' ) );
		add_action( 'save_post_' . self::FORM_POST_TYPE, array( $this, 'sync_form_term_name' ) );
		add_action( 'restrict_manage_posts', array( $this, 'render_entry_form_filter' ) );
	}

	/**
	 * Registers both post types and the entry↔form taxonomy with
	 * Cassette-CMF. Also called directly (unhooked) from TestCase::set_up()
	 * between tests — Cassette-CMF no-ops the registration once a post type
	 * or taxonomy already exists, so repeat calls are safe.
	 */
	public function register_post_types(): void {
		Manager::init()->register_from_array(
			array(
				'cpts'       => array(
					array(
						'id'   => self::FORM_POST_TYPE,
						'args' => $this->form_args(),
					),
					array(
						'id'   => self::ENTRY_POST_TYPE,
						'args' => $this->entry_args(),
					),
				),
				'taxonomies' => array(
					array(
						'id'          => self::ENTRY_FORM_TAXONOMY,
						'object_type' => array( self::ENTRY_POST_TYPE ),
						'args'        => $this->entry_form_taxonomy_args(),
					),
				),
			)
		);
	}

	/**
	 * Args for the `swf_form` post type (the block-editor form builder).
	 *
	 * @return array<string, mixed>
	 */
	private function form_args(): array {
		$labels = array(
			'name'               => __( 'Forms', 'swiftforms' ),
			'singular_name'      => __( 'Form', 'swiftforms' ),
			'add_new'            => __( 'Add New', 'swiftforms' ),
			'add_new_item'       => __( 'Add New Form', 'swiftforms' ),
			'edit_item'          => __( 'Edit Form', 'swiftforms' ),
			'new_item'           => __( 'New Form', 'swiftforms' ),
			'view_item'          => __( 'View Form', 'swiftforms' ),
			'search_items'       => __( 'Search Forms', 'swiftforms' ),
			'not_found'          => __( 'No forms found.', 'swiftforms' ),
			'not_found_in_trash' => __( 'No forms found in Trash.', 'swiftforms' ),
			'all_items'          => __( 'All Forms', 'swiftforms' ),
			'menu_name'          => __( 'SwiftForms', 'swiftforms' ),
		);

		return array(
			'labels'          => $labels,
			'public'          => false,
			'show_ui'         => true,
			'show_in_menu'    => false, // Admin\Menu builds a custom top-level menu.
			'show_in_rest'    => true,
			'rest_base'       => 'swf-forms',
			'menu_icon'       => 'dashicons-feedback',
			'supports'        => array( 'title', 'editor', 'custom-fields' ),
			'map_meta_cap'    => true,
			'capability_type' => 'post',
			'has_archive'     => false,
			'rewrite'         => false,
			'query_var'       => false,
		);
	}

	/**
	 * Args for the `swf_entry` post type — a plain CPT with its own list
	 * table (nested under the SwiftForms menu via `show_in_menu`) and the
	 * built-in Custom Fields metabox for viewing a submission's field
	 * values; no dedicated admin screen or REST controller.
	 *
	 * @return array<string, mixed>
	 */
	private function entry_args(): array {
		$labels = array(
			'name'               => __( 'Entries', 'swiftforms' ),
			'singular_name'      => __( 'Entry', 'swiftforms' ),
			'all_items'          => __( 'Entries', 'swiftforms' ),
			'view_item'          => __( 'View Entry', 'swiftforms' ),
			'search_items'       => __( 'Search Entries', 'swiftforms' ),
			'not_found'          => __( 'No entries found.', 'swiftforms' ),
			'not_found_in_trash' => __( 'No entries found in Trash.', 'swiftforms' ),
		);

		return array(
			'labels'             => $labels,
			'public'             => false,
			'publicly_queryable' => false,
			'show_ui'            => true,
			'show_in_menu'       => 'edit.php?post_type=' . self::FORM_POST_TYPE,
			'show_in_rest'       => false,
			'supports'           => array( 'title', 'custom-fields' ),
			'map_meta_cap'       => true,
			'capability_type'    => 'post',
			'has_archive'        => false,
			'rewrite'            => false,
			'query_var'          => false,
		);
	}

	/**
	 * Args for the `swf_entry_form` taxonomy: no term-management UI of its
	 * own (terms are created/assigned programmatically — see
	 * entry_term_for_form()), but `show_admin_column` gives the Entries list
	 * a "Form" column, and render_entry_form_filter() adds the matching
	 * filter dropdown above it.
	 *
	 * @return array<string, mixed>
	 */
	private function entry_form_taxonomy_args(): array {
		return array(
			'labels'            => array(
				'name'          => __( 'Forms', 'swiftforms' ),
				'singular_name' => __( 'Form', 'swiftforms' ),
			),
			'hierarchical'      => false,
			'public'            => false,
			'show_ui'           => false,
			'show_admin_column' => true,
			'query_var'         => true,
			'show_in_rest'      => false,
			'rewrite'           => false,
		);
	}

	/**
	 * Keeps the `swf_entry_form` term name in sync with its form's title
	 * whenever the form is saved with one, so the Entries filter dropdown
	 * never shows a stale label.
	 */
	public function sync_form_term_name( int $post_id ): void {
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		$title = get_the_title( $post_id );

		if ( '' === $title ) {
			return;
		}

		$term_id = self::entry_term_for_form( $post_id );

		if ( $term_id > 0 ) {
			wp_update_term( $term_id, self::ENTRY_FORM_TAXONOMY, array( 'name' => $title ) );
		}
	}

	/**
	 * The deterministic `swf_entry_form` term slug for a given form — stable
	 * and computable without a DB lookup, so links (EditorIntegration, the
	 * block editor "Entries" link) can point straight at a filtered Entries
	 * list without needing the term to already exist.
	 */
	public static function entry_term_slug( int $form_id ): string {
		return 'swf-form-' . $form_id;
	}

	/**
	 * Gets (or lazily creates) the `swf_entry_form` term for a form, used to
	 * tag every entry saved for it. Falls back to a generic name if the form
	 * has no title yet.
	 */
	public static function entry_term_for_form( int $form_id ): int {
		$slug = self::entry_term_slug( $form_id );
		$term = get_term_by( 'slug', $slug, self::ENTRY_FORM_TAXONOMY );

		if ( $term instanceof \WP_Term ) {
			return $term->term_id;
		}

		$title = get_the_title( $form_id );
		/* translators: %d: form post id. */
		$name = '' !== $title ? $title : sprintf( __( 'Form #%d', 'swiftforms' ), $form_id );

		$result = wp_insert_term( $name, self::ENTRY_FORM_TAXONOMY, array( 'slug' => $slug ) );

		return is_wp_error( $result ) ? 0 : (int) $result['term_id'];
	}

	/**
	 * The "filter by form" dropdown above the Entries list table — WordPress
	 * only auto-adds this for the built-in `category` taxonomy, so a custom
	 * one needs a `restrict_manage_posts` renderer; filtering itself is
	 * handled entirely by core once submitted (the taxonomy's `query_var`
	 * is picked up by WP_Query like any other).
	 */
	public function render_entry_form_filter(): void {
		global $typenow;

		if ( self::ENTRY_POST_TYPE !== $typenow ) {
			return;
		}

		$terms = get_terms(
			array(
				'taxonomy'   => self::ENTRY_FORM_TAXONOMY,
				'hide_empty' => true,
			)
		);

		if ( is_wp_error( $terms ) || ! $terms ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list-table filter, same as core's own category dropdown.
		$selected = isset( $_GET[ self::ENTRY_FORM_TAXONOMY ] ) ? sanitize_title( wp_unslash( (string) $_GET[ self::ENTRY_FORM_TAXONOMY ] ) ) : '';

		echo '<label class="screen-reader-text" for="' . esc_attr( self::ENTRY_FORM_TAXONOMY ) . '">' . esc_html__( 'Filter by form', 'swiftforms' ) . '</label>';
		echo '<select name="' . esc_attr( self::ENTRY_FORM_TAXONOMY ) . '" id="' . esc_attr( self::ENTRY_FORM_TAXONOMY ) . '">';
		echo '<option value="">' . esc_html__( 'All forms', 'swiftforms' ) . '</option>';

		foreach ( $terms as $term ) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $term->slug ),
				selected( $selected, $term->slug, false ),
				esc_html( $term->name )
			);
		}

		echo '</select>';
	}
}
