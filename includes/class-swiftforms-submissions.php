<?php
/**
 * Submission processing.
 */

declare(strict_types=1);

class SwiftForms_Submissions {
    /**
     * Handles a frontend submission request.
     *
     * Tests to create:
     * - test_handle_submission_rejects_invalid_nonce: Pass an invalid nonce and expect a response array with success false and code invalid_nonce.
     * - test_handle_submission_silently_ignores_honeypot_bots: Pass a populated honeypot and expect a success response with code spam_blocked.
     * - test_handle_submission_creates_submission_for_valid_payload: Pass a valid request and expect success true with a numeric submission_id.
        * - test_handle_submission_fires_pre_and_post_submission_hooks: Submit a valid payload and expect both lifecycle hooks to fire exactly once.
        * - test_handle_submission_persists_uploaded_file_meta: Submit a file field and expect the saved meta value to point to the hashed upload path.
     *
     * Expected output:
     * - Invalid nonces fail immediately.
     * - Honeypot spam is absorbed without exposing validation details.
     * - Valid payloads create a submission post and field meta rows.
    * - Valid payloads trigger notification delivery after persistence succeeds.
     *
     * @param array<string, mixed>|null $request Optional request data for direct testing.
     *
     * @return array<string, mixed>
     */
    public function handle_submission(?array $request = null): array {
        $should_send_json = null === $request;
        $request = null === $request ? wp_unslash($_POST) : $request;

        if ($should_send_json) {
            $request = $this->merge_uploaded_files($request, $_FILES['swiftforms_files'] ?? array());
        }

        $request = $this->normalize_request($request);

        $nonce = isset($request['nonce']) ? (string) $request['nonce'] : '';
        if (!$this->verify_nonce($nonce)) {
            $response = array(
                'success' => false,
                'code' => 'invalid_nonce',
                'message' => 'The form session has expired.',
            );

            return $this->maybe_send_json($response, $should_send_json, 400);
        }

        $honeypot = isset($request['honeypot']) ? (string) $request['honeypot'] : '';
        if (!$this->validate_honeypot($honeypot)) {
            $response = array(
                'success' => true,
                'code' => 'spam_blocked',
                'message' => 'Submission ignored.',
            );

            return $this->maybe_send_json($response, $should_send_json, 200);
        }

        if (!$this->validate_captcha($request)) {
            $response = array(
                'success' => false,
                'code' => 'invalid_captcha',
                'message' => 'The captcha answer is incorrect.',
            );

            return $this->maybe_send_json($response, $should_send_json, 400);
        }

        $field_errors = $this->validate_fields($request);
        if (!empty($field_errors)) {
            $response = array(
                'success' => false,
                'code' => 'validation_failed',
                'errors' => $field_errors,
            );

            return $this->maybe_send_json($response, $should_send_json, 400);
        }

        do_action('swiftforms_pre_submission', $request, $this);

        $submission_id = $this->create_submission_post($request);
        if (is_wp_error($submission_id)) {
            $response = array(
                'success' => false,
                'code' => $submission_id->get_error_code(),
                'message' => $submission_id->get_error_message(),
            );

            return $this->maybe_send_json($response, $should_send_json, 500);
        }

        $this->save_field_meta($submission_id, $request['fields'] ?? array());
        $this->send_notifications($submission_id, $request);

        do_action('swiftforms_post_submission', $submission_id, $request, $this);

        $response = array(
            'success' => true,
            'message' => 'Form submitted successfully.',
            'submission_id' => $submission_id,
        );

        return $this->maybe_send_json($response, $should_send_json, 200);
    }

    /**
     * Verifies the AJAX nonce.
     *
     * Tests to create:
     * - test_verify_nonce_accepts_valid_token: Generate wp_create_nonce('swiftforms_ajax') and expect verify_nonce() to return true.
     * - test_verify_nonce_rejects_invalid_token: Pass a random value and expect verify_nonce() to return false.
     *
     * Expected output:
     * - Only nonce values generated for the swiftforms_ajax action are accepted.
     */
    public function verify_nonce(string $nonce): bool {
        return 1 === wp_verify_nonce($nonce, 'swiftforms_ajax') || 2 === wp_verify_nonce($nonce, 'swiftforms_ajax');
    }

