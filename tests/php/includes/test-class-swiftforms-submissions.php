<?php
/**
 * Tests for submission handling.
 */

declare(strict_types=1);

class SwiftForms_Submissions_Test extends WP_UnitTestCase {
	private SwiftForms_Submissions $submissions;
	private array $original_post  = array();
	private array $original_files = array();

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

		$this->original_post  = $_POST;
		$this->original_files = $_FILES;

		( new SwiftForms_CPTs() )->register();
		$this->submissions           = new SwiftForms_Submissions();
		$this->pre_submission_calls  = array();
		$this->post_submission_calls = array();
		$this->mail_calls            = array();

		add_action( $this->get_pre_submission_hook_name(), array( $this, 'capture_pre_submission' ), 10, 2 );
		add_action( $this->get_post_submission_hook_name(), array( $this, 'capture_post_submission' ), 10, 3 );
		add_filter( 'pre_wp_mail', array( $this, 'capture_mail_call' ), 10, 2 );
	}

	public function tear_down(): void {
		remove_action( $this->get_pre_submission_hook_name(), array( $this, 'capture_pre_submission' ), 10 );
		remove_action( $this->get_post_submission_hook_name(), array( $this, 'capture_post_submission' ), 10 );
		remove_filter( 'pre_wp_mail', array( $this, 'capture_mail_call' ), 10 );
		remove_all_filters( 'swiftforms_email_content' );
		$_POST  = $this->original_post;
		$_FILES = $this->original_files;

		parent::tear_down();
	}

	public function test_verify_nonce_accepts_valid_token(): void {
		$nonce = wp_create_nonce( 'swiftforms_ajax' );

		$this->assertTrue( $this->submissions->verify_nonce( $nonce ) );
	}

	public function test_verify_nonce_rejects_invalid_token(): void {
		$this->assertFalse( $this->submissions->verify_nonce( 'invalid' ) );
	}

	public function test_validate_honeypot_accepts_empty_value(): void {
		$this->assertTrue( $this->submissions->validate_honeypot( '' ) );
	}

	public function test_validate_honeypot_rejects_populated_value(): void {
		$this->assertFalse( $this->submissions->validate_honeypot( 'bot-data' ) );
	}

	public function test_validate_captcha_passes_when_no_challenge_exists(): void {
		$this->assertTrue( $this->submissions->validate_captcha( array() ) );
	}

	public function test_validate_captcha_accepts_correct_answer(): void {
		$issued_at = time();

		$this->assertTrue(
			$this->submissions->validate_captcha(
				array(
					'captcha_answer' => 4,
					'captcha_token'  => $issued_at . '.' . SwiftForms_Submissions::hash_captcha_answer( 4, $issued_at ),
				)
			)
		);
	}

	public function test_validate_captcha_rejects_wrong_answer(): void {
		$issued_at = time();

		$this->assertFalse(
			$this->submissions->validate_captcha(
				array(
					'captcha_answer' => 5,
					'captcha_token'  => $issued_at . '.' . SwiftForms_Submissions::hash_captcha_answer( 4, $issued_at ),
				)
			)
		);
	}

	public function test_validate_captcha_rejects_expired_token(): void {
		$issued_at = time() - ( 60 * 60 );

		$this->assertFalse(
			$this->submissions->validate_captcha(
				array(
					'captcha_answer' => 4,
					'captcha_token'  => $issued_at . '.' . SwiftForms_Submissions::hash_captcha_answer( 4, $issued_at ),
				)
			)
		);
	}

	public function test_validate_field_type_accepts_valid_email(): void {
		$this->assertTrue( $this->submissions->validate_field_type( 'email', 'person@example.com' ) );
	}

	public function test_validate_field_type_rejects_invalid_email(): void {
		$result = $this->submissions->validate_field_type( 'email', 'invalid-email' );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_email', $result->get_error_code() );
	}

	public function test_validate_field_type_accepts_valid_number_with_constraints(): void {
		$this->assertTrue(
			$this->submissions->validate_field_type(
				'number',
				'4',
				array(
					'max'  => '10',
					'min'  => '1',
					'step' => '1',
				)
			)
		);
	}

	public function test_validate_field_type_rejects_number_outside_constraints(): void {
		$result = $this->submissions->validate_field_type(
			'number',
			'12',
			array(
				'max' => '10',
				'min' => '1',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_number_max', $result->get_error_code() );
	}

	public function test_validate_field_type_rejects_invalid_phone_number(): void {
		$result = $this->submissions->validate_field_type( 'tel', 'call-me' );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_tel', $result->get_error_code() );
	}

	public function test_validate_field_type_rejects_select_value_not_in_options(): void {
		$result = $this->submissions->validate_field_type(
			'select',
			'Billing',
			array(
				'options' => array( 'Sales', 'Support' ),
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_select', $result->get_error_code() );
	}

	public function test_validate_field_type_rejects_required_checkbox_when_unchecked(): void {
		$result = $this->submissions->validate_field_type(
			'checkbox',
			'',
			array(
				'required' => true,
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'required_checkbox', $result->get_error_code() );
	}

	public function test_create_submission_post_creates_submission_type(): void {
		$post_id = $this->submissions->create_submission_post( array( 'form_id' => 10 ) );

		$this->assertIsInt( $post_id );
		$this->assertSame( SwiftForms_CPTs::SUBMISSION_POST_TYPE, get_post_type( $post_id ) );
		$this->assertSame( 'Submission #' . $post_id, get_post( $post_id )->post_title );
	}

	public function test_save_field_meta_saves_meta_by_slug(): void {
		$post_id = self::factory()->post->create( array( 'post_type' => SwiftForms_CPTs::SUBMISSION_POST_TYPE ) );

		$this->submissions->save_field_meta(
			$post_id,
			array(
				array(
					'slug'  => 'user_email',
					'value' => 'test@example.com',
				),
			)
		);

		$this->assertSame( 'test@example.com', get_post_meta( $post_id, '_sf_field_user_email', true ) );
	}

	public function test_handle_submission_rejects_invalid_nonce(): void {
		$response = $this->submissions->handle_submission(
			array(
				'nonce' => 'invalid',
			)
		);

		$this->assertFalse( $response['success'] );
		$this->assertSame( 'invalid_nonce', $response['code'] );
	}

	public function test_handle_submission_silently_ignores_honeypot_bots(): void {
		$response = $this->submissions->handle_submission(
			array(
				'nonce'    => wp_create_nonce( 'swiftforms_ajax' ),
				'honeypot' => 'bot-data',
			)
		);

		$this->assertTrue( $response['success'] );
		$this->assertSame( 'spam_blocked', $response['code'] );
	}

	public function test_handle_submission_creates_submission_for_valid_payload(): void {
		$response = $this->submissions->handle_submission(
			array(
				'nonce'    => wp_create_nonce( 'swiftforms_ajax' ),
				'honeypot' => '',
				'fields'   => array(
					array(
						'slug'  => 'email',
						'type'  => 'email',
						'value' => 'person@example.com',
					),
				),
				'form_id'  => 22,
			)
		);

		$this->assertTrue( $response['success'] );
		$this->assertIsInt( $response['submission_id'] );
		$this->assertSame( 'person@example.com', get_post_meta( $response['submission_id'], '_sf_field_email', true ) );
	}

	public function test_handle_submission_fires_pre_and_post_submission_hooks(): void {
		$request = array(
			'nonce'    => wp_create_nonce( 'swiftforms_ajax' ),
			'honeypot' => '',
			'fields'   => array(
				array(
					'slug'  => 'email',
					'type'  => 'email',
					'value' => 'person@example.com',
				),
			),
			'form_id'  => 33,
		);

		$response = $this->submissions->handle_submission( $request );

		$this->assertTrue( $response['success'] );
		$this->assertCount( 1, $this->pre_submission_calls );
		$this->assertCount( 1, $this->post_submission_calls );
		$this->assertSame( $request['form_id'], $this->pre_submission_calls[0]['request']['form_id'] );
		$this->assertSame( $response['submission_id'], $this->post_submission_calls[0]['submission_id'] );
	}

	public function test_handle_submission_persists_uploaded_file_meta(): void {
		$tmp_file = wp_tempnam( 'swiftforms-upload.txt' );
		file_put_contents( $tmp_file, 'swiftforms test payload' );

		$response = $this->submissions->handle_submission(
			array(
				'nonce'    => wp_create_nonce( 'swiftforms_ajax' ),
				'honeypot' => '',
				'fields'   => array(
					array(
						'slug'  => 'attachment',
						'type'  => 'file',
						'value' => array(
							'name'     => 'notes.txt',
							'size'     => filesize( $tmp_file ),
							'tmp_name' => $tmp_file,
						),
					),
				),
				'form_id'  => 44,
			)
		);

		$this->assertTrue( $response['success'] );

		$saved_value = get_post_meta( $response['submission_id'], '_sf_field_attachment', true );

		$this->assertIsString( $saved_value );
		$this->assertStringContainsString( '/swiftforms/', $saved_value );
		$this->assertFileExists( $saved_value );
	}

	public function test_handle_submission_normalizes_new_field_values_before_persisting(): void {
		$response = $this->submissions->handle_submission(
			array(
				'nonce'    => wp_create_nonce( 'swiftforms_ajax' ),
				'honeypot' => '',
				'fields'   => array(
					array(
						'slug'  => 'guests',
						'type'  => 'number',
						'value' => ' 4 ',
						'min'   => '1',
						'max'   => '10',
						'step'  => '1',
					),
					array(
						'slug'  => 'phone',
						'type'  => 'tel',
						'value' => ' +1 555 0100 ',
					),
					array(
						'slug'    => 'department',
						'type'    => 'select',
						'value'   => ' Sales ',
						'options' => array( 'Sales', 'Support' ),
					),
					array(
						'slug'     => 'consent',
						'type'     => 'checkbox',
						'value'    => ' yes ',
						'required' => true,
					),
				),
				'form_id'  => 88,
			)
		);

		$this->assertTrue( $response['success'] );
		$this->assertSame( '4', get_post_meta( $response['submission_id'], '_sf_field_guests', true ) );
		$this->assertSame( '+1 555 0100', get_post_meta( $response['submission_id'], '_sf_field_phone', true ) );
		$this->assertSame( 'Sales', get_post_meta( $response['submission_id'], '_sf_field_department', true ) );
		$this->assertSame( 'yes', get_post_meta( $response['submission_id'], '_sf_field_consent', true ) );
	}

	public function test_handle_submission_rejects_invalid_select_value(): void {
		$response = $this->submissions->handle_submission(
			array(
				'nonce'    => wp_create_nonce( 'swiftforms_ajax' ),
				'honeypot' => '',
				'fields'   => array(
					array(
						'slug'    => 'department',
						'type'    => 'select',
						'value'   => 'Billing',
						'options' => array( 'Sales', 'Support' ),
					),
				),
				'form_id'  => 89,
			)
		);

		$this->assertFalse( $response['success'] );
		$this->assertSame( 'validation_failed', $response['code'] );
		$this->assertSame( 'Please select a valid option.', $response['errors']['department'] );
	}

	public function test_handle_submission_rejects_empty_required_text(): void {
		$response = $this->submissions->handle_submission(
			array(
				'nonce'    => wp_create_nonce( 'swiftforms_ajax' ),
				'honeypot' => '',
				'fields'   => array(
					array(
						'slug'     => 'full_name',
						'type'     => 'text',
						'value'    => '   ',
						'required' => '1',
					),
				),
				'form_id'  => 90,
			)
		);

		$this->assertFalse( $response['success'] );
		$this->assertSame( 'validation_failed', $response['code'] );
		$this->assertSame( 'This field is required.', $response['errors']['full_name'] );
	}

	public function test_handle_submission_merges_live_uploaded_files_from_superglobal_request(): void {
		$tmp_file = wp_tempnam( 'swiftforms-live-upload.txt' );
		file_put_contents( $tmp_file, 'swiftforms live upload payload' );

		// Live submissions are validated against the stored form's field
		// schema, so the form post must exist and declare the field.
		$form_id = self::factory()->post->create(
			array(
				'post_content' => '<!-- wp:swiftforms/file-field {"label":"Attachment","slug":"attachment"} /-->',
				'post_status'  => 'publish',
				'post_type'    => SwiftForms_CPTs::FORM_POST_TYPE,
			)
		);

		$_POST = array(
			'nonce'    => wp_create_nonce( 'swiftforms_ajax' ),
			'honeypot' => '',
			'fields'   => array(
				array(
					'slug'  => 'attachment',
					'type'  => 'file',
					'value' => array(),
				),
			),
			'form_id'  => $form_id,
		);

		$_FILES = array(
			'swiftforms_files' => array(
				'name'     => array(
					0 => 'notes.txt',
				),
				'type'     => array(
					0 => 'text/plain',
				),
				'tmp_name' => array(
					0 => $tmp_file,
				),
				'error'    => array(
					0 => 0,
				),
				'size'     => array(
					0 => filesize( $tmp_file ),
				),
			),
		);

		$response = $this->submissions->handle_submission();

		$this->assertTrue( $response['success'] );

		$saved_value = get_post_meta( $response['submission_id'], '_sf_field_attachment', true );

		$this->assertIsString( $saved_value );
		$this->assertStringContainsString( '/swiftforms/', $saved_value );
		$this->assertFileExists( $saved_value );
	}

	public function test_handle_file_upload_rejects_disallowed_type(): void {
		$tmp_file = wp_tempnam( 'swiftforms-upload.exe' );
		file_put_contents( $tmp_file, 'binary-ish' );

		$result = $this->submissions->handle_file_upload(
			array(
				'name'     => 'payload.exe',
				'size'     => filesize( $tmp_file ),
				'tmp_name' => $tmp_file,
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_file_type', $result->get_error_code() );
	}

	public function test_handle_submission_sends_admin_and_autoresponder_notifications(): void {
		update_option( 'admin_email', 'admin@example.org' );

		$response = $this->submissions->handle_submission(
			array(
				'nonce'    => wp_create_nonce( 'swiftforms_ajax' ),
				'honeypot' => '',
				'fields'   => array(
					array(
						'slug'  => 'name',
						'type'  => 'text',
						'value' => 'Taylor',
					),
					array(
						'slug'  => 'email',
						'type'  => 'email',
						'value' => 'person@example.com',
					),
				),
				'form_id'  => 55,
			)
		);

		$this->assertTrue( $response['success'] );
		$this->assertCount( 2, $this->mail_calls );
		$this->assertSame( array( 'admin@example.org' ), (array) $this->mail_calls[0]['to'] );
		$this->assertSame( array( 'person@example.com' ), (array) $this->mail_calls[1]['to'] );
	}

	public function test_handle_submission_applies_email_content_filter(): void {
		add_filter(
			'swiftforms_email_content',
			static function ( string $message, string $context ): string {
				return $message . "\nfiltered-for:" . $context;
			},
			10,
			2
		);

		$response = $this->submissions->handle_submission(
			array(
				'nonce'    => wp_create_nonce( 'swiftforms_ajax' ),
				'honeypot' => '',
				'fields'   => array(
					array(
						'slug'  => 'email',
						'type'  => 'email',
						'value' => 'person@example.com',
					),
				),
				'form_id'  => 66,
			)
		);

		$this->assertTrue( $response['success'] );
		$this->assertNotEmpty( $this->mail_calls );
		$this->assertStringContainsString( 'filtered-for:admin', $this->mail_calls[0]['message'] );
	}

	public function test_handle_submission_uses_configurable_notification_settings(): void {
		$response = $this->submissions->handle_submission(
			array(
				'nonce'         => wp_create_nonce( 'swiftforms_ajax' ),
				'honeypot'      => '',
				'fields'        => array(
					array(
						'slug'  => 'name',
						'type'  => 'text',
						'value' => 'Taylor',
					),
					array(
						'slug'  => 'email',
						'type'  => 'email',
						'value' => 'person@example.com',
					),
				),
				'form_id'       => 77,
				'notifications' => array(
					'adminRecipients'       => "ops@example.org\nowner@example.org",
					'adminSubject'          => 'New lead {submission_id}',
					'adminTemplate'         => 'Admin {field:name} {field:email}',
					'autoresponderSubject'  => 'Thanks {field:name}',
					'autoresponderTemplate' => 'Received {submission_id}',
				),
			)
		);

		$this->assertTrue( $response['success'] );
		$this->assertCount( 2, $this->mail_calls );
		$this->assertSame( array( 'ops@example.org', 'owner@example.org' ), (array) $this->mail_calls[0]['to'] );
		$this->assertSame( 'New lead ' . $response['submission_id'], $this->mail_calls[0]['subject'] );
		$this->assertSame( 'Admin Taylor person@example.com', $this->mail_calls[0]['message'] );
		$this->assertSame( 'Thanks Taylor', $this->mail_calls[1]['subject'] );
		$this->assertSame( 'Received ' . $response['submission_id'], $this->mail_calls[1]['message'] );
	}

	public function test_handle_submission_uses_form_level_notification_settings_when_request_overrides_are_missing(): void {
		$form_id = self::factory()->post->create( array( 'post_type' => SwiftForms_CPTs::FORM_POST_TYPE ) );

		update_post_meta(
			$form_id,
			SwiftForms_CPTs::FORM_SETTINGS_META_KEY,
			SwiftForms_CPTs::sanitize_form_settings(
				array(
					'adminRecipients'       => "ops@example.org\nowner@example.org",
					'adminSubject'          => 'Stored lead {submission_id}',
					'adminTemplate'         => 'Stored admin {field:name}',
					'autoresponderSubject'  => 'Stored thanks {field:name}',
					'autoresponderTemplate' => 'Stored autoresponder {submission_id}',
				)
			)
		);

		$response = $this->submissions->handle_submission(
			array(
				'nonce'    => wp_create_nonce( 'swiftforms_ajax' ),
				'honeypot' => '',
				'fields'   => array(
					array(
						'slug'  => 'name',
						'type'  => 'text',
						'value' => 'Taylor',
					),
					array(
						'slug'  => 'email',
						'type'  => 'email',
						'value' => 'person@example.com',
					),
				),
				'form_id'  => $form_id,
			)
		);

		$this->assertTrue( $response['success'] );
		$this->assertCount( 2, $this->mail_calls );
		$this->assertSame( array( 'ops@example.org', 'owner@example.org' ), (array) $this->mail_calls[0]['to'] );
		$this->assertSame( 'Stored lead ' . $response['submission_id'], $this->mail_calls[0]['subject'] );
		$this->assertSame( 'Stored admin Taylor', $this->mail_calls[0]['message'] );
		$this->assertSame( 'Stored thanks Taylor', $this->mail_calls[1]['subject'] );
		$this->assertSame( 'Stored autoresponder ' . $response['submission_id'], $this->mail_calls[1]['message'] );
	}

	public function test_handle_submission_ignores_notifications_override_from_live_request(): void {
		// Live (AJAX-shaped) submissions come from a $_POST/$_FILES-derived
		// request, not an explicit array — this is what distinguishes a real
		// visitor submission from the direct/programmatic calls used
		// elsewhere in this suite. A visitor should never be able to redirect
		// admin notifications by POSTing a 'notifications' payload.
		update_option( 'admin_email', 'admin@example.org' );

		$form_id = self::factory()->post->create(
			array(
				'post_content' => '<!-- wp:swiftforms/email-field {"label":"Email","slug":"email"} /-->',
				'post_status'  => 'publish',
				'post_type'    => SwiftForms_CPTs::FORM_POST_TYPE,
			)
		);

		$_POST  = array(
			'nonce'         => wp_create_nonce( 'swiftforms_ajax' ),
			'honeypot'      => '',
			'fields'        => array(
				array(
					'slug'  => 'email',
					'type'  => 'email',
					'value' => 'person@example.com',
				),
			),
			'form_id'       => $form_id,
			'notifications' => array(
				'adminRecipients' => 'attacker@evil.example',
			),
		);
		$_FILES = array();

		$response = $this->submissions->handle_submission();

		$this->assertTrue( $response['success'] );
		$this->assertNotEmpty( $this->mail_calls );
		$this->assertSame( array( 'admin@example.org' ), (array) $this->mail_calls[0]['to'] );
	}

	public function test_handle_submission_rate_limits_live_requests(): void {
		add_filter( 'swiftforms_rate_limit_max_requests', static fn (): int => 2 );

		$_POST  = array();
		$_FILES = array();

		$this->submissions->handle_submission();
		$this->submissions->handle_submission();
		$response = $this->submissions->handle_submission();

		remove_all_filters( 'swiftforms_rate_limit_max_requests' );

		$this->assertFalse( $response['success'] );
		$this->assertSame( 'rate_limited', $response['code'] );
	}

	public function test_handle_submission_returns_fresh_nonce_when_expired(): void {
		$response = $this->submissions->handle_submission(
			array(
				'nonce' => 'invalid',
			)
		);

		$this->assertFalse( $response['success'] );
		$this->assertSame( 'invalid_nonce', $response['code'] );
		$this->assertNotEmpty( $response['nonce'] );
		$this->assertTrue( $this->submissions->verify_nonce( $response['nonce'] ) );
	}

	public function test_handle_submission_rejects_live_request_for_unknown_form(): void {
		$_POST  = array(
			'nonce'    => wp_create_nonce( 'swiftforms_ajax' ),
			'honeypot' => '',
			'fields'   => array(),
			'form_id'  => 987654,
		);
		$_FILES = array();

		$response = $this->submissions->handle_submission();

		$this->assertFalse( $response['success'] );
		$this->assertSame( 'invalid_form', $response['code'] );
	}

	public function test_handle_submission_enforces_stored_required_flag_on_live_requests(): void {
		// The client claims the field is optional; the stored form says
		// required. The stored form must win.
		$form_id = self::factory()->post->create(
			array(
				'post_content' => '<!-- wp:swiftforms/text-field {"label":"Name","slug":"full_name","required":true} /-->',
				'post_status'  => 'publish',
				'post_type'    => SwiftForms_CPTs::FORM_POST_TYPE,
			)
		);

		$_POST  = array(
			'nonce'    => wp_create_nonce( 'swiftforms_ajax' ),
			'honeypot' => '',
			'fields'   => array(
				array(
					'slug'     => 'full_name',
					'type'     => 'text',
					'required' => '0',
					'value'    => '',
				),
			),
			'form_id'  => $form_id,
		);
		$_FILES = array();

		$response = $this->submissions->handle_submission();

		$this->assertFalse( $response['success'] );
		$this->assertSame( 'validation_failed', $response['code'] );
		$this->assertSame( 'This field is required.', $response['errors']['full_name'] );
	}

	public function test_handle_submission_flags_required_fields_omitted_from_live_requests(): void {
		// A bot that drops the required row entirely (instead of sending it
		// empty) must still be caught.
		$form_id = self::factory()->post->create(
			array(
				'post_content' => '<!-- wp:swiftforms/email-field {"label":"Email","slug":"email","required":true} /-->',
				'post_status'  => 'publish',
				'post_type'    => SwiftForms_CPTs::FORM_POST_TYPE,
			)
		);

		$_POST  = array(
			'nonce'    => wp_create_nonce( 'swiftforms_ajax' ),
			'honeypot' => '',
			'fields'   => array(),
			'form_id'  => $form_id,
		);
		$_FILES = array();

		$response = $this->submissions->handle_submission();

		$this->assertFalse( $response['success'] );
		$this->assertSame( 'validation_failed', $response['code'] );
		$this->assertArrayHasKey( 'email', $response['errors'] );
	}

	public function test_handle_submission_drops_unknown_field_slugs_from_live_requests(): void {
		$form_id = self::factory()->post->create(
			array(
				'post_content' => '<!-- wp:swiftforms/text-field {"label":"Name","slug":"full_name"} /-->',
				'post_status'  => 'publish',
				'post_type'    => SwiftForms_CPTs::FORM_POST_TYPE,
			)
		);

		$_POST  = array(
			'nonce'    => wp_create_nonce( 'swiftforms_ajax' ),
			'honeypot' => '',
			'fields'   => array(
				array(
					'slug'  => 'full_name',
					'type'  => 'text',
					'value' => 'Taylor',
				),
				array(
					'slug'  => 'injected_meta_key',
					'type'  => 'text',
					'value' => 'malicious',
				),
			),
			'form_id'  => $form_id,
		);
		$_FILES = array();

		$response = $this->submissions->handle_submission();

		$this->assertTrue( $response['success'] );
		$this->assertSame( 'Taylor', get_post_meta( $response['submission_id'], '_sf_field_full_name', true ) );
		$this->assertSame( '', get_post_meta( $response['submission_id'], '_sf_field_injected_meta_key', true ) );
	}

	public function test_handle_submission_requires_captcha_token_when_form_enables_captcha(): void {
		$form_id = self::factory()->post->create(
			array(
				'post_content' => '<!-- wp:swiftforms/text-field {"label":"Name","slug":"full_name"} /-->',
				'post_status'  => 'publish',
				'post_type'    => SwiftForms_CPTs::FORM_POST_TYPE,
			)
		);

		update_post_meta(
			$form_id,
			SwiftForms_CPTs::FORM_SETTINGS_META_KEY,
			SwiftForms_CPTs::sanitize_form_settings( array( 'enableCaptcha' => true ) )
		);

		// No captcha_token at all — a bot skipping the challenge.
		$_POST  = array(
			'nonce'    => wp_create_nonce( 'swiftforms_ajax' ),
			'honeypot' => '',
			'fields'   => array(
				array(
					'slug'  => 'full_name',
					'type'  => 'text',
					'value' => 'Taylor',
				),
			),
			'form_id'  => $form_id,
		);
		$_FILES = array();

		$response = $this->submissions->handle_submission();

		$this->assertFalse( $response['success'] );
		$this->assertSame( 'invalid_captcha', $response['code'] );
	}

	public function test_handle_submission_marks_new_submissions_unread(): void {
		$response = $this->submissions->handle_submission(
			array(
				'nonce'    => wp_create_nonce( 'swiftforms_ajax' ),
				'honeypot' => '',
				'fields'   => array(
					array(
						'slug'  => 'email',
						'type'  => 'email',
						'value' => 'person@example.com',
					),
				),
				'form_id'  => 12,
			)
		);

		$this->assertTrue( $response['success'] );
		$this->assertSame( '1', get_post_meta( $response['submission_id'], '_sf_unread', true ) );
	}

	public function test_handle_submission_posts_webhook_for_configured_form(): void {
		$form_id = self::factory()->post->create( array( 'post_type' => SwiftForms_CPTs::FORM_POST_TYPE ) );

		update_post_meta(
			$form_id,
			SwiftForms_CPTs::FORM_SETTINGS_META_KEY,
			SwiftForms_CPTs::sanitize_form_settings( array( 'webhookUrl' => 'https://example.org/webhooks/swiftforms' ) )
		);

		$captured_requests = array();
		$capture           = static function ( $preempt, array $args, string $url ) use ( &$captured_requests ) {
			$captured_requests[] = array(
				'args' => $args,
				'url'  => $url,
			);

			return array(
				'body'     => '',
				'headers'  => array(),
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
			);
		};
		add_filter( 'pre_http_request', $capture, 10, 3 );

		$response = $this->submissions->handle_submission(
			array(
				'nonce'    => wp_create_nonce( 'swiftforms_ajax' ),
				'honeypot' => '',
				'fields'   => array(
					array(
						'slug'  => 'email',
						'type'  => 'email',
						'value' => 'person@example.com',
					),
				),
				'form_id'  => $form_id,
			)
		);

		remove_filter( 'pre_http_request', $capture, 10 );

		$this->assertTrue( $response['success'] );
		$this->assertCount( 1, $captured_requests );
		$this->assertSame( 'https://example.org/webhooks/swiftforms', $captured_requests[0]['url'] );

		$payload = json_decode( (string) $captured_requests[0]['args']['body'], true );
		$this->assertSame( $response['submission_id'], $payload['submission_id'] );
		$this->assertSame( 'person@example.com', $payload['fields']['email'] );
	}

	public function test_autoresponder_uses_configured_field_over_first_email_field(): void {
		$form_id = self::factory()->post->create( array( 'post_type' => SwiftForms_CPTs::FORM_POST_TYPE ) );

		update_post_meta(
			$form_id,
			SwiftForms_CPTs::FORM_SETTINGS_META_KEY,
			SwiftForms_CPTs::sanitize_form_settings( array( 'autoresponderField' => 'work_email' ) )
		);

		$response = $this->submissions->handle_submission(
			array(
				'nonce'    => wp_create_nonce( 'swiftforms_ajax' ),
				'honeypot' => '',
				'fields'   => array(
					array(
						'slug'  => 'personal_email',
						'type'  => 'email',
						'value' => 'personal@example.com',
					),
					array(
						'slug'  => 'work_email',
						'type'  => 'text',
						'value' => 'work@example.com',
					),
				),
				'form_id'  => $form_id,
			)
		);

		$this->assertTrue( $response['success'] );
		$this->assertCount( 2, $this->mail_calls );
		$this->assertSame( array( 'work@example.com' ), (array) $this->mail_calls[1]['to'] );
	}

	public function test_handle_submission_skips_entry_when_form_disables_saving(): void {
		update_option( 'admin_email', 'admin@example.org' );

		$form_id = self::factory()->post->create( array( 'post_type' => SwiftForms_CPTs::FORM_POST_TYPE ) );
		update_post_meta(
			$form_id,
			SwiftForms_CPTs::FORM_SETTINGS_META_KEY,
			SwiftForms_CPTs::sanitize_form_settings( array( 'saveEntries' => 'disabled' ) )
		);

		$entries_before = count(
			get_posts(
				array(
					'fields'         => 'ids',
					'post_status'    => 'any',
					'post_type'      => SwiftForms_CPTs::SUBMISSION_POST_TYPE,
					'posts_per_page' => -1,
				)
			)
		);

		$response = $this->submissions->handle_submission(
			array(
				'nonce'    => wp_create_nonce( 'swiftforms_ajax' ),
				'honeypot' => '',
				'fields'   => array(
					array(
						'slug'  => 'email',
						'type'  => 'email',
						'value' => 'person@example.com',
					),
				),
				'form_id'  => $form_id,
			)
		);

		$entries_after = count(
			get_posts(
				array(
					'fields'         => 'ids',
					'post_status'    => 'any',
					'post_type'      => SwiftForms_CPTs::SUBMISSION_POST_TYPE,
					'posts_per_page' => -1,
				)
			)
		);

		$this->assertTrue( $response['success'] );
		$this->assertSame( 0, $response['submission_id'] );
		$this->assertSame( $entries_before, $entries_after );
		// Notifications still go out for notify-only forms.
		$this->assertCount( 2, $this->mail_calls );
	}

	public function test_handle_submission_follows_global_save_entries_default(): void {
		$form_id = self::factory()->post->create( array( 'post_type' => SwiftForms_CPTs::FORM_POST_TYPE ) );

		// Global default off + form on 'default' → not saved.
		update_option(
			SwiftForms_Settings::OPTION_KEY,
			SwiftForms_Settings::sanitize_settings( array( 'saveEntriesDefault' => '' ) )
		);

		$request = array(
			'nonce'    => wp_create_nonce( 'swiftforms_ajax' ),
			'honeypot' => '',
			'fields'   => array(
				array(
					'slug'  => 'email',
					'type'  => 'email',
					'value' => 'person@example.com',
				),
			),
			'form_id'  => $form_id,
		);

		$response = $this->submissions->handle_submission( $request );
		$this->assertTrue( $response['success'] );
		$this->assertSame( 0, $response['submission_id'] );

		// A form explicitly set to 'enabled' overrides the global default.
		update_post_meta(
			$form_id,
			SwiftForms_CPTs::FORM_SETTINGS_META_KEY,
			SwiftForms_CPTs::sanitize_form_settings( array( 'saveEntries' => 'enabled' ) )
		);

		$request['nonce'] = wp_create_nonce( 'swiftforms_ajax' );
		$response         = $this->submissions->handle_submission( $request );

		$this->assertTrue( $response['success'] );
		$this->assertGreaterThan( 0, $response['submission_id'] );
		$this->assertSame( 'person@example.com', get_post_meta( $response['submission_id'], '_sf_field_email', true ) );

		delete_option( SwiftForms_Settings::OPTION_KEY );
	}

	public function test_notifications_fall_back_to_global_default_recipients(): void {
		update_option( 'admin_email', 'admin@example.org' );
		update_option(
			SwiftForms_Settings::OPTION_KEY,
			SwiftForms_Settings::sanitize_settings( array( 'defaultAdminRecipients' => "global-one@example.org\nglobal-two@example.org" ) )
		);

		// Form without its own recipients uses the global default, not admin_email.
		$response = $this->submissions->handle_submission(
			array(
				'nonce'    => wp_create_nonce( 'swiftforms_ajax' ),
				'honeypot' => '',
				'fields'   => array(
					array(
						'slug'  => 'name',
						'type'  => 'text',
						'value' => 'Taylor',
					),
				),
				'form_id'  => 0,
			)
		);

		delete_option( SwiftForms_Settings::OPTION_KEY );

		$this->assertTrue( $response['success'] );
		$this->assertNotEmpty( $this->mail_calls );
		$this->assertSame( array( 'global-one@example.org', 'global-two@example.org' ), (array) $this->mail_calls[0]['to'] );
	}

	public function capture_pre_submission( array $request, SwiftForms_Submissions $submissions ): void {
		$this->pre_submission_calls[] = array(
			'request'     => $request,
			'submissions' => $submissions,
		);
	}

	public function capture_post_submission( int $submission_id, array $request, SwiftForms_Submissions $submissions ): void {
		$this->post_submission_calls[] = array(
			'submission_id' => $submission_id,
			'request'       => $request,
			'submissions'   => $submissions,
		);
	}

	/**
	 * Short-circuits wp_mail while capturing its payload for assertions.
	 *
	 * @param array<string, mixed> $atts Mail arguments as passed to wp_mail.
	 */
	public function capture_mail_call( $short_circuit, array $atts ): bool {
		$this->mail_calls[] = $atts;

		return true;
	}

	private function get_pre_submission_hook_name(): string {
		return 'swiftforms_pre_submission';
	}

	private function get_post_submission_hook_name(): string {
		return 'swiftforms_post_submission';
	}

	/**
	 * Builds a live-request form with a conditionally required Details field.
	 *
	 * Details is required but only shown when Topic equals 'support'.
	 */
	private function create_conditional_form(): int {
		$content = '<!-- wp:swiftforms/select-field {"label":"Topic","slug":"topic","options":"support\\nsales"} /-->'
			. "\\n" . '<!-- wp:swiftforms/text-field {"label":"Details","slug":"details","required":true,"conditions":{"enabled":true,"action":"show","groups":[[{"field":"topic","operator":"equals","value":"support"}]]}} /-->';

		return self::factory()->post->create(
			array(
				'post_content' => wp_slash( $content ),
				'post_status'  => 'publish',
				'post_type'    => SwiftForms_CPTs::FORM_POST_TYPE,
			)
		);
	}

	public function test_handle_submission_skips_required_for_condition_hidden_field(): void {
		$form_id = $this->create_conditional_form();

		$_POST  = array(
			'nonce'    => wp_create_nonce( 'swiftforms_ajax' ),
			'honeypot' => '',
			'fields'   => array(
				array(
					'slug'  => 'topic',
					'type'  => 'select',
					'value' => 'sales',
				),
			),
			'form_id'  => $form_id,
		);
		$_FILES = array();

		$response = $this->submissions->handle_submission();

		$this->assertTrue( $response['success'], 'A required field hidden by its own conditions must not block the submission.' );
	}

	public function test_handle_submission_drops_values_submitted_for_condition_hidden_fields(): void {
		$form_id = $this->create_conditional_form();

		$_POST  = array(
			'nonce'    => wp_create_nonce( 'swiftforms_ajax' ),
			'honeypot' => '',
			'fields'   => array(
				array(
					'slug'  => 'topic',
					'type'  => 'select',
					'value' => 'sales',
				),
				array(
					'slug'  => 'details',
					'type'  => 'text',
					'value' => 'smuggled value for a hidden field',
				),
			),
			'form_id'  => $form_id,
		);
		$_FILES = array();

		$response = $this->submissions->handle_submission();

		$this->assertTrue( $response['success'] );
		$this->assertGreaterThan( 0, $response['submission_id'] );
		$this->assertSame( '', (string) get_post_meta( $response['submission_id'], '_sf_field_details', true ), 'Values for condition-hidden fields must never persist.' );
		$this->assertSame( 'sales', (string) get_post_meta( $response['submission_id'], '_sf_field_topic', true ) );
	}

	public function test_handle_submission_requires_condition_visible_field(): void {
		$form_id = $this->create_conditional_form();

		$_POST  = array(
			'nonce'    => wp_create_nonce( 'swiftforms_ajax' ),
			'honeypot' => '',
			'fields'   => array(
				array(
					'slug'  => 'topic',
					'type'  => 'select',
					'value' => 'support',
				),
			),
			'form_id'  => $form_id,
		);
		$_FILES = array();

		$response = $this->submissions->handle_submission();

		$this->assertFalse( $response['success'], 'A required field made visible by the submitted values must still be required.' );
		$this->assertSame( 'validation_failed', $response['code'] );
		$this->assertArrayHasKey( 'details', $response['errors'] );
	}

	public function test_parse_option_pairs_splits_on_first_pipe_only(): void {
		$pairs = SwiftForms_Submissions::parse_option_pairs( "Veggie meal|veggie\nPipe|in|value" );

		$this->assertSame(
			array(
				array(
					'label' => 'Veggie meal',
					'value' => 'veggie',
				),
				array(
					'label' => 'Pipe',
					'value' => 'in|value',
				),
			),
			$pairs
		);
	}

	public function test_parse_option_pairs_falls_back_to_label_only_lines(): void {
		$pairs = SwiftForms_Submissions::parse_option_pairs( "Sales\nSupport|\n|orphan\n   " );

		$this->assertSame(
			array(
				array(
					'label' => 'Sales',
					'value' => 'Sales',
				),
				array(
					'label' => 'Support',
					'value' => 'Support',
				),
			),
			$pairs
		);
	}

	public function test_validate_field_type_select_accepts_pair_values_not_labels(): void {
		$field = array( 'options' => "Veggie meal|veggie\nMeat meal|meat" );

		$this->assertTrue( $this->submissions->validate_field_type( 'select', 'veggie', $field ) );
		$this->assertWPError( $this->submissions->validate_field_type( 'select', 'Veggie meal', $field ) );
	}

	public function test_validate_field_type_radio_validates_like_select(): void {
		$field = array(
			'options'  => "Yes|y\nNo|n",
			'required' => true,
		);

		$this->assertTrue( $this->submissions->validate_field_type( 'radio', 'y', $field ) );

		$invalid = $this->submissions->validate_field_type( 'radio', 'maybe', $field );
		$this->assertWPError( $invalid );
		$this->assertSame( 'invalid_select', $invalid->get_error_code() );

		$missing = $this->submissions->validate_field_type( 'radio', '', $field );
		$this->assertWPError( $missing );
		$this->assertSame( 'required_select', $missing->get_error_code() );
	}

	public function test_validate_field_type_accepts_valid_date(): void {
		$this->assertTrue( $this->submissions->validate_field_type( 'date', '2026-07-10' ) );
	}

	public function test_validate_field_type_rejects_malformed_dates(): void {
		foreach ( array( '10/07/2026', '2026-13-01', '2026-02-30', 'not-a-date', 20260710 ) as $bad_date ) {
			$result = $this->submissions->validate_field_type( 'date', $bad_date );
			$this->assertWPError( $result );
			$this->assertSame( 'invalid_date', $result->get_error_code() );
		}
	}

	public function test_validate_field_type_enforces_date_min_max(): void {
		$field = array(
			'min' => '2026-01-01',
			'max' => '2026-12-31',
		);

		$this->assertTrue( $this->submissions->validate_field_type( 'date', '2026-06-15', $field ) );

		$too_early = $this->submissions->validate_field_type( 'date', '2025-12-31', $field );
		$this->assertWPError( $too_early );
		$this->assertSame( 'invalid_date_min', $too_early->get_error_code() );

		$too_late = $this->submissions->validate_field_type( 'date', '2027-01-01', $field );
		$this->assertWPError( $too_late );
		$this->assertSame( 'invalid_date_max', $too_late->get_error_code() );
	}

	public function test_handle_submission_round_trips_step_wrapped_radio_and_hidden_fields(): void {
		$content = '<!-- wp:swiftforms/step {"title":"One"} --><div class="wp-block-swiftforms-step swiftforms-step" data-swiftforms-step="true" data-step-title="One">'
			. '<!-- wp:swiftforms/radio-field {"label":"Meal","slug":"meal","options":"Veggie|v\u005cnMeat|m","required":true} /-->'
			. '</div><!-- /wp:swiftforms/step -->'
			. '<!-- wp:swiftforms/step {"title":"Two"} --><div class="wp-block-swiftforms-step swiftforms-step" data-swiftforms-step="true" data-step-title="Two">'
			. '<!-- wp:swiftforms/hidden-field {"slug":"campaign","value":"spring"} /-->'
			. '</div><!-- /wp:swiftforms/step -->';

		$form_id = self::factory()->post->create(
			array(
				'post_content' => wp_slash( str_replace( '\u005cn', '\\n', $content ) ),
				'post_status'  => 'publish',
				'post_type'    => SwiftForms_CPTs::FORM_POST_TYPE,
			)
		);

		$_POST  = array(
			'nonce'    => wp_create_nonce( 'swiftforms_ajax' ),
			'honeypot' => '',
			'fields'   => array(
				array(
					'slug'  => 'meal',
					'type'  => 'radio',
					'value' => 'v',
				),
				array(
					'slug'  => 'campaign',
					'type'  => 'hidden',
					'value' => 'spring',
				),
			),
			'form_id'  => $form_id,
		);
		$_FILES = array();

		$response = $this->submissions->handle_submission();

		$this->assertTrue( $response['success'] );
		$this->assertSame( 'v', (string) get_post_meta( $response['submission_id'], '_sf_field_meal', true ) );
		$this->assertSame( 'spring', (string) get_post_meta( $response['submission_id'], '_sf_field_campaign', true ) );
	}

	public function test_validate_time_trap_passes_without_token(): void {
		$this->assertTrue( $this->submissions->validate_time_trap( array() ) );
		$this->assertTrue( $this->submissions->validate_time_trap( array( 'render_ts' => '' ) ) );
	}

	public function test_validate_time_trap_rejects_fast_submission(): void {
		$issued_at = time();
		$token     = $issued_at . '.' . SwiftForms_Submissions::hash_render_timestamp( $issued_at );

		$this->assertFalse( $this->submissions->validate_time_trap( array( 'render_ts' => $token ) ) );
	}

	public function test_validate_time_trap_rejects_forged_hmac(): void {
		$issued_at = time() - 3600;

		$this->assertFalse( $this->submissions->validate_time_trap( array( 'render_ts' => $issued_at . '.forged' ) ) );
		$this->assertFalse( $this->submissions->validate_time_trap( array( 'render_ts' => 'garbage' ) ) );
	}

	public function test_validate_time_trap_accepts_aged_token(): void {
		$issued_at = time() - 3600;
		$token     = $issued_at . '.' . SwiftForms_Submissions::hash_render_timestamp( $issued_at );

		$this->assertTrue( $this->submissions->validate_time_trap( array( 'render_ts' => $token ) ), 'Old tokens must pass — page caches serve old timestamps.' );
	}

	public function test_validate_time_trap_disabled_when_filtered_to_zero(): void {
		add_filter( 'swiftforms_min_submit_seconds', '__return_zero' );

		$issued_at = time();
		$token     = $issued_at . '.' . SwiftForms_Submissions::hash_render_timestamp( $issued_at );

		$this->assertTrue( $this->submissions->validate_time_trap( array( 'render_ts' => $token ) ) );

		remove_all_filters( 'swiftforms_min_submit_seconds' );
	}

	public function test_validate_turnstile_passes_when_not_enabled_or_unconfigured(): void {
		$form_id = self::factory()->post->create( array( 'post_type' => SwiftForms_CPTs::FORM_POST_TYPE ) );

		// Not enabled on the form.
		$this->assertTrue( $this->submissions->validate_turnstile( array( 'form_id' => $form_id ) ) );

		// Enabled but no secret configured: graceful degrade.
		update_post_meta( $form_id, SwiftForms_CPTs::FORM_SETTINGS_META_KEY, SwiftForms_CPTs::sanitize_form_settings( array( 'enableTurnstile' => true ) ) );
		$this->assertTrue( $this->submissions->validate_turnstile( array( 'form_id' => $form_id ) ) );
	}

	public function test_validate_turnstile_uses_filtered_response(): void {
		$form_id = self::factory()->post->create( array( 'post_type' => SwiftForms_CPTs::FORM_POST_TYPE ) );
		update_post_meta( $form_id, SwiftForms_CPTs::FORM_SETTINGS_META_KEY, SwiftForms_CPTs::sanitize_form_settings( array( 'enableTurnstile' => true ) ) );
		update_option( SwiftForms_Settings::OPTION_KEY, array( 'turnstileSecretKey' => 'secret-key' ) );

		// Short-circuit the outbound HTTP call entirely.
		add_filter( 'pre_http_request', static fn () => new WP_Error( 'blocked', 'no network in tests' ) );

		add_filter( 'swiftforms_turnstile_verify_response', static fn (): array => array( 'success' => true ) );
		$this->assertTrue( $this->submissions->validate_turnstile( array( 'form_id' => $form_id ) ) );

		remove_all_filters( 'swiftforms_turnstile_verify_response' );
		add_filter( 'swiftforms_turnstile_verify_response', static fn (): array => array( 'success' => false ) );
		$this->assertFalse( $this->submissions->validate_turnstile( array( 'form_id' => $form_id ) ) );

		remove_all_filters( 'swiftforms_turnstile_verify_response' );
		remove_all_filters( 'pre_http_request' );
		delete_option( SwiftForms_Settings::OPTION_KEY );
	}

	public function test_handle_submission_stores_spam_without_notifications(): void {
		update_option( SwiftForms_Settings::OPTION_KEY, array( 'akismetEnabled' => true ) );
		add_filter( 'swiftforms_akismet_result', '__return_true' );

		$form_id = self::factory()->post->create(
			array(
				'post_content' => '<!-- wp:swiftforms/text-field {"label":"Name","slug":"name"} /-->',
				'post_status'  => 'publish',
				'post_type'    => SwiftForms_CPTs::FORM_POST_TYPE,
			)
		);

		$_POST  = array(
			'nonce'    => wp_create_nonce( 'swiftforms_ajax' ),
			'honeypot' => '',
			'fields'   => array(
				array(
					'slug'  => 'name',
					'type'  => 'text',
					'value' => 'Cheap widgets here',
				),
			),
			'form_id'  => $form_id,
		);
		$_FILES = array();

		$response = $this->submissions->handle_submission();

		remove_all_filters( 'swiftforms_akismet_result' );
		delete_option( SwiftForms_Settings::OPTION_KEY );

		$this->assertTrue( $response['success'], 'Spam is absorbed as a normal success so bots learn nothing.' );
		$this->assertGreaterThan( 0, $response['submission_id'] );
		$this->assertSame( '1', (string) get_post_meta( $response['submission_id'], '_sf_spam', true ) );
		$this->assertSame( '', (string) get_post_meta( $response['submission_id'], '_sf_unread', true ), 'Spam must not count as unread.' );
		$this->assertEmpty( $this->mail_calls, 'Spam must not trigger notifications.' );
	}
}
