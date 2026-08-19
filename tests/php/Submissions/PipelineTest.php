<?php
/**
 * End-to-end tests for the submission pipeline.
 *
 * @package SwiftForms
 */

declare(strict_types=1);

namespace SwiftForms\Tests\Submissions;

use SwiftForms\Entries\EntryRepository;
use SwiftForms\Fields\FieldRegistry;
use SwiftForms\Notifications\Mailer;
use SwiftForms\Notifications\Notifier;
use SwiftForms\Notifications\TemplateRenderer;
use SwiftForms\Notifications\Webhooks;
use SwiftForms\Submissions\NonceGuard;
use SwiftForms\Submissions\Pipeline;
use SwiftForms\Submissions\RateLimiter;
use SwiftForms\Submissions\SchemaEnforcer;
use SwiftForms\Submissions\SpamGuard;
use SwiftForms\Submissions\TimeTrap;
use SwiftForms\Submissions\UploadHandler;
use SwiftForms\Submissions\Validator;
use SwiftForms\Tests\TestCase;

final class PipelineTest extends TestCase {

	private Pipeline $pipeline;
	private NonceGuard $nonce_guard;

	public function set_up(): void {
		parent::set_up();

		$registry = new FieldRegistry();
		$registry->load_types();

		$this->nonce_guard = new NonceGuard();

		$this->pipeline = new Pipeline(
			new RateLimiter(),
			$this->nonce_guard,
			new SpamGuard(),
			new SchemaEnforcer( $registry ),
			new Validator( $registry ),
			new UploadHandler(),
			new EntryRepository(),
			new Notifier( new TemplateRenderer(), new Mailer() ),
			new Webhooks()
		);

		add_filter( 'smartlogix_swiftforms_rate_limit_max_requests', fn() => 1000 );
		add_filter( 'smartlogix_swiftforms_min_submit_seconds', fn() => 0 );
	}

	private function valid_render_ts(): string {
		// Simulates a render that happened long enough ago to pass the time trap.
		$issued_at = time() - 5;
		$signature = hash_hmac( 'sha256', "rendered_at|{$issued_at}", wp_salt( 'auth' ) );

		return "{$issued_at}.{$signature}";
	}

	private function base_request( int $form_id, array $fields ): array {
		return array(
			'form_id'   => $form_id,
			'nonce'     => $this->nonce_guard->create(),
			'render_ts' => $this->valid_render_ts(),
			'honeypot'  => '',
			'fields'    => $fields,
		);
	}

	public function test_invalid_nonce_is_rejected_with_a_fresh_one_returned(): void {
		$form_id = $this->create_form( '<!-- wp:smartlogix-swiftforms/field-text {"slug":"name","label":"Name"} /-->' );

		$result = $this->pipeline->handle(
			array(
				'form_id' => $form_id,
				'nonce'   => 'not-a-real-nonce',
				'fields'  => array(),
			)
		);

		$this->assertSame( 403, $result['status_code'] );
		$this->assertSame( 'smartlogix_swiftforms_invalid_nonce', $result['body']['code'] );
		$this->assertNotEmpty( $result['body']['nonce'] );
	}

	public function test_honeypot_triggers_a_silent_success(): void {
		$form_id = $this->create_form( '<!-- wp:smartlogix-swiftforms/field-text {"slug":"name","label":"Name"} /-->' );

		$request             = $this->base_request(
			$form_id,
			array(
				array(
					'slug'  => 'name',
					'value' => 'Bot',
				),
			)
		);
		$request['honeypot'] = 'i-am-a-bot';

		$result = $this->pipeline->handle( $request );

		$this->assertSame( 200, $result['status_code'] );
		$this->assertTrue( $result['body']['success'] );
		$this->assertArrayNotHasKey( 'entry_id', $result['body'] );
	}