    /**
     * Validates the honeypot anti-spam field.
     *
     * Tests to create:
     * - test_validate_honeypot_accepts_empty_value: Pass an empty string and expect true.
     * - test_validate_honeypot_rejects_populated_value: Pass any non-empty string and expect false.
     *
     * Expected output:
     * - Empty honeypot values pass and populated values fail.
     */
    public function validate_honeypot(string $value): bool {
        return '' === trim($value);
    }

    /**
     * Validates the optional math captcha values in the request.
     *
     * Tests to create:
     * - test_validate_captcha_passes_when_no_challenge_exists: Call validate_captcha() without captcha keys and expect true.
     * - test_validate_captcha_accepts_correct_answer: Pass matching captcha answer and sum and expect true.
     * - test_validate_captcha_rejects_wrong_answer: Pass a mismatched answer and expect false.
     *
     * Expected output:
     * - Missing captcha configuration is treated as disabled.
     * - Matching answers pass and mismatches fail.
     *
     * @param array<string, mixed> $request Submission payload.
     */
    public function validate_captcha(array $request): bool {
        if (!isset($request['captcha_expected'])) {
            return true;
        }

        $expected = (int) $request['captcha_expected'];
        $answer = isset($request['captcha_answer']) ? (int) $request['captcha_answer'] : PHP_INT_MIN;

        return $expected === $answer;
    }

    /**
     * Validates a value for a specific field type.
     *
     * Tests to create:
     * - test_validate_field_type_accepts_valid_email: Pass email with user@example.com and expect true.
     * - test_validate_field_type_rejects_invalid_email: Pass email with invalid-email and expect a WP_Error with code invalid_email.
     * - test_validate_field_type_rejects_large_files: Pass a file array above wp_max_upload_size() and expect a WP_Error with code file_too_large.
     *
     * Expected output:
     * - Supported field types return true for valid values or WP_Error for invalid input.
     *
     * @param mixed                $value Submitted value.
     * @param array<string, mixed> $field Submitted field configuration.
     *
     * @return true|WP_Error
     */
    public function validate_field_type(string $type, mixed $value, array $field = array()) {
        if ('email' === $type) {
            if (!is_string($value) || !is_email($value)) {
                return new WP_Error('invalid_email', 'Please enter a valid email address.');
            }

            return true;
        }

        if ('url' === $type) {
            if (!is_string($value) || false === filter_var($value, FILTER_VALIDATE_URL)) {
                return new WP_Error('invalid_url', 'Please enter a valid URL.');
            }

            return true;
        }

        if ('number' === $type) {
            if (!is_scalar($value) || '' === trim((string) $value) || !is_numeric((string) $value)) {
                return new WP_Error('invalid_number', 'Please enter a valid number.');
            }

            $numeric_value = (float) $value;

            if (isset($field['min']) && '' !== (string) $field['min'] && $numeric_value < (float) $field['min']) {
                return new WP_Error('invalid_number_min', 'Please enter a number above the minimum value.');
            }

            if (isset($field['max']) && '' !== (string) $field['max'] && $numeric_value > (float) $field['max']) {
                return new WP_Error('invalid_number_max', 'Please enter a number below the maximum value.');
            }

            if (isset($field['step']) && '' !== (string) $field['step']) {
                $step = (float) $field['step'];
                if ($step > 0) {
                    $minimum = isset($field['min']) && '' !== (string) $field['min'] ? (float) $field['min'] : 0.0;
                    $offset = ($numeric_value - $minimum) / $step;

                    if (abs($offset - round($offset)) > 0.00001) {
                        return new WP_Error('invalid_number_step', 'Please enter a valid increment.');
                    }
                }
            }

            return true;
        }

        if ('tel' === $type) {
            if (!is_string($value) || '' === trim($value) || 1 !== preg_match('/^\+?[0-9\s().-]{6,20}$/', $value)) {
                return new WP_Error('invalid_tel', 'Please enter a valid phone number.');
            }

            return true;
        }

        if ('select' === $type) {
            $string_value = is_scalar($value) ? trim((string) $value) : '';
            $options = $this->normalize_select_options($field['options'] ?? array());

            if ('' === $string_value) {
                if ($this->is_field_required($field)) {
                    return new WP_Error('required_select', 'Please select an option.');
                }

                return true;
            }

            if (!empty($options) && !in_array($string_value, $options, true)) {
                return new WP_Error('invalid_select', 'Please select a valid option.');
            }

            return true;
        }

        if ('checkbox' === $type) {
            $string_value = is_scalar($value) ? trim((string) $value) : '';

            if ($this->is_field_required($field) && '' === $string_value) {
                return new WP_Error('required_checkbox', 'Please check this box to continue.');
            }

            return true;
        }

        if ('file' === $type) {
            if (!is_array($value)) {
                return new WP_Error('invalid_file', 'The uploaded file is invalid.');
            }

            $size = isset($value['size']) ? (int) $value['size'] : 0;
            if ($size > wp_max_upload_size()) {
                return new WP_Error('file_too_large', 'File too large.');
            }

            return true;
        }

        return true;
    }

