<?php
/**
 * Custom post type registration.
 */

declare(strict_types=1);

class SwiftForms_CPTs {
    /**
     * Form post type slug.
     */
    public const FORM_POST_TYPE = 'swiftforms_form';

    /**
     * Submission post type slug.
     *
     * The spec name `swiftforms_submission` exceeds WordPress' 20 character limit,
     * so the runtime slug uses the closest valid form.
     */
    public const SUBMISSION_POST_TYPE = 'swiftform_entry';

    /**
     * Registers the form and submission custom post types.
     *
     * Tests to create:
     * - test_register_adds_form_post_type: Call register() and expect get_post_type_object('swiftforms_form') to return a post type object.
     * - test_register_adds_submission_post_type: Call register() and expect get_post_type_object('swiftforms_submission') to return a post type object.
     * - test_register_disables_form_revisions_by_omission: Call register() and expect the form type supports title, editor, and thumbnail, but not revisions.
     *
     * Expected output:
     * - Both custom post types are registered with their intended visibility and supports.
     */
    public function register(): void {
        if (!post_type_exists(self::FORM_POST_TYPE)) {
            register_post_type(self::FORM_POST_TYPE, $this->get_form_args());
        }

        if (!post_type_exists(self::SUBMISSION_POST_TYPE)) {
            register_post_type(self::SUBMISSION_POST_TYPE, $this->get_submission_args());
        }
    }

    /**
     * Returns registration arguments for the form builder post type.
     *
     * Tests to create:
     * - test_get_form_args_supports_editor_workflow: Call get_form_args() and expect supports to include title, editor, and thumbnail.
     *
     * Expected output:
     * - Form posts are editable in the block editor and omit revisions.
     *
     * @return array<string, mixed>
     */
    public function get_form_args(): array {
        return array(
            'label' => 'Forms',
            'description' => 'SwiftForms builders.',
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => true,
            'show_in_rest' => true,
            'menu_icon' => 'dashicons-feedback',
            'supports' => array('title', 'editor', 'thumbnail'),
            'map_meta_cap' => true,
        );
    }

    /**
     * Returns registration arguments for the submission post type.
     *
     * Tests to create:
     * - test_get_submission_args_is_private: Call get_submission_args() and expect publicly_queryable to be false.
     * - test_get_submission_args_keeps_admin_visibility: Call get_submission_args() and expect show_ui to be true.
     *
     * Expected output:
     * - Submission posts stay private while remaining manageable in wp-admin.
     *
     * @return array<string, mixed>
     */
    public function get_submission_args(): array {
        return array(
            'label' => 'Submissions',
            'description' => 'SwiftForms submission records.',
            'public' => false,
            'publicly_queryable' => false,
            'show_ui' => true,
            'show_in_menu' => true,
            'show_in_rest' => false,
            'supports' => array('title'),
            'capability_type' => 'post',
            'map_meta_cap' => true,
        );
    }
}