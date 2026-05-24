<?php
/**
 * Tests for submission handling.
 */

declare(strict_types=1);

class SwiftForms_Submissions_Test extends WP_UnitTestCase {
    private SwiftForms_Submissions $submissions;

    /**
     * Tracks hook calls for submission lifecycle assertions.
     *
     * @var array<int, array<string, mixed>>
     */
    private array $pre_submission_calls = array();

    /**
     * Tracks hook calls for submission lifecycle assertions.
     *
     * @var array<int, array<string, mixed>>
     */
    private array $post_submission_calls = array();

    /**
     * Captured wp_mail calls.
     *
     * @var array<int, array<string, mixed>>
     */
    private array $mail_calls = array();

    public function set_up(): void {
        parent::set_up();

        (new SwiftForms_CPTs())->register();
        $this->submissions = new SwiftForms_Submissions();
        $this->pre_submission_calls = array();
        $this->post_submission_calls = array();
        $this->mail_calls = array();

        add_action($this->get_pre_submission_hook_name(), array($this, 'capture_pre_submission'), 10, 2);
        add_action($this->get_post_submission_hook_name(), array($this, 'capture_post_submission'), 10, 3);
        add_filter('pre_wp_mail', array($this, 'capture_mail_call'), 10, 2);
    }

    public function tear_down(): void {
        remove_action($this->get_pre_submission_hook_name(), array($this, 'capture_pre_submission'), 10);
        remove_action($this->get_post_submission_hook_name(), array($this, 'capture_post_submission'), 10);
        remove_filter('pre_wp_mail', array($this, 'capture_mail_call'), 10);
        remove_all_filters('swiftforms_email_content');

        parent::tear_down();
    }

    public function test_verify_nonce_accepts_valid_token(): void {
        $nonce = wp_create_nonce('swiftforms_ajax');

        $this->assertTrue($this->submissions->verify_nonce($nonce));
    }

    public function test_verify_nonce_rejects_invalid_token(): void {
        $this->assertFalse($this->submissions->verify_nonce('invalid'));
    }

    public function test_validate_honeypot_accepts_empty_value(): void {
        $this->assertTrue($this->submissions->validate_honeypot(''));
    }

    public function test_validate_honeypot_rejects_populated_value(): void {
        $this->assertFalse($this->submissions->validate_honeypot('bot-data'));
    }

    public function test_validate_captcha_accepts_correct_answer(): void {
        $this->assertTrue(
            $this->submissions->validate_captcha(
                array(
                    'captcha_answer' => 4,
                    'captcha_expected' => 4,
                )
            )
        );
    }

    public function test_validate_captcha_rejects_wrong_answer(): void {
        $this->assertFalse(
            $this->submissions->validate_captcha(
                array(
                    'captcha_answer' => 5,
                    'captcha_expected' => 4,
                )
            )
        );
    }

    public function test_validate_field_type_accepts_valid_email(): void {
        $this->assertTrue($this->submissions->validate_field_type('email', 'person@example.com'));
    }

    public function test_validate_field_type_rejects_invalid_email(): void {
        $result = $this->submissions->validate_field_type('email', 'invalid-email');

        $this->assertWPError($result);
        $this->assertSame('invalid_email', $result->get_error_code());
    }

    public function test_create_submission_post_creates_submission_type(): void {
        $post_id = $this->submissions->create_submission_post(array('form_id' => 10));

        $this->assertIsInt($post_id);
        $this->assertSame(SwiftForms_CPTs::SUBMISSION_POST_TYPE, get_post_type($post_id));
        $this->assertSame('Submission #' . $post_id, get_post($post_id)->post_title);
    }

    public function test_save_field_meta_saves_meta_by_slug(): void {
        $post_id = self::factory()->post->create(array('post_type' => SwiftForms_CPTs::SUBMISSION_POST_TYPE));

        $this->submissions->save_field_meta(
            $post_id,
            array(
                array(
                    'slug' => 'user_email',
                    'value' => 'test@example.com',
                ),
            )
        );

        $this->assertSame('test@example.com', get_post_meta($post_id, '_sf_field_user_email', true));
    }