    /**
     * Creates the submission post wrapper.
     *
     * Tests to create:
     * - test_create_submission_post_creates_submission_type: Call create_submission_post() and expect a new swiftforms_submission post id.
     * - test_create_submission_post_sets_human_readable_title: Call create_submission_post() and expect the saved title to be Submission #<id>.
     *
     * Expected output:
     * - A private submission post is created and renamed after insertion.
     *
     * @param array<string, mixed> $request Submission payload.
     *
     * @return int|WP_Error
     */
    public function create_submission_post(array $request) {
        $post_id = wp_insert_post(
            array(
                'post_type' => SwiftForms_CPTs::SUBMISSION_POST_TYPE,
                'post_status' => 'private',
                'post_title' => 'Submission',
                'meta_input' => array(
                    '_sf_form_id' => isset($request['form_id']) ? (int) $request['form_id'] : 0,
                ),
            ),
            true
        );

        if (is_wp_error($post_id)) {
            return $post_id;
        }

        wp_update_post(
            array(
                'ID' => $post_id,
                'post_title' => sprintf('Submission #%d', $post_id),
            )
        );

        return $post_id;
    }

    /**
     * Saves each submitted field as post meta.
     *
     * Tests to create:
     * - test_save_field_meta_saves_meta_by_slug: Save one field and expect _sf_field_<slug> to exist with the submitted value.
     * - test_save_field_meta_skips_incomplete_field_rows: Pass a field without slug and expect no meta to be created.
    * - test_handle_submission_persists_uploaded_file_meta: Pass a file field and expect the stored meta value to be the uploaded file path.
     *
     * Expected output:
     * - Each valid field row is stored under the _sf_field_<slug> naming convention.
     *
     * @param array<int, array<string, mixed>> $fields Submitted field rows.
     */
    public function save_field_meta(int $post_id, array $fields): void {
        foreach ($fields as $field) {
            if (empty($field['slug'])) {
                continue;
            }

            $slug = sanitize_key((string) $field['slug']);
            $type = isset($field['type']) ? (string) $field['type'] : 'text';
            $value = $field['value'] ?? '';

            if ('file' === $type && is_array($value)) {
                $upload = $this->handle_file_upload($value);

                if (!is_wp_error($upload)) {
                    update_post_meta($post_id, '_sf_field_' . $slug, $upload['path']);
                }

                continue;
            }

            if (is_scalar($value)) {
                update_post_meta($post_id, '_sf_field_' . $slug, (string) $value);
            }
        }
    }

