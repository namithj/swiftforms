<?php
/**
 * Tests for custom post type registration.
 */

declare(strict_types=1);

class SwiftForms_CPTs_Test extends WP_UnitTestCase {
    public function test_register_adds_form_post_type(): void {
        $cpts = new SwiftForms_CPTs();

        $cpts->register();

        $this->assertNotNull(get_post_type_object(SwiftForms_CPTs::FORM_POST_TYPE));
    }

    public function test_register_adds_submission_post_type(): void {
        $cpts = new SwiftForms_CPTs();

        $cpts->register();

        $this->assertNotNull(get_post_type_object(SwiftForms_CPTs::SUBMISSION_POST_TYPE));
    }

    public function test_form_type_supports_expected_editor_features(): void {
        $cpts = new SwiftForms_CPTs();

        $cpts->register();

        $this->assertTrue(post_type_supports(SwiftForms_CPTs::FORM_POST_TYPE, 'title'));
        $this->assertTrue(post_type_supports(SwiftForms_CPTs::FORM_POST_TYPE, 'editor'));
        $this->assertTrue(post_type_supports(SwiftForms_CPTs::FORM_POST_TYPE, 'thumbnail'));
        $this->assertFalse(post_type_supports(SwiftForms_CPTs::FORM_POST_TYPE, 'revisions'));
    }

    public function test_form_type_supports_custom_fields_for_rest_meta(): void {
        // Without 'custom-fields' support, WordPress's REST API never exposes
        // or accepts the `meta` field for this post type, so _sf_settings
        // (the Form Experience / Notifications sidebar panel) could never be
        // read or saved through the block editor.
        $cpts = new SwiftForms_CPTs();

        $cpts->register();

        $this->assertTrue(post_type_supports(SwiftForms_CPTs::FORM_POST_TYPE, 'custom-fields'));
    }

    public function test_submission_post_type_is_private(): void {
        $cpts = new SwiftForms_CPTs();

        $cpts->register();

        $post_type = get_post_type_object(SwiftForms_CPTs::SUBMISSION_POST_TYPE);

        $this->assertFalse($post_type->publicly_queryable);
        $this->assertTrue($post_type->show_ui);
    }

    public function test_get_form_settings_returns_defaults_when_meta_is_missing(): void {
        $form_id = self::factory()->post->create(array('post_type' => SwiftForms_CPTs::FORM_POST_TYPE));

        $settings = SwiftForms_CPTs::get_form_settings($form_id);

        $this->assertSame('Send message', $settings['submitLabel']);
        $this->assertSame('Form submitted successfully.', $settings['successMessage']);
        $this->assertFalse($settings['enableCaptcha']);
    }

    public function test_register_form_settings_meta_exposes_rest_schema(): void {
        $cpts = new SwiftForms_CPTs();

        $cpts->register();

        $meta_keys = get_registered_meta_keys('post', SwiftForms_CPTs::FORM_POST_TYPE);

        $this->assertArrayHasKey(SwiftForms_CPTs::FORM_SETTINGS_META_KEY, $meta_keys);
        $this->assertSame('object', $meta_keys[SwiftForms_CPTs::FORM_SETTINGS_META_KEY]['type']);
        $this->assertIsArray($meta_keys[SwiftForms_CPTs::FORM_SETTINGS_META_KEY]['show_in_rest']);
    }

    public function test_sanitize_form_settings_normalizes_form_level_values(): void {
        $settings = SwiftForms_CPTs::sanitize_form_settings(
            array(
                'adminRecipients' => " ops@example.org\nowner@example.org ",
                'adminSubject' => '  New lead {submission_id}  ',
                'enableCaptcha' => '1',
                'submitLabel' => '  Send now  ',
                'successMessage' => '  Thanks for reaching out.  ',
            )
        );

        $this->assertSame("ops@example.org\nowner@example.org", $settings['adminRecipients']);
        $this->assertSame('New lead {submission_id}', $settings['adminSubject']);
        $this->assertTrue($settings['enableCaptcha']);
        $this->assertSame('Send now', $settings['submitLabel']);
        $this->assertSame('Thanks for reaching out.', $settings['successMessage']);
    }

    public function test_sanitize_form_settings_meta_returns_defaults_for_invalid_values(): void {
        $settings = SwiftForms_CPTs::sanitize_form_settings_meta('invalid');

        $this->assertSame('Send message', $settings['submitLabel']);
        $this->assertFalse($settings['enableCaptcha']);
    }

    public function test_filter_submission_columns_adds_form_and_email_columns(): void {
        $cpts = new SwiftForms_CPTs();

        $columns = $cpts->filter_submission_columns(
            array(
                'cb' => '<input type="checkbox" />',
                'title' => 'Title',
                'date' => 'Date',
            )
        );

        $this->assertSame('Form', $columns['swiftforms_form']);
        $this->assertSame('Email', $columns['swiftforms_email']);
    }

    public function test_render_submission_column_outputs_form_title_and_email(): void {
        $cpts = new SwiftForms_CPTs();
        $form_id = self::factory()->post->create(
            array(
                'post_type' => SwiftForms_CPTs::FORM_POST_TYPE,
                'post_title' => 'Contact Form',
            )
        );
        $submission_id = self::factory()->post->create(
            array(
                'post_type' => SwiftForms_CPTs::SUBMISSION_POST_TYPE,
            )
        );

        update_post_meta($submission_id, '_sf_form_id', $form_id);
        update_post_meta($submission_id, '_sf_field_email', 'person@example.com');

        ob_start();
        $cpts->render_submission_column('swiftforms_form', $submission_id);
        $form_output = trim((string) ob_get_clean());

        ob_start();
        $cpts->render_submission_column('swiftforms_email', $submission_id);
        $email_output = trim((string) ob_get_clean());

        $this->assertSame('Contact Form', $form_output);
        $this->assertSame('person@example.com', $email_output);
    }

    public function test_build_export_rows_unions_field_columns_across_submissions(): void {
        $cpts = new SwiftForms_CPTs();
        $cpts->register();

        $first = self::factory()->post->create(array('post_type' => SwiftForms_CPTs::SUBMISSION_POST_TYPE));
        update_post_meta($first, '_sf_field_email', 'a@example.com');

        $second = self::factory()->post->create(array('post_type' => SwiftForms_CPTs::SUBMISSION_POST_TYPE));
        update_post_meta($second, '_sf_field_email', 'b@example.com');
        update_post_meta($second, '_sf_field_full_name', 'Second Person');

        $rows = $cpts->build_export_rows(array($first, $second));

        $header = $rows[0];
        $this->assertSame(array('ID', 'Title', 'Date'), array_slice($header, 0, 3));
        $this->assertContains('Email', $header);
        $this->assertContains('Full Name', $header);

        $full_name_index = array_search('Full Name', $header, true);
        $this->assertSame('', $rows[1][$full_name_index]);
        $this->assertSame('Second Person', $rows[2][$full_name_index]);
    }
}