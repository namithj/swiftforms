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

        $this->register_form_settings_meta();

        add_action('enqueue_block_editor_assets', array($this, 'enqueue_form_settings_panel'));
        add_filter('manage_edit-' . self::SUBMISSION_POST_TYPE . '_columns', array($this, 'filter_submission_columns'));
        add_action('manage_' . self::SUBMISSION_POST_TYPE . '_posts_custom_column', array($this, 'render_submission_column'), 10, 2);
    }

    /**
     * Registers form settings meta so the block editor sidebar can edit it directly.
     */
    public function register_form_settings_meta(): void {
        register_post_meta(
            self::FORM_POST_TYPE,
            self::FORM_SETTINGS_META_KEY,
            array(
                'auth_callback' => static fn (): bool => current_user_can('edit_posts'),
                'default' => self::get_default_form_settings(),
                'sanitize_callback' => array(__CLASS__, 'sanitize_form_settings_meta'),
                'show_in_rest' => array(
                    'schema' => $this->get_form_settings_meta_schema(),
                ),
                'single' => true,
                'type' => 'object',
            )
        );
    }

    /**
     * Sanitizes REST meta values for the form settings object.
     *
     * @param mixed $value Raw meta value.
     *
     * @return array<string, string|bool>
     */
    public static function sanitize_form_settings_meta($value): array {
        if (!is_array($value)) {
            return self::get_default_form_settings();
        }

        return self::sanitize_form_settings($value);
    }

    /**
     * Returns the REST schema for the form settings sidebar panel.
     *
     * @return array<string, mixed>
     */
    public function get_form_settings_meta_schema(): array {
        return array(
            'type' => 'object',
            'properties' => array(
                'adminRecipients' => array('type' => 'string'),
                'adminSubject' => array('type' => 'string'),
                'adminTemplate' => array('type' => 'string'),
                'autoresponderSubject' => array('type' => 'string'),
                'autoresponderTemplate' => array('type' => 'string'),
                'enableCaptcha' => array('type' => 'boolean'),
                'submitLabel' => array('type' => 'string'),
                'successMessage' => array('type' => 'string'),
            ),
        );
    }

    /**
     * Enqueues the form settings document panel for the form CPT block editor.
     */
    public function enqueue_form_settings_panel(): void {
        if (!function_exists('get_current_screen')) {
            return;
        }

        $screen = get_current_screen();
        if (!$screen || self::FORM_POST_TYPE !== $screen->post_type) {
            return;
        }

        $asset_path = SWIFTFORMS_PATH . 'dist/form/settings-panel.asset.php';
        $script_path = SWIFTFORMS_PATH . 'dist/form/settings-panel.js';

        if (!file_exists($asset_path) || !file_exists($script_path)) {
            return;
        }

        $asset = require $asset_path;

        wp_enqueue_script(
            'swiftforms-form-settings-panel',
            SWIFTFORMS_URL . 'dist/form/settings-panel.js',
            $asset['dependencies'] ?? array(),
            $asset['version'] ?? SWIFTFORMS_VERSION,
            true
        );
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
     * Adds useful submission columns in wp-admin.
     *
     * @param array<string, string> $columns Existing post list table columns.
     *
     * @return array<string, string>
     */
    public function filter_submission_columns(array $columns): array {
        return array(
            'cb' => $columns['cb'] ?? '<input type="checkbox" />',
            'title' => $columns['title'] ?? 'Title',
            'swiftforms_form' => 'Form',
            'swiftforms_email' => 'Email',
            'date' => $columns['date'] ?? 'Date',
        );
    }

    /**
     * Renders custom submission column values.
     */
    public function render_submission_column(string $column_name, int $post_id): void {
        if ('swiftforms_form' === $column_name) {
            $form_id = (int) get_post_meta($post_id, '_sf_form_id', true);

            if ($form_id <= 0) {
                echo '&mdash;';
                return;
            }

            $form = get_post($form_id);

            if (!$form instanceof WP_Post) {
                echo esc_html(sprintf('Form #%d', $form_id));
                return;
            }

            echo esc_html(get_the_title($form) ?: sprintf('Form #%d', $form_id));
            return;
        }

        if ('swiftforms_email' === $column_name) {
            $email = get_post_meta($post_id, '_sf_field_email', true);

            if (!is_string($email) || '' === trim($email)) {
                echo '&mdash;';
                return;
            }

            echo esc_html($email);
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