    /**
     * Sends admin and auto-responder notifications for a stored submission.
     *
     * Tests to create:
     * - test_handle_submission_sends_admin_and_autoresponder_notifications: Submit a valid payload with an email field and expect two wp_mail calls.
     * - test_handle_submission_applies_email_content_filter: Filter the email body and expect the filtered content in the admin notification.
     *
     * Expected output:
     * - The admin email receives a notification for every valid submission.
     * - The auto-responder is sent when a valid email field is present.
     * - Email body content passes through the swiftforms_email_content filter.
     *
     * @param array<string, mixed> $request Submission payload.
     */
    public function send_notifications(int $submission_id, array $request): void {
        $notification_config = $this->resolve_notification_config($submission_id, $request);

        $admin_message = $this->build_email_content('admin', $submission_id, $request, $notification_config['adminTemplate']);
        wp_mail($notification_config['adminRecipients'], $notification_config['adminSubject'], $admin_message);

        $autoresponder_recipient = $this->get_autoresponder_recipient($request['fields'] ?? array());
        if ('' !== $autoresponder_recipient) {
            $autoresponder_message = $this->build_email_content('autoresponder', $submission_id, $request, $notification_config['autoresponderTemplate']);
            wp_mail($autoresponder_recipient, $notification_config['autoresponderSubject'], $autoresponder_message);
        }
    }

    /**
     * Builds the text body for a notification email.
     *
     * Tests to create:
     * - test_handle_submission_applies_email_content_filter: Filter the returned content and expect the final message to include the filtered marker.
     *
     * Expected output:
     * - The body contains the submission id and submitted scalar field values.
     * - The body is passed through swiftforms_email_content with the context string.
     *
     * @param array<string, mixed> $request Submission payload.
     */
    public function build_email_content(string $context, int $submission_id, array $request, string $template = ''): string {
        $message = '' !== trim($template)
            ? $this->render_notification_template($template, $submission_id, $request)
            : $this->get_default_email_content($submission_id, $request);

        return (string) apply_filters('swiftforms_email_content', $message, $context, $submission_id, $request);
    }

    /**
     * Resolves notification recipients, subjects, and templates from the request.
     *
     * @param array<string, mixed> $request Submission payload.
     *
     * @return array<string, array<int, string>|string>
     */
    public function resolve_notification_config(int $submission_id, array $request): array {
        $form_id = isset($request['form_id']) ? (int) $request['form_id'] : 0;
        $stored_settings = $form_id > 0 ? SwiftForms_CPTs::get_form_settings($form_id) : SwiftForms_CPTs::get_default_form_settings();
        $config = isset($request['notifications']) && is_array($request['notifications'])
            ? $request['notifications']
            : array();

        $admin_recipients = $this->parse_notification_recipients($config['adminRecipients'] ?? $stored_settings['adminRecipients'] ?? get_option('admin_email'));
        if (empty($admin_recipients)) {
            $admin_recipients = array((string) get_option('admin_email'));
        }

        $admin_subject_template = isset($config['adminSubject']) && '' !== trim((string) $config['adminSubject'])
            ? (string) $config['adminSubject']
            : (string) $stored_settings['adminSubject'];
        $autoresponder_subject_template = isset($config['autoresponderSubject']) && '' !== trim((string) $config['autoresponderSubject'])
            ? (string) $config['autoresponderSubject']
            : (string) $stored_settings['autoresponderSubject'];

        return array(
            'adminRecipients' => $admin_recipients,
            'adminSubject' => $this->render_notification_template($admin_subject_template, $submission_id, $request),
            'adminTemplate' => isset($config['adminTemplate']) && '' !== trim((string) $config['adminTemplate'])
                ? (string) $config['adminTemplate']
                : (string) $stored_settings['adminTemplate'],
            'autoresponderSubject' => $this->render_notification_template($autoresponder_subject_template, $submission_id, $request),
            'autoresponderTemplate' => isset($config['autoresponderTemplate']) && '' !== trim((string) $config['autoresponderTemplate'])
                ? (string) $config['autoresponderTemplate']
                : (string) $stored_settings['autoresponderTemplate'],
        );
    }

