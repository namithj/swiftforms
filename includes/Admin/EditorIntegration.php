<?php
/**
 * Block-editor integration: Settings|Entries navigation.
 *
 * @package SwiftForms
 */

declare(strict_types=1);

namespace SwiftForms\Admin;

use SwiftForms\PostTypes;
use SwiftForms\Registrable;
use SwiftForms\Settings\FormSettingsMetabox;

/**
 * The editor header links UI itself is a JS `PluginPostStatusInfo`/portal
 * (src/editor/form-tabs) with no official WordPress SlotFill to hook — so
 * it's backed here by server-rendered fallbacks that always work regardless
 * of editor-chrome changes: row actions on the forms list, and an admin bar
 * menu on the form-editor screen itself. Both link to the same places the
 * in-editor links do: Settings jumps straight to its meta box (a plain
 * `#` anchor to FormSettingsMetabox::METABOX_ID — it's rendered right on
 * this same edit screen, no popup/page to open) and Entries opens the
 * Entries screen with this form preselected in its filter.
 */
final class EditorIntegration implements Registrable {

	public function register(): void {
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_editor_assets' ) );
		add_filter( 'post_row_actions', array( $this, 'add_row_actions' ), 10, 2 );
		add_action( 'admin_bar_menu', array( $this, 'add_admin_bar_links' ), 100 );
	}

	public function enqueue_editor_assets(): void {
		$screen = get_current_screen();

		if ( ! $screen || PostTypes::FORM_POST_TYPE !== $screen->post_type ) {
			return;
		}

		$entry = SMARTLOGIX_SWIFTFORMS_PLUGIN_PATH . 'build/editor/index';

		if ( ! file_exists( "{$entry}.js" ) ) {
			return;
		}

		$asset = file_exists( "{$entry}.asset.php" ) ? include "{$entry}.asset.php" : array();

		wp_enqueue_script(
			'smartlogix-swiftforms-editor-integration',
			SMARTLOGIX_SWIFTFORMS_PLUGIN_URL . 'build/editor/index.js',
			(array) ( $asset['dependencies'] ?? array( 'wp-plugins', 'wp-edit-post', 'wp-element', 'wp-components' ) ),
			(string) ( $asset['version'] ?? SMARTLOGIX_SWIFTFORMS_VERSION ),
			true
		);
		wp_set_script_translations( 'smartlogix-swiftforms-editor-integration', 'swiftforms' );

		if ( file_exists( "{$entry}.css" ) ) {
			wp_enqueue_style( 'smartlogix-swiftforms-editor-integration', SMARTLOGIX_SWIFTFORMS_PLUGIN_URL . 'build/editor/index.css', array(), (string) ( $asset['version'] ?? SMARTLOGIX_SWIFTFORMS_VERSION ) );
		}

		global $post;

		wp_add_inline_script(
			'smartlogix-swiftforms-editor-integration',
			'window.smartlogixSwiftFormsEditorSettings = ' . wp_json_encode(
				array(
					'adminUrl' => esc_url_raw( admin_url() ),
					'formId'   => $post ? $post->ID : 0,
				)
			) . ';',
			'before'
		);
	}

	/**
	 * @param array<string, string> $actions Existing row actions.
	 */
	public function add_row_actions( array $actions, \WP_Post $post ): array {
		if ( PostTypes::FORM_POST_TYPE !== $post->post_type || ! current_user_can( 'edit_post', $post->ID ) ) {
			return $actions;
		}

		$actions['smartlogix-swiftforms-settings'] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'post.php?post=' . $post->ID . '&action=edit#' . FormSettingsMetabox::METABOX_ID ) ),
			esc_html__( 'Settings', 'swiftforms' )
		);

		$actions['smartlogix-swiftforms-entries'] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( $this->entries_list_url( $post->ID ) ) ),
			esc_html__( 'Entries', 'swiftforms' )
		);

		return $actions;
	}

	public function add_admin_bar_links( \WP_Admin_Bar $admin_bar ): void {
		$screen = get_current_screen();

		if ( ! $screen || PostTypes::FORM_POST_TYPE !== $screen->post_type ) {
			return;
		}

		$post_id = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( $post_id <= 0 ) {
			return;
		}

		$admin_bar->add_node(
			array(
				'id'    => 'smartlogix-swiftforms-form',
				'title' => __( 'SwiftForms', 'swiftforms' ),
			)
		);

		$admin_bar->add_node(
			array(
				'id'     => 'smartlogix-swiftforms-form-settings',
				'parent' => 'smartlogix-swiftforms-form',
				'title'  => __( 'Settings', 'swiftforms' ),
				'href'   => admin_url( 'post.php?post=' . $post_id . '&action=edit#' . FormSettingsMetabox::METABOX_ID ),
			)
		);

		$admin_bar->add_node(
			array(
				'id'     => 'smartlogix-swiftforms-form-entries',
				'parent' => 'smartlogix-swiftforms-form',
				'title'  => __( 'Entries', 'swiftforms' ),
				'href'   => admin_url( $this->entries_list_url( $post_id ) ),
			)
		);
	}

	/**
	 * The Entries list table pre-filtered to one form via the
	 * `smartlogix_swf_entry_form` taxonomy query var — no lookup needed, the term
	 * slug is deterministic (see PostTypes::entry_term_slug()).
	 */
	private function entries_list_url( int $form_id ): string {
		return sprintf(
			'edit.php?post_type=%s&%s=%s',
			PostTypes::ENTRY_POST_TYPE,
			PostTypes::ENTRY_FORM_TAXONOMY,
			PostTypes::entry_term_slug( $form_id )
		);
	}
}
