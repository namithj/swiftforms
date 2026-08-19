<?php
/**
 * Tests for SwiftForms\PostTypes.
 *
 * @package SwiftForms
 */

declare(strict_types=1);

namespace SwiftForms\Tests;

use SwiftForms\PostTypes;
use SwiftForms\Settings\FormSettings;
use SwiftForms\Settings\FormSettingsMetabox;

final class PostTypesTest extends TestCase {

	public function test_form_post_type_is_registered_correctly(): void {
		$object = get_post_type_object( PostTypes::FORM_POST_TYPE );

		$this->assertNotNull( $object );
		$this->assertFalse( $object->public );
		$this->assertTrue( $object->show_ui );
		$this->assertFalse( $object->show_in_menu );
		$this->assertTrue( $object->show_in_rest );
		$this->assertSame( 'edit_swf_forms', $object->cap->edit_posts );
		$this->assertArrayHasKey( 'editor', get_all_post_type_supports( PostTypes::FORM_POST_TYPE ) );
	}

	public function test_entry_post_type_is_registered_correctly(): void {
		$object = get_post_type_object( PostTypes::ENTRY_POST_TYPE );

		$this->assertNotNull( $object );
		$this->assertFalse( $object->public );
		$this->assertTrue( $object->show_ui );
		$this->assertSame( 'edit.php?post_type=' . PostTypes::FORM_POST_TYPE, $object->show_in_menu );
		$this->assertFalse( $object->show_in_rest );
		$this->assertSame( 'edit_swf_entries', $object->cap->edit_posts );
		$this->assertArrayHasKey( 'custom-fields', get_all_post_type_supports( PostTypes::ENTRY_POST_TYPE ) );
	}

	public function test_entry_form_taxonomy_is_registered_with_admin_column(): void {
		$taxonomy = get_taxonomy( PostTypes::ENTRY_FORM_TAXONOMY );

		$this->assertNotFalse( $taxonomy );
		$this->assertContains( PostTypes::ENTRY_POST_TYPE, $taxonomy->object_type );
		$this->assertTrue( $taxonomy->show_admin_column );
	}

	public function test_entry_term_for_form_is_created_and_reused(): void {
		$form_id = $this->create_form();

		$term_id = PostTypes::entry_term_for_form( $form_id );
		$again   = PostTypes::entry_term_for_form( $form_id );

		$this->assertSame( $term_id, $again );
		$this->assertSame( PostTypes::entry_term_slug( $form_id ), get_term( $term_id )->slug );
	}

	public function test_form_settings_meta_has_registered_defaults(): void {
		$form_id = $this->create_form();

		$defaults = FormSettings::get( $form_id );

		$this->assertSame( 'default', $defaults['saveEntries'] );
		$this->assertSame( 0, $defaults['retentionDays'] );
	}

	/**
	 * Exercises the real save path: Cassette-CMF's own `save_post` handler,
	 * not a direct PHP call — this is how the meta box actually persists a
	 * submission from the post-edit screen.
	 */
	public function test_form_settings_metabox_sanitizes_input_on_save_post(): void {
		$form_id = $this->create_form();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$_POST = array(
			'swf_form_fields_nonce'                        => wp_create_nonce( 'save_swf_form_fields' ),
			FormSettingsMetabox::meta_key( 'submitLabel' ) => "Send\nit",
			FormSettingsMetabox::meta_key( 'enableCaptcha' ) => 'yes',
			FormSettingsMetabox::meta_key( 'saveEntries' ) => 'not-a-real-option',
		);

		do_action( 'save_post', $form_id, get_post( $form_id ), true );

		$_POST = array();

		$settings = FormSettings::get( $form_id );

		$this->assertStringNotContainsString( "\n", $settings['submitLabel'] );
		$this->assertTrue( $settings['enableCaptcha'] );
		$this->assertSame( 'default', $settings['saveEntries'] );
	}
}
