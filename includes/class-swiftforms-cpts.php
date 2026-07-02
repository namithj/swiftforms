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

        add_action('enqueue_block_editor_assets', array($this, 'enqueue_form_editor_assets'));
        add_filter('manage_edit-' . self::SUBMISSION_POST_TYPE . '_columns', array($this, 'filter_submission_columns'));
        add_action('manage_' . self::SUBMISSION_POST_TYPE . '_posts_custom_column', array($this, 'render_submission_column'), 10, 2);
        add_action('add_meta_boxes_' . self::SUBMISSION_POST_TYPE, array($this, 'add_submission_metabox'));
        add_filter('bulk_actions-edit-' . self::SUBMISSION_POST_TYPE, array($this, 'filter_submission_bulk_actions'));
        add_filter('handle_bulk_actions-edit-' . self::SUBMISSION_POST_TYPE, array($this, 'handle_submission_bulk_actions'), 10, 3);
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
     * Enqueues shared form editor assets for the form CPT block editor.
     */
    public function enqueue_form_editor_assets(): void {
        if (!function_exists('get_current_screen')) {
            return;
        }

        $screen = get_current_screen();
        if (!$screen || self::FORM_POST_TYPE !== $screen->post_type) {
            return;
        }

        $asset_path = SWIFTFORMS_PATH . 'dist/form/settings-panel.asset.php';
        $script_path = SWIFTFORMS_PATH . 'dist/form/settings-panel.js';
        $style_path = SWIFTFORMS_PATH . 'dist/form/settings-panel.css';

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

        if (file_exists($style_path)) {
            wp_enqueue_style(
                'swiftforms-form-settings-panel',
                SWIFTFORMS_URL . 'dist/form/settings-panel.css',
                array('wp-components'),
                $asset['version'] ?? SWIFTFORMS_VERSION
            );

            wp_style_add_data('swiftforms-form-settings-panel', 'rtl', 'replace');
        }
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
     * Registers the read-only submission details metabox.
     */
    public function add_submission_metabox(): void {
        add_meta_box(
            'swiftforms-submission-details',
            'Submission Details',
            array($this, 'render_submission_metabox'),
            self::SUBMISSION_POST_TYPE,
            'normal',
            'high'
        );
    }

    /**
     * Renders every stored field value for a submission as a read-only table.
     */
    public function render_submission_metabox(WP_Post $post): void {
        $fields = $this->get_submission_field_values($post->ID);
        $form_id = (int) get_post_meta($post->ID, '_sf_form_id', true);

        echo '<table class="widefat striped"><tbody>';

        if ($form_id > 0) {
            $form_link = get_edit_post_link($form_id);
            $form_title = get_the_title($form_id) ?: sprintf('Form #%d', $form_id);

            echo '<tr><th scope="row">Form</th><td>';
            if ($form_link) {
                echo '<a href="' . esc_url($form_link) . '">' . esc_html($form_title) . '</a>';
            } else {
                echo esc_html($form_title);
            }
            echo '</td></tr>';
        }

        if (empty($fields)) {
            echo '<tr><td colspan="2">No field data was stored for this submission.</td></tr>';
        }

        foreach ($fields as $slug => $value) {
            $file_url = $this->resolve_uploaded_file_url((string) $value);

            echo '<tr><th scope="row">' . esc_html($this->format_field_label($slug)) . '</th><td>';
            if ('' !== $file_url) {
                echo '<a href="' . esc_url($file_url) . '" rel="noopener" target="_blank">' . esc_html(basename((string) $value)) . '</a>';
            } else {
                echo nl2br(esc_html((string) $value));
            }
            echo '</td></tr>';
        }

        echo '</tbody></table>';
    }

    /**
     * Adds the CSV export bulk action to the submissions list table.
     *
     * @param array<string, string> $actions Existing bulk actions.
     *
     * @return array<string, string>
     */
    public function filter_submission_bulk_actions(array $actions): array {
        $actions['swiftforms_export_csv'] = 'Export to CSV';

        return $actions;
    }

    /**
     * Streams selected submissions as a CSV download.
     *
     * @param string $redirect_to Default redirect URL.
     * @param string $action      Bulk action being performed.
     * @param int[]  $post_ids    Selected submission IDs.
     */
    public function handle_submission_bulk_actions(string $redirect_to, string $action, array $post_ids): string {
        if ('swiftforms_export_csv' !== $action) {
            return $redirect_to;
        }

        if (!current_user_can('edit_posts') || empty($post_ids)) {
            return $redirect_to;
        }

        $rows = $this->build_export_rows($post_ids);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="swiftforms-submissions.csv"');

        $handle = fopen('php://output', 'w');
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }
        fclose($handle);

        exit;
    }

    /**
     * Builds the CSV matrix (header row plus one row per submission).
     *
     * Kept separate from the streaming handler so the column logic is unit testable.
     *
     * @param int[] $post_ids Submission IDs to export.
     *
     * @return array<int, array<int, string>>
     */
    public function build_export_rows(array $post_ids): array {
        $field_keys = $this->collect_field_keys($post_ids);
        $labels = array_map(array($this, 'format_field_label'), $field_keys);

        $rows = array(array_merge(array('ID', 'Title', 'Date'), $labels));

        foreach ($post_ids as $post_id) {
            $post_id = (int) $post_id;
            $post = get_post($post_id);

            if (!$post instanceof WP_Post || self::SUBMISSION_POST_TYPE !== $post->post_type) {
                continue;
            }

            $values = $this->get_submission_field_values($post_id);
            $row = array(
                (string) $post_id,
                get_the_title($post) ?: '',
                get_the_date('Y-m-d H:i:s', $post) ?: '',
            );

            foreach ($field_keys as $slug) {
                $value = isset($values[$slug]) ? (string) $values[$slug] : '';
                $file_url = $this->resolve_uploaded_file_url($value);
                $row[] = '' !== $file_url ? $file_url : $value;
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Collects the union of field slugs across the given submissions.
     *
     * @param int[] $post_ids Submission IDs.
     *
     * @return string[]
     */
    private function collect_field_keys(array $post_ids): array {
        $keys = array();

        foreach ($post_ids as $post_id) {
            foreach (array_keys($this->get_submission_field_values((int) $post_id)) as $slug) {
                $keys[$slug] = true;
            }
        }

        return array_keys($keys);
    }

    /**
     * Returns the `_sf_field_*` meta for a submission keyed by field slug.
     *
     * @param int $post_id Submission ID.
     *
     * @return array<string, string>
     */
    private function get_submission_field_values(int $post_id): array {
        $meta = get_post_meta($post_id);
        $fields = array();

        if (!is_array($meta)) {
            return $fields;
        }

        foreach ($meta as $key => $value) {
            if (0 !== strpos((string) $key, '_sf_field_')) {
                continue;
            }

            $slug = substr((string) $key, strlen('_sf_field_'));
            $fields[$slug] = is_array($value) ? (string) reset($value) : (string) $value;
        }

        return $fields;
    }

    /**
     * Converts a stored uploaded-file path into a public URL, or '' if not a file.
     */
    private function resolve_uploaded_file_url(string $value): string {
        if ('' === $value) {
            return '';
        }

        $uploads = wp_upload_dir();
        $basedir = trailingslashit($uploads['basedir']) . 'swiftforms/';

        if (0 !== strpos($value, $basedir)) {
            return '';
        }

        return $uploads['baseurl'] . '/swiftforms/' . ltrim(substr($value, strlen($basedir)), '/');
    }

    /**
     * Turns a field slug into a human-readable label.
     */
    private function format_field_label(string $slug): string {
        return ucwords(str_replace(array('_', '-'), ' ', $slug));
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
            // 'custom-fields' is required for WordPress's REST API to expose
            // and accept the `meta` field at all; without it, _sf_settings
            // (the Form Experience / Notifications sidebar panel) can never
            // be read or saved through the block editor.
            'supports' => array('title', 'editor', 'thumbnail', 'custom-fields'),
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