    public function test_handle_submission_rejects_invalid_nonce(): void {
        $response = $this->submissions->handle_submission(
            array(
                'nonce' => 'invalid',
            )
        );

        $this->assertFalse($response['success']);
        $this->assertSame('invalid_nonce', $response['code']);
    }

    public function test_handle_submission_silently_ignores_honeypot_bots(): void {
        $response = $this->submissions->handle_submission(
            array(
                'nonce' => wp_create_nonce('swiftforms_ajax'),
                'honeypot' => 'bot-data',
            )
        );

        $this->assertTrue($response['success']);
        $this->assertSame('spam_blocked', $response['code']);
    }

    public function test_handle_submission_creates_submission_for_valid_payload(): void {
        $response = $this->submissions->handle_submission(
            array(
                'nonce' => wp_create_nonce('swiftforms_ajax'),
                'honeypot' => '',
                'fields' => array(
                    array(
                        'slug' => 'email',
                        'type' => 'email',
                        'value' => 'person@example.com',
                    ),
                ),
                'form_id' => 22,
            )
        );

        $this->assertTrue($response['success']);
        $this->assertIsInt($response['submission_id']);
        $this->assertSame('person@example.com', get_post_meta($response['submission_id'], '_sf_field_email', true));
    }

    public function test_handle_submission_fires_pre_and_post_submission_hooks(): void {
        $request = array(
            'nonce' => wp_create_nonce('swiftforms_ajax'),
            'honeypot' => '',
            'fields' => array(
                array(
                    'slug' => 'email',
                    'type' => 'email',
                    'value' => 'person@example.com',
                ),
            ),
            'form_id' => 33,
        );

        $response = $this->submissions->handle_submission($request);

        $this->assertTrue($response['success']);
        $this->assertCount(1, $this->pre_submission_calls);
        $this->assertCount(1, $this->post_submission_calls);
        $this->assertSame($request['form_id'], $this->pre_submission_calls[0]['request']['form_id']);
        $this->assertSame($response['submission_id'], $this->post_submission_calls[0]['submission_id']);
    }

    public function test_handle_submission_persists_uploaded_file_meta(): void {
        $tmp_file = wp_tempnam('swiftforms-upload.txt');
        file_put_contents($tmp_file, 'swiftforms test payload');

        $response = $this->submissions->handle_submission(
            array(
                'nonce' => wp_create_nonce('swiftforms_ajax'),
                'honeypot' => '',
                'fields' => array(
                    array(
                        'slug' => 'attachment',
                        'type' => 'file',
                        'value' => array(
                            'name' => 'notes.txt',
                            'size' => filesize($tmp_file),
                            'tmp_name' => $tmp_file,
                        ),
                    ),
                ),
                'form_id' => 44,
            )
        );

        $this->assertTrue($response['success']);

        $saved_value = get_post_meta($response['submission_id'], '_sf_field_attachment', true);

        $this->assertIsString($saved_value);
        $this->assertStringContainsString('/swiftforms/', $saved_value);
        $this->assertFileExists($saved_value);
    }

    public function test_handle_file_upload_rejects_disallowed_type(): void {
        $tmp_file = wp_tempnam('swiftforms-upload.exe');
        file_put_contents($tmp_file, 'binary-ish');

        $result = $this->submissions->handle_file_upload(
            array(
                'name' => 'payload.exe',
                'size' => filesize($tmp_file),
                'tmp_name' => $tmp_file,
            )
        );

        $this->assertWPError($result);
        $this->assertSame('invalid_file_type', $result->get_error_code());
    }

