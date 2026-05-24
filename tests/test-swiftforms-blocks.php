<?php
/**
 * Tests for block registration.
 */

declare(strict_types=1);

class SwiftForms_Blocks_Test extends WP_UnitTestCase {
    public function test_get_block_metadata_paths_lists_known_blocks(): void {
        $blocks = new SwiftForms_Blocks(SWIFTFORMS_PATH);

        $paths = $blocks->get_block_metadata_paths();

        $this->assertCount(6, $paths);
    }

    public function test_register_blocks_registers_form_block(): void {
        $blocks = new SwiftForms_Blocks(SWIFTFORMS_PATH);

        $blocks->register_blocks();

        $this->assertNotNull(WP_Block_Type_Registry::get_instance()->get_registered('swiftforms/form'));
    }

    public function test_register_blocks_registers_text_and_email_field_blocks(): void {
        $blocks = new SwiftForms_Blocks(SWIFTFORMS_PATH);

        $blocks->register_blocks();

        $this->assertNotNull(WP_Block_Type_Registry::get_instance()->get_registered('swiftforms/text-field'));
        $this->assertNotNull(WP_Block_Type_Registry::get_instance()->get_registered('swiftforms/email-field'));
    }

    public function test_register_blocks_registers_additional_field_blocks(): void {
        $blocks = new SwiftForms_Blocks(SWIFTFORMS_PATH);

        $blocks->register_blocks();

        $this->assertNotNull(WP_Block_Type_Registry::get_instance()->get_registered('swiftforms/textarea-field'));
        $this->assertNotNull(WP_Block_Type_Registry::get_instance()->get_registered('swiftforms/url-field'));
        $this->assertNotNull(WP_Block_Type_Registry::get_instance()->get_registered('swiftforms/file-field'));
    }

    public function test_enqueue_frontend_settings_attaches_runtime_data(): void {
        $blocks = new SwiftForms_Blocks(SWIFTFORMS_PATH);

        $blocks->register_blocks();
        do_action('wp_enqueue_scripts');
        wp_enqueue_script('swiftforms-form-view-script');
        $scripts = wp_scripts();
        $inline_script = implode("\n", $scripts->get_data('swiftforms-form-view-script', 'before') ?: array());

        $this->assertStringContainsString('window.swiftformsSettings = ', $inline_script);
        $this->assertStringContainsString('swiftforms_submit', $inline_script);
        $this->assertStringContainsString('admin-ajax.php', $inline_script);
    }
}