<?php
/**
 * Tests for block registration.
 */

declare(strict_types=1);

class SwiftForms_Blocks_Test extends WP_UnitTestCase {
    public function test_get_block_metadata_paths_lists_known_blocks(): void {
        $blocks = new SwiftForms_Blocks(SWIFTFORMS_PATH);

        $paths = $blocks->get_block_metadata_paths();

        $this->assertCount(10, $paths);
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
        $this->assertNotNull(WP_Block_Type_Registry::get_instance()->get_registered('swiftforms/number-field'));
        $this->assertNotNull(WP_Block_Type_Registry::get_instance()->get_registered('swiftforms/tel-field'));
        $this->assertNotNull(WP_Block_Type_Registry::get_instance()->get_registered('swiftforms/select-field'));
        $this->assertNotNull(WP_Block_Type_Registry::get_instance()->get_registered('swiftforms/checkbox-field'));
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

    public function test_filter_allowed_block_types_keeps_builder_blocks_in_form_posts(): void {
        $blocks = new SwiftForms_Blocks(SWIFTFORMS_PATH);
        $form_id = self::factory()->post->create(array('post_type' => SwiftForms_CPTs::FORM_POST_TYPE));
        $context = new WP_Block_Editor_Context(array('post' => get_post($form_id)));

        $allowed = $blocks->filter_allowed_block_types(
            array('swiftforms/form', 'swiftforms/text-field', 'core/paragraph'),
            $context
        );

        $this->assertContains('swiftforms/text-field', $allowed);
        $this->assertContains('core/paragraph', $allowed);
        $this->assertNotContains('swiftforms/form', $allowed);
    }

    public function test_filter_allowed_block_types_removes_field_blocks_from_page_posts(): void {
        $blocks = new SwiftForms_Blocks(SWIFTFORMS_PATH);
        $page_id = self::factory()->post->create(array('post_type' => 'page'));
        $context = new WP_Block_Editor_Context(array('post' => get_post($page_id)));

        $allowed = $blocks->filter_allowed_block_types(
            array('swiftforms/form', 'swiftforms/text-field', 'core/paragraph'),
            $context
        );

        $this->assertContains('swiftforms/form', $allowed);
        $this->assertContains('core/paragraph', $allowed);
        $this->assertNotContains('swiftforms/text-field', $allowed);
    }

    public function test_render_form_block_outputs_selected_form_content(): void {
        $blocks = new SwiftForms_Blocks(SWIFTFORMS_PATH);
        $blocks->register_blocks();

        $form_id = self::factory()->post->create(
            array(
                'post_content' => '<!-- wp:swiftforms/text-field {"label":"Your name","slug":"your_name"} --><div class="wp-block-swiftforms-text-field swiftforms-field swiftforms-field--text" data-field-slug="your_name" data-field-type="text" data-swiftforms-field="true"><label class="swiftforms-field__control"><span class="swiftforms-field__label">Your name</span><input name="your_name" type="text" /></label></div><!-- /wp:swiftforms/text-field -->',
                'post_status' => 'publish',
                'post_type' => SwiftForms_CPTs::FORM_POST_TYPE,
            )
        );

        update_post_meta(
            $form_id,
            SwiftForms_CPTs::FORM_SETTINGS_META_KEY,
            SwiftForms_CPTs::sanitize_form_settings(
                array(
                    'adminRecipients' => 'ops@example.com',
                    'submitLabel' => 'Send now',
                    'successMessage' => 'Thanks',
                )
            )
        );

        $markup = $blocks->render_form_block(array('formId' => $form_id));

        $this->assertStringContainsString('data-form-id="' . $form_id . '"', $markup);
        $this->assertStringContainsString('data-admin-recipients="ops@example.com"', $markup);
        $this->assertStringContainsString('data-success-message="Thanks"', $markup);
        $this->assertStringContainsString('data-swiftforms-form', $markup);
        $this->assertStringContainsString('Your name', $markup);
        $this->assertStringContainsString('data-field-slug="your_name"', $markup);
        $this->assertStringContainsString('Send now', $markup);
    }
}