    public function test_handle_submission_sends_admin_and_autoresponder_notifications(): void {
        update_option('admin_email', 'admin@example.org');

        $response = $this->submissions->handle_submission(
            array(
                'nonce' => wp_create_nonce('swiftforms_ajax'),
                'honeypot' => '',
                'fields' => array(
                    array(
                        'slug' => 'name',
                        'type' => 'text',
                        'value' => 'Taylor',
                    ),
                    array(
                        'slug' => 'email',
                        'type' => 'email',
                        'value' => 'person@example.com',
                    ),
                ),
                'form_id' => 55,
            )
        );

        $this->assertTrue($response['success']);
        $this->assertCount(2, $this->mail_calls);
        $this->assertSame(array('admin@example.org'), (array) $this->mail_calls[0]['to']);
        $this->assertSame(array('person@example.com'), (array) $this->mail_calls[1]['to']);
    }

    public function test_handle_submission_applies_email_content_filter(): void {
        add_filter(
            'swiftforms_email_content',
            static function (string $message, string $context): string {
                return $message . "\nfiltered-for:" . $context;
            },
            10,
            2
        );

        $response = $this->submissions->handle_submission(
            array(
                'nonce' => wp_create_nonce('swiftforms_ajax'),
                'honeypot' => '',
                'fields' => array(
                    array(
                        'slug' => 'email',
                        'type' => 'email',
                        'value' => 'person@example.com',
                    ),
                ),
                'form_id' => 66,
            )
        );

        $this->assertTrue($response['success']);
        $this->assertNotEmpty($this->mail_calls);
        $this->assertStringContainsString('filtered-for:admin', $this->mail_calls[0]['message']);
    }

    public function test_handle_submission_uses_configurable_notification_settings(): void {
        $response = $this->submissions->handle_submission(
            array(
                'nonce' => wp_create_nonce('swiftforms_ajax'),
                'honeypot' => '',
                'fields' => array(
                    array(
                        'slug' => 'name',
                        'type' => 'text',
                        'value' => 'Taylor',
                    ),
                    array(
                        'slug' => 'email',
                        'type' => 'email',
                        'value' => 'person@example.com',
                    ),
                ),
                'form_id' => 77,
                'notifications' => array(
                    'adminRecipients' => "ops@example.org\nowner@example.org",
                    'adminSubject' => 'New lead {submission_id}',
                    'adminTemplate' => 'Admin {field:name} {field:email}',
                    'autoresponderSubject' => 'Thanks {field:name}',
                    'autoresponderTemplate' => 'Received {submission_id}',
                ),
            )
        );

        $this->assertTrue($response['success']);
        $this->assertCount(2, $this->mail_calls);
        $this->assertSame(array('ops@example.org', 'owner@example.org'), (array) $this->mail_calls[0]['to']);
        $this->assertSame('New lead ' . $response['submission_id'], $this->mail_calls[0]['subject']);
        $this->assertSame('Admin Taylor person@example.com', $this->mail_calls[0]['message']);
        $this->assertSame('Thanks Taylor', $this->mail_calls[1]['subject']);
        $this->assertSame('Received ' . $response['submission_id'], $this->mail_calls[1]['message']);
    }

    public function capture_pre_submission(array $request, SwiftForms_Submissions $submissions): void {
        $this->pre_submission_calls[] = array(
            'request' => $request,
            'submissions' => $submissions,
        );
    }

    public function capture_post_submission(int $submission_id, array $request, SwiftForms_Submissions $submissions): void {
        $this->post_submission_calls[] = array(
            'submission_id' => $submission_id,
            'request' => $request,
            'submissions' => $submissions,
        );
    }

    /**
     * Short-circuits wp_mail while capturing its payload for assertions.
     *
     * @param array<string, mixed> $atts Mail arguments as passed to wp_mail.
     */
    public function capture_mail_call($return, array $atts): bool {
        $this->mail_calls[] = $atts;

        return true;
    }

    private function get_pre_submission_hook_name(): string {
        return 'swiftforms_pre_submission';
    }

    private function get_post_submission_hook_name(): string {
        return 'swiftforms_post_submission';
    }
}