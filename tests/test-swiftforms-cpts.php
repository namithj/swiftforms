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
}