	public function test_time_trap_triggers_a_silent_success(): void {
		remove_all_filters( 'smartlogix_swiftforms_min_submit_seconds' );

		$form_id = $this->create_form( '<!-- wp:smartlogix-swiftforms/field-text {"slug":"name","label":"Name"} /-->' );

		$request              = $this->base_request(
			$form_id,
			array(
				array(
					'slug'  => 'name',
					'value' => 'Fast Bot',
				),
			)
		);
		$request['render_ts'] = TimeTrap::build(); // Submitted "now" -> fails the default 3s minimum.

		$result = $this->pipeline->handle( $request );

		$this->assertSame( 200, $result['status_code'] );
		$this->assertTrue( $result['body']['success'] );
		$this->assertArrayNotHasKey( 'entry_id', $result['body'] );
	}

	public function test_missing_required_field_fails_validation(): void {
		$form_id = $this->create_form( '<!-- wp:smartlogix-swiftforms/field-email {"slug":"email","label":"Email","required":true} /-->' );

		$result = $this->pipeline->handle(
			$this->base_request(
				$form_id,
				array(
					array(
						'slug'  => 'email',
						'value' => '',
					),
				)
			)
		);

		$this->assertSame( 400, $result['status_code'] );
		$this->assertSame( 'validation_failed', $result['body']['code'] );
		$this->assertArrayHasKey( 'email', $result['body']['errors'] );
	}

	public function test_rejects_too_many_submitted_fields(): void {
		add_filter( 'smartlogix_swiftforms_submission_max_fields', static fn() => 1 );

		$form_id = $this->create_form( '<!-- wp:smartlogix-swiftforms/field-text {"slug":"name","label":"Name"} /-->' );
		$result  = $this->pipeline->handle(
			$this->base_request(
				$form_id,
				array(
					array(
						'slug'  => 'name',
						'value' => 'Alice',
					),
					array(
						'slug'  => 'extra',
						'value' => 'Ignored',
					),
				)
			)
		);

		$this->assertSame( 413, $result['status_code'] );
		$this->assertSame( 'payload_too_large', $result['body']['code'] );
	}

	public function test_rejects_an_overlong_scalar_field_value(): void {
		add_filter( 'smartlogix_swiftforms_submission_max_field_value_bytes', static fn() => 4 );

		$form_id = $this->create_form( '<!-- wp:smartlogix-swiftforms/field-text {"slug":"name","label":"Name"} /-->' );
		$result  = $this->pipeline->handle(
			$this->base_request(
				$form_id,
				array(
					array(
						'slug'  => 'name',
						'value' => 'Alice',
					),
				)
			)
		);

		$this->assertSame( 413, $result['status_code'] );
		$this->assertSame( 'payload_too_large', $result['body']['code'] );
	}

	public function test_invalid_email_fails_validation(): void {
		$form_id = $this->create_form( '<!-- wp:smartlogix-swiftforms/field-email {"slug":"email","label":"Email"} /-->' );

		$result = $this->pipeline->handle(
			$this->base_request(
				$form_id,
				array(
					array(
						'slug'  => 'email',
						'value' => 'not-an-email',
					),
				)
			)
		);

		$this->assertSame( 400, $result['status_code'] );
		$this->assertArrayHasKey( 'email', $result['body']['errors'] );
	}

	public function test_valid_submission_succeeds_and_creates_an_entry(): void {
		$form_id = $this->create_form( '<!-- wp:smartlogix-swiftforms/field-text {"slug":"name","label":"Name","required":true} /-->' );

		$result = $this->pipeline->handle(
			$this->base_request(
				$form_id,
				array(
					array(
						'slug'  => 'name',
						'value' => 'Alice',
					),
				)
			)
		);

		$this->assertSame( 200, $result['status_code'] );
		$this->assertTrue( $result['body']['success'] );
		$this->assertArrayHasKey( 'entry_id', $result['body'] );

		$entry_id = $result['body']['entry_id'];
		$this->assertSame( 'smartlogix_swf_entry', get_post_type( $entry_id ) );
		$this->assertSame( 'Alice', get_post_meta( $entry_id, 'smartlogix_swiftforms_field_name', true ) );

		$terms = wp_get_object_terms( $entry_id, \SwiftForms\PostTypes::ENTRY_FORM_TAXONOMY, array( 'fields' => 'slugs' ) );
		$this->assertSame( array( \SwiftForms\PostTypes::entry_term_slug( $form_id ) ), $terms );
	}

