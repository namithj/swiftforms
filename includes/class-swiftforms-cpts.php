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
     * Form settings meta key.
     */
    public const FORM_SETTINGS_META_KEY = '_sf_settings';

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

        add_action('add_meta_boxes_' . self::FORM_POST_TYPE, array($this, 'register_form_settings_metabox'));
        add_action('save_post_' . self::FORM_POST_TYPE, array($this, 'save_form_settings_metabox'));
    }

    /**
     * Returns the default settings stored on a form post.
     *
     * @return array<string, string|bool>
     */
    public static function get_default_form_settings(): array {
        return array(
            'adminRecipients' => '',
            'adminSubject' => 'SwiftForms submission #{submission_id}',
            'adminTemplate' => '',
            'autoresponderSubject' => 'We received your submission',
            'autoresponderTemplate' => '',
            'enableCaptcha' => false,
            'submitLabel' => 'Send message',
            'successMessage' => 'Form submitted successfully.',
        );
    }

    /**
     * Returns the stored settings for a form post merged with defaults.
     *
     * @return array<string, string|bool>
     */
    public static function get_form_settings(int $post_id): array {
        $saved_settings = get_post_meta($post_id, self::FORM_SETTINGS_META_KEY, true);

        if (!is_array($saved_settings)) {
            $saved_settings = array();
        }

        return self::sanitize_form_settings($saved_settings);
    }

    /**
     * Sanitizes form settings before storage or runtime use.
     *
     * @param array<string, mixed> $settings Raw settings.
     *
     * @return array<string, string|bool>
     */
    public static function sanitize_form_settings(array $settings): array {
        $defaults = self::get_default_form_settings();

        return array(
            'adminRecipients' => isset($settings['adminRecipients']) ? sanitize_textarea_field((string) $settings['adminRecipients']) : $defaults['adminRecipients'],
            'adminSubject' => isset($settings['adminSubject']) ? sanitize_text_field((string) $settings['adminSubject']) : $defaults['adminSubject'],
            'adminTemplate' => isset($settings['adminTemplate']) ? sanitize_textarea_field((string) $settings['adminTemplate']) : $defaults['adminTemplate'],
            'autoresponderSubject' => isset($settings['autoresponderSubject']) ? sanitize_text_field((string) $settings['autoresponderSubject']) : $defaults['autoresponderSubject'],
            'autoresponderTemplate' => isset($settings['autoresponderTemplate']) ? sanitize_textarea_field((string) $settings['autoresponderTemplate']) : $defaults['autoresponderTemplate'],
            'enableCaptcha' => !empty($settings['enableCaptcha']),
            'submitLabel' => isset($settings['submitLabel']) && '' !== trim((string) $settings['submitLabel'])
                ? sanitize_text_field((string) $settings['submitLabel'])
                : $defaults['submitLabel'],
            'successMessage' => isset($settings['successMessage']) && '' !== trim((string) $settings['successMessage'])
                ? sanitize_text_field((string) $settings['successMessage'])
                : $defaults['successMessage'],
        );
    }

    /**
     * Registers the form settings side metabox for the form CPT editor.
     */
    public function register_form_settings_metabox(): void {
        add_meta_box(
            'swiftforms-form-settings',
            'Form Settings',
            array($this, 'render_form_settings_metabox'),
            self::FORM_POST_TYPE,
            'side',
            'default'
        );
    }

    /**
     * Renders the form settings metabox on the form CPT editor.
     */
    public function render_form_settings_metabox(WP_Post $post): void {
        $settings = self::get_form_settings($post->ID);

        wp_nonce_field('swiftforms_save_form_settings', 'swiftforms_form_settings_nonce');
        ?>
        <p>
            <label for="swiftforms-submit-label"><strong>Submit label</strong></label>
            <input class="widefat" id="swiftforms-submit-label" name="swiftforms_form_settings[submitLabel]" type="text" value="<?php echo esc_attr((string) $settings['submitLabel']); ?>" />
        </p>
        <p>
            <label for="swiftforms-success-message"><strong>Success message</strong></label>
            <textarea class="widefat" id="swiftforms-success-message" name="swiftforms_form_settings[successMessage]" rows="3"><?php echo esc_textarea((string) $settings['successMessage']); ?></textarea>
        </p>
        <p>
            <label>
                <input name="swiftforms_form_settings[enableCaptcha]" type="checkbox" value="1" <?php checked(!empty($settings['enableCaptcha'])); ?> />
                Enable math captcha
            </label>
        </p>
        <hr />
        <p>
            <label for="swiftforms-admin-recipients"><strong>Admin recipients</strong></label>
            <textarea class="widefat" id="swiftforms-admin-recipients" name="swiftforms_form_settings[adminRecipients]" rows="3"><?php echo esc_textarea((string) $settings['adminRecipients']); ?></textarea>
        </p>
        <p>
            <label for="swiftforms-admin-subject"><strong>Admin subject</strong></label>
            <input class="widefat" id="swiftforms-admin-subject" name="swiftforms_form_settings[adminSubject]" type="text" value="<?php echo esc_attr((string) $settings['adminSubject']); ?>" />
        </p>
        <p>
            <label for="swiftforms-admin-template"><strong>Admin template</strong></label>
            <textarea class="widefat" id="swiftforms-admin-template" name="swiftforms_form_settings[adminTemplate]" rows="4"><?php echo esc_textarea((string) $settings['adminTemplate']); ?></textarea>
        </p>
        <p>
            <label for="swiftforms-autoresponder-subject"><strong>Autoresponder subject</strong></label>
            <input class="widefat" id="swiftforms-autoresponder-subject" name="swiftforms_form_settings[autoresponderSubject]" type="text" value="<?php echo esc_attr((string) $settings['autoresponderSubject']); ?>" />
        </p>
        <p>
            <label for="swiftforms-autoresponder-template"><strong>Autoresponder template</strong></label>
            <textarea class="widefat" id="swiftforms-autoresponder-template" name="swiftforms_form_settings[autoresponderTemplate]" rows="4"><?php echo esc_textarea((string) $settings['autoresponderTemplate']); ?></textarea>
        </p>
        <p><small>Supports {submission_id}, {form_id}, {fields}, and {field:slug} placeholders.</small></p>
        <?php
    }

    /**
     * Saves form settings from the metabox.
     */
    public function save_form_settings_metabox(int $post_id): void {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (wp_is_post_revision($post_id)) {
            return;
        }

        $nonce = isset($_POST['swiftforms_form_settings_nonce']) ? (string) $_POST['swiftforms_form_settings_nonce'] : '';
        if ('' === $nonce || !wp_verify_nonce($nonce, 'swiftforms_save_form_settings')) {
            return;
        }

        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        $settings = isset($_POST['swiftforms_form_settings']) && is_array($_POST['swiftforms_form_settings'])
            ? wp_unslash($_POST['swiftforms_form_settings'])
            : array();

        update_post_meta($post_id, self::FORM_SETTINGS_META_KEY, self::sanitize_form_settings($settings));
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