    /**
     * Splits recipient config strings into a clean recipient list.
     *
     * @param mixed $recipients Raw configured recipients.
     *
     * @return string[]
     */
    public function parse_notification_recipients(mixed $recipients): array {
        if (is_array($recipients)) {
            $candidate_recipients = $recipients;
        } else {
            $candidate_recipients = preg_split('/[\r\n,;]+/', (string) $recipients) ?: array();
        }

        $parsed = array();

        foreach ($candidate_recipients as $candidate) {
            $candidate = trim((string) $candidate);

            if ('' !== $candidate && is_email($candidate)) {
                $parsed[] = $candidate;
            }
        }

        return array_values(array_unique($parsed));
    }

    /**
     * Renders a simple notification template with submission placeholders.
     *
     * Supported placeholders: {submission_id}, {form_id}, {fields}, and {field:slug}.
     *
     * @param array<string, mixed> $request Submission payload.
     */
    public function render_notification_template(string $template, int $submission_id, array $request): string {
        $field_map = $this->get_scalar_field_map($request['fields'] ?? array());

        $rendered = str_replace(
            array('{submission_id}', '{form_id}', '{fields}'),
            array(
                (string) $submission_id,
                (string) (isset($request['form_id']) ? (int) $request['form_id'] : 0),
                $this->format_field_lines($field_map),
            ),
            $template
        );

        return (string) preg_replace_callback(
            '/\{field:([a-z0-9_\-]+)\}/i',
            static function (array $matches) use ($field_map): string {
                $slug = sanitize_key($matches[1]);

                return $field_map[$slug] ?? '';
            },
            $rendered
        );
    }

    /**
     * Returns the default email body when no template is configured.
     *
     * @param array<string, mixed> $request Submission payload.
     */
    private function get_default_email_content(int $submission_id, array $request): string {
        $lines = array(
            sprintf('Submission ID: %d', $submission_id),
            sprintf('Form ID: %d', isset($request['form_id']) ? (int) $request['form_id'] : 0),
        );

        foreach ($this->get_scalar_field_map($request['fields'] ?? array()) as $slug => $value) {
            $lines[] = sprintf('%s: %s', $slug, $value);
        }

        return implode("\n", $lines);
    }

    /**
     * Returns scalar submission fields keyed by sanitized slug.
     *
     * @param mixed $fields Submitted field rows.
     *
     * @return array<string, string>
     */
    private function get_scalar_field_map(mixed $fields): array {
        if (!is_array($fields)) {
            return array();
        }

        $field_map = array();

        foreach ($fields as $field) {
            $slug = isset($field['slug']) ? sanitize_key((string) $field['slug']) : '';
            $value = $field['value'] ?? '';

            if ('' === $slug || !is_scalar($value)) {
                continue;
            }

            $field_map[$slug] = (string) $value;
        }

        return $field_map;
    }

    /**
     * Formats scalar fields for the {fields} template placeholder.
     *
     * @param array<string, string> $field_map Scalar submission fields.
     */
    private function format_field_lines(array $field_map): string {
        $lines = array();

        foreach ($field_map as $slug => $value) {
            $lines[] = sprintf('%s: %s', $slug, $value);
        }

        return implode("\n", $lines);
    }

    /**
     * Finds the first valid email field for auto-responder delivery.
     *
     * Tests to create:
     * - test_handle_submission_sends_admin_and_autoresponder_notifications: Submit a payload with an email field and expect the email value to be used.
     *
     * Expected output:
     * - Returns the first valid submitted email address or an empty string.
     *
     * @param array<int, array<string, mixed>> $fields Submitted field rows.
     */
    public function get_autoresponder_recipient(array $fields): string {
        foreach ($fields as $field) {
            $type = isset($field['type']) ? (string) $field['type'] : '';
            $value = $field['value'] ?? '';

            if ('email' === $type && is_string($value) && is_email($value)) {
                return $value;
            }
        }

        return '';
    }

