<?php
/**
 * Top-level admin menu.
 *
 * @package SwiftForms
 */

declare(strict_types=1);

namespace SwiftForms\Admin;

use SwiftForms\PostTypes;
use SwiftForms\Registrable;

/**
 * Builds the "SwiftForms" top-level menu: All Forms / Add New (the CPT's
 * own list table and editor). "Entries" is added automatically by
 * WordPress core (`_add_post_type_submenus()`) since the `swf_entry` CPT
 * declares `show_in_menu` pointing at this same parent slug — it's a plain
 * list table, not a bespoke page, so there's nothing to register here.
 * "Settings" is registered separately by Settings\GlobalSettingsPage via
 * Cassette-CMF. Per-form Settings is a meta box on the form's own edit
 * screen (see Settings\FormSettingsMetabox), not a separate page.
 */
final class Menu implements Registrable {

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
	}

	public function register_menu(): void {
		$form_edit_slug = 'edit.php?post_type=' . PostTypes::FORM_POST_TYPE;

		add_menu_page(
			__( 'SwiftForms', 'swiftforms' ),
			__( 'SwiftForms', 'swiftforms' ),
			'edit_swf_forms', // phpcs:ignore WordPress.WP.Capabilities.Unknown -- registered for administrators in Activation.
			$form_edit_slug,
			'',
			'dashicons-feedback',
			30
		);

		add_submenu_page(
			$form_edit_slug,
			__( 'All Forms', 'swiftforms' ),
			__( 'All Forms', 'swiftforms' ),
			'edit_swf_forms', // phpcs:ignore WordPress.WP.Capabilities.Unknown -- registered for administrators in Activation.
			$form_edit_slug
		);

		add_submenu_page(
			$form_edit_slug,
			__( 'Add New', 'swiftforms' ),
			__( 'Add New', 'swiftforms' ),
			'edit_swf_forms', // phpcs:ignore WordPress.WP.Capabilities.Unknown -- registered for administrators in Activation.
			'post-new.php?post_type=' . PostTypes::FORM_POST_TYPE
		);

		// "Entries" is added automatically by WordPress core, and
		// "Settings" is registered by Settings\GlobalSettingsPage via
		// Cassette-CMF — neither is registered here.
	}
}
