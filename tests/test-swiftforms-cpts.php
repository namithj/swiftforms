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
}