	public function test_disabled_entries_still_returns_success_but_saves_nothing(): void {
		$form_id = $this->create_form(
			'<!-- wp:smartlogix-swiftforms/field-text {"slug":"name","label":"Name"} /-->',
			array( 'saveEntries' => 'disabled' )
		);

		$result = $this->pipeline->handle(
			$this->base_request(
				$form_id,
				array(
					array(
						'slug'  => 'name',
						'value' => 'Alice',
					),
				)
			)
		);

		$this->assertSame( 200, $result['status_code'] );
		$this->assertTrue( $result['body']['success'] );
		$this->assertArrayNotHasKey( 'entry_id', $result['body'] );
	}

	public function test_required_file_upload_is_validated_then_saved(): void {
		$form_id = $this->create_form( '<!-- wp:smartlogix-swiftforms/field-file {"slug":"attachment","label":"Attachment","required":true} /-->' );
		$path    = wp_tempnam( 'swf-pipeline-upload-test' );

		file_put_contents( $path, "Hello, this is a plain text attachment.\n" );

		$result = $this->pipeline->handle(
			$this->base_request(
				$form_id,
				array(
					array(
						'slug'  => 'attachment',
						'value' => '',
					),
				),
			),
			array(
				'attachment' => array(
					'name'     => 'notes.txt',
					'tmp_name' => $path,
					'error'    => UPLOAD_ERR_OK,
					'size'     => filesize( $path ),
				),
			)
		);

		$this->assertSame( 200, $result['status_code'] );
		$this->assertTrue( $result['body']['success'] );

		$upload = get_post_meta( $result['body']['entry_id'], 'smartlogix_swiftforms_field_attachment', true );
		$this->assertIsArray( $upload );
		$this->assertFileExists( $upload['path'] );

		wp_delete_file( $upload['path'] );
	}

	public function test_missing_captcha_token_when_required_is_rejected(): void {
		$form_id = $this->create_form(
			'<!-- wp:smartlogix-swiftforms/field-text {"slug":"name","label":"Name"} /-->',
			array( 'enableCaptcha' => true )
		);

		$result = $this->pipeline->handle(
			$this->base_request(
				$form_id,
				array(
					array(
						'slug'  => 'name',
						'value' => 'Alice',
					),
				)
			)
		);

		$this->assertSame( 400, $result['status_code'] );
		$this->assertSame( 'invalid_captcha', $result['body']['code'] );
	}

	public function test_admin_notification_email_is_sent_on_valid_submission(): void {
		$captured = array();
		add_filter(
			'pre_wp_mail',
			static function ( $short_circuit, array $atts ) use ( &$captured ) {
				$captured[] = $atts;
				return true;
			},
			10,
			2
		);

		$form_id = $this->create_form(
			'<!-- wp:smartlogix-swiftforms/field-email {"slug":"email","label":"Email"} /-->',
			array( 'adminRecipients' => 'owner@example.com' )
		);

		$this->pipeline->handle(
			$this->base_request(
				$form_id,
				array(
					array(
						'slug'  => 'email',
						'value' => 'visitor@example.com',
					),
				)
			)
		);

		$this->assertNotEmpty( $captured );
		$this->assertSame( 'owner@example.com', $captured[0]['to'] );
	}

	public function test_rejects_unknown_form(): void {
		$result = $this->pipeline->handle(
			array(
				'form_id' => 999999,
				'nonce'   => $this->nonce_guard->create(),
				'fields'  => array(),
			)
		);

		$this->assertSame( 400, $result['status_code'] );
		$this->assertSame( 'invalid_form', $result['body']['code'] );
	}
}