    /**
     * Handles a validated file upload into the SwiftForms uploads directory.
     *
     * Tests to create:
     * - test_handle_file_upload_moves_file_into_swiftforms_directory: Provide a temp file and expect the returned path to include /swiftforms/.
     * - test_handle_file_upload_renames_file_to_hash: Provide a temp file and expect the basename to be a sha256 hash plus extension.
     * - test_handle_file_upload_rejects_disallowed_type: Provide an unsupported extension and expect a WP_Error with code invalid_file_type.
     *
     * Expected output:
     * - Allowed files are copied into uploads/swiftforms/YYYY/MM with hashed names.
     * - Unsupported file types return WP_Error.
     *
     * @param array<string, mixed> $file Uploaded file array.
     *
     * @return array<string, string>|WP_Error
     */
    public function handle_file_upload(array $file) {
        $tmp_name = isset($file['tmp_name']) ? (string) $file['tmp_name'] : '';
        $original_name = isset($file['name']) ? (string) $file['name'] : '';

        if ('' === $tmp_name || !file_exists($tmp_name)) {
            return new WP_Error('missing_file', 'The uploaded file could not be found.');
        }

        $validation = $this->validate_field_type('file', $file);
        if (is_wp_error($validation)) {
            return $validation;
        }

        $allowed_types = apply_filters(
            'swiftforms_allowed_upload_types',
            array(
                'jpg|jpeg|jpe' => 'image/jpeg',
                'pdf' => 'application/pdf',
                'png' => 'image/png',
                'txt' => 'text/plain',
            )
        );

        $filetype = wp_check_filetype($original_name, $allowed_types);
        if (empty($filetype['ext']) || empty($filetype['type'])) {
            return new WP_Error('invalid_file_type', 'File type not allowed.');
        }

        $uploads = wp_upload_dir();
        $subdir = '/swiftforms/' . gmdate('Y') . '/' . gmdate('m');
        $target_dir = $uploads['basedir'] . $subdir;

        wp_mkdir_p($target_dir);

        $hashed_name = hash_file('sha256', $tmp_name) . '.' . $filetype['ext'];
        $target_path = wp_unique_filename($target_dir, $hashed_name);
        $destination = trailingslashit($target_dir) . $target_path;

        if (!copy($tmp_name, $destination)) {
            return new WP_Error('upload_failed', 'The uploaded file could not be stored.');
        }

        return array(
            'file' => $destination,
            'path' => $destination,
            'type' => $filetype['type'],
            'url' => $uploads['baseurl'] . $subdir . '/' . basename($destination),
        );
    }

    /**
     * Validates the submitted field collection.
     *
     * @param array<string, mixed> $request Submission payload.
     *
     * @return array<string, string>
     */
    private function validate_fields(array $request): array {
        $errors = array();
        $fields = $request['fields'] ?? array();

        if (!is_array($fields)) {
            return array('fields' => 'Submitted fields are invalid.');
        }

        foreach ($fields as $field) {
            $slug = isset($field['slug']) ? sanitize_key((string) $field['slug']) : '';
            $type = isset($field['type']) ? (string) $field['type'] : 'text';
            $value = $field['value'] ?? '';

            $validation = $this->validate_field_type($type, $value, is_array($field) ? $field : array());
            if (is_wp_error($validation)) {
                $errors[$slug ?: 'field'] = $validation->get_error_message();
            }
        }

        return $errors;
    }

    /**
     * Merges uploaded files into the normalized request field payload.
     *
        * Tests to create:
        * - test_handle_submission_merges_live_uploaded_files_from_superglobal_request: Populate $_POST and $_FILES for a file field and expect the merged upload to persist like a normal file submission.
        *
        * Expected output:
        * - Uploaded files posted via the live AJAX request are merged into the matching field rows before validation and persistence.
        *
     * @param array<string, mixed> $request Submission payload.
     * @param mixed                $uploaded_files Raw uploaded file data.
     *
     * @return array<string, mixed>
     */
    private function merge_uploaded_files(array $request, mixed $uploaded_files): array {
        $fields = $request['fields'] ?? array();

        if (!is_array($fields) || !is_array($uploaded_files) || !isset($uploaded_files['name']) || !is_array($uploaded_files['name'])) {
            return $request;
        }

        foreach ($uploaded_files['name'] as $index => $name) {
            if (!isset($fields[$index]) || !is_array($fields[$index])) {
                continue;
            }

            $fields[$index]['value'] = array(
                'name' => (string) $name,
                'size' => isset($uploaded_files['size'][$index]) ? (int) $uploaded_files['size'][$index] : 0,
                'tmp_name' => isset($uploaded_files['tmp_name'][$index]) ? (string) $uploaded_files['tmp_name'][$index] : '',
            );
        }

        $request['fields'] = $fields;

        return $request;
    }

    /**
     * Normalizes scalar field values and request-level field configuration.
     *
     * @param array<string, mixed> $request Submission payload.
     *
     * @return array<string, mixed>
     */
    private function normalize_request(array $request): array {
        $fields = $request['fields'] ?? array();

        if (!is_array($fields)) {
            return $request;
        }

        foreach ($fields as $index => $field) {
            if (!is_array($field)) {
                continue;
            }

            $type = isset($field['type']) ? (string) $field['type'] : 'text';
            $value = $field['value'] ?? '';

            if (isset($field['slug'])) {
                $field['slug'] = sanitize_key((string) $field['slug']);
            }

            if (isset($field['required'])) {
                $field['required'] = $this->is_truthy($field['required']);
            }

            if ('select' === $type && array_key_exists('options', $field)) {
                $field['options'] = $this->normalize_select_options($field['options']);
            }

            if ('number' === $type) {
                foreach (array('min', 'max', 'step') as $numeric_key) {
                    if (isset($field[$numeric_key])) {
                        $field[$numeric_key] = trim((string) $field[$numeric_key]);
                    }
                }
            }

            $field['value'] = $this->normalize_field_value($type, $value);
            $fields[$index] = $field;
        }

        $request['fields'] = $fields;

        return $request;
    }

    /**
     * Normalizes a scalar field value for persistence and email rendering.
     */
    private function normalize_field_value(string $type, mixed $value): mixed {
        if ('file' === $type || !is_scalar($value)) {
            return $value;
        }

        $string_value = trim((string) $value);

        if ('number' === $type && '' !== $string_value && is_numeric($string_value)) {
            if (preg_match('/^-?\d+$/', $string_value)) {
                return (string) (int) $string_value;
            }

            return rtrim(rtrim(sprintf('%.10F', (float) $string_value), '0'), '.');
        }

        return $string_value;
    }

    /**
     * Normalizes select options into a trimmed list.
     *
     * @param mixed $options Raw option configuration.
     *
     * @return string[]
     */
    private function normalize_select_options(mixed $options): array {
        if (is_array($options)) {
            $candidate_options = $options;
        } else {
            $candidate_options = preg_split('/\r?\n/', (string) $options) ?: array();
        }

        $normalized_options = array();

        foreach ($candidate_options as $option) {
            $option = trim((string) $option);

            if ('' !== $option) {
                $normalized_options[] = $option;
            }
        }

        return array_values(array_unique($normalized_options));
    }

    /**
     * Returns whether a field is marked as required.
     *
     * @param array<string, mixed> $field Submitted field configuration.
     */
    private function is_field_required(array $field): bool {
        return isset($field['required']) && $this->is_truthy($field['required']);
    }

    /**
     * Normalizes common truthy request values.
     */
    private function is_truthy(mixed $value): bool {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string) $value)), array('1', 'true', 'on', 'yes'), true);
    }

    /**
     * Sends a JSON response for live AJAX requests and always returns the payload.
     *
     * @param array<string, mixed> $response Response payload.
     *
     * @return array<string, mixed>
     */
    private function maybe_send_json(array $response, bool $should_send_json, int $status_code): array {
        if ($should_send_json && wp_doing_ajax()) {
            wp_send_json($response, $status_code);
        }

        return $response;
    }
}