<?php
/**
 * Submission pipeline orchestrator.
 *
 * @package SwiftForms
 */

declare(strict_types=1);

namespace SwiftForms\Submissions;

use SwiftForms\Entries\EntryRepository;
use SwiftForms\Notifications\Notifier;
use SwiftForms\Notifications\Webhooks;
use SwiftForms\Settings\FormSettings;
use WP_Error;

/**
 * Runs every submission through, in order: rate limit → nonce → schema
 * enforcement (the form's stored blocks become the authoritative field
 * list) → spam checks → file uploads → validation → storage →
 * notifications/webhooks. Framework-agnostic — SubmitController adapts it
 * to REST; tests call it directly.
 */
final class Pipeline {

	private const DEFAULT_MAX_FIELDS            = 100;
	private const DEFAULT_MAX_FIELD_VALUE_BYTES = 10000;

	public function __construct(
		private RateLimiter $rate_limiter,
		private NonceGuard $nonce_guard,
		private SpamGuard $spam_guard,
		private SchemaEnforcer $schema_enforcer,
		private Validator $validator,
		private UploadHandler $upload_handler,
		private EntryRepository $entry_repository,
		private Notifier $notifier,
		private Webhooks $webhooks
	) {
	}

	/**
	 * @param array<string, mixed> $request Raw POST body (already `wp_unslash()`-ed).
	 * @param array<string, mixed> $files   `$_FILES`-shaped entries keyed by field slug.
	 * @return array{status_code: int, body: array<string, mixed>}
	 */
	public function handle( array $request, array $files = array() ): array {
		$limits_error = $this->validate_request_limits( $request );

		if ( null !== $limits_error ) {
			return $limits_error;
		}

		if ( ! $this->nonce_guard->verify( (string) ( $request['nonce'] ?? '' ) ) ) {
			return $this->error(
				403,
				'smartlogix_swiftforms_invalid_nonce',
				__( 'Your session has expired. Please try again.', 'swiftforms' ),
				array( 'nonce' => $this->nonce_guard->create() )
			);
		}

		$normalized = $this->normalize_request( $request );

		$enforced = $this->schema_enforcer->enforce( $normalized );

		if ( $enforced instanceof WP_Error ) {
			$data = $enforced->get_error_data();

			return $this->error( (int) ( $data['status'] ?? 400 ), $enforced->get_error_code(), $enforced->get_error_message() );
		}

		$form_id       = $enforced['form_id'];
		$fields        = $enforced['fields'];
		$form_settings = FormSettings::get( $form_id );

		if ( $this->rate_limiter->is_limited( $form_id ) ) {
			return $this->error( 429, 'rate_limited', __( 'Too many submissions. Please wait a moment and try again.', 'swiftforms' ) );
		}

		// Akismet must see only the schema-enforced, visible fields (including
		// their authoritative types), never arbitrary client rows.
		$spam_verdict = $this->spam_guard->evaluate( array_merge( $normalized, array( 'fields' => $fields ) ), $form_settings );

		if ( 'silent_reject' === $spam_verdict['status'] ) {
			return $this->success( 0, $form_settings );
		}

		if ( 'hard_reject' === $spam_verdict['status'] ) {
			return $this->error( 400, $spam_verdict['code'], $spam_verdict['message'] );
		}

		$is_spam = 'soft_flag' === $spam_verdict['status'];

		foreach ( $fields as $index => $field ) {
			if ( 'file' !== $field['type'] ) {
				continue;
			}

			// FileField validates the original upload shape (`tmp_name`, `size`,
			// etc.); moving it first would replace that with stored-file metadata.
			$fields[ $index ]['value'] = $files[ $field['slug'] ] ?? array();
		}

		$errors = $this->validator->validate( $fields );

		if ( $errors ) {
			return $this->error( 400, 'validation_failed', __( 'Please fix the errors below and try again.', 'swiftforms' ), array( 'errors' => $errors ) );
		}

		$save_entries   = FormSettings::should_save_entries( $form_id );
		$uploaded_files = array();

		foreach ( $fields as $index => $field ) {
			if ( 'file' !== $field['type'] || empty( $field['value']['tmp_name'] ) ) {
				continue;
			}

			if ( ! $save_entries ) {
				return $this->error( 400, 'file_upload_requires_entry_storage', __( 'This form must save entries to accept file uploads.', 'swiftforms' ), array( 'errors' => array( $field['slug'] => __( 'File uploads are unavailable for this form.', 'swiftforms' ) ) ) );
			}

			$uploaded = $this->upload_handler->handle( $field['value'] );

			if ( $uploaded instanceof WP_Error ) {
				$this->delete_uploaded_files( $uploaded_files );

				return $this->error( 400, $uploaded->get_error_code(), $uploaded->get_error_message(), array( 'errors' => array( $field['slug'] => $uploaded->get_error_message() ) ) );
			}

			if ( is_array( $uploaded ) ) {
				$uploaded_files[] = $uploaded;
			}

			$fields[ $index ]['value'] = $uploaded;
		}

		/**
		 * Fires right before a valid submission is saved.
		 *
		 * @param array<int, array<string, mixed>> $fields  Validated fields.
		 * @param int                               $form_id Source form post id.
		 */
		do_action( 'smartlogix_swiftforms_pre_submission', $fields, $form_id );

		$entry_id = $save_entries ? $this->entry_repository->create( $form_id, $fields, $is_spam ) : 0;

		if ( $save_entries && 0 === $entry_id ) {
			$this->delete_uploaded_files( $uploaded_files );

			return $this->error( 500, 'entry_not_saved', __( 'Your submission could not be saved. Please try again.', 'swiftforms' ) );
		}

		if ( ! $is_spam ) {
			$delivery = $this->notifier->dispatch( $entry_id, $form_id, $fields, $form_settings );
			if ( $entry_id > 0 ) {
				update_post_meta( $entry_id, '_smartlogix_swiftforms_delivery_email', in_array( false, $delivery, true ) ? 'failed' : 'sent' );
				update_post_meta( $entry_id, '_smartlogix_swiftforms_delivery_webhook', ! empty( $form_settings['webhookUrl'] ) ? 'queued' : 'not_configured' );
			}
			$this->webhooks->send( $entry_id, $form_id, $fields, $form_settings );
		}

		/**
		 * Fires after a submission has been fully processed.
		 *
		 * @param int                               $entry_id Entry post id (0 if not saved).
		 * @param int                               $form_id  Source form post id.
		 * @param array<int, array<string, mixed>>  $fields   Validated fields.
		 */
		do_action( 'smartlogix_swiftforms_post_submission', $entry_id, $form_id, $fields );

		return $this->success( $entry_id, $form_settings );
	}

	/**
	 * @param array<string, mixed> $request Raw POST body.
	 * @return array<string, mixed>
	 */
	private function normalize_request( array $request ): array {
		$fields = array();

		foreach ( (array) ( $request['fields'] ?? array() ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$slug = sanitize_key( (string) ( $row['slug'] ?? '' ) );

			if ( '' === $slug ) {
				continue;
			}

			$value = $row['value'] ?? '';

			$fields[] = array(
				'slug'  => $slug,
				'value' => is_array( $value ) ? $value : sanitize_textarea_field( (string) $value ),
			);
		}

		return array(
			'form_id'               => (int) ( $request['form_id'] ?? 0 ),
			'honeypot'              => (string) ( $request['honeypot'] ?? '' ),
			'render_ts'             => (string) ( $request['render_ts'] ?? '' ),
			'nonce'                 => (string) ( $request['nonce'] ?? '' ),
			'captcha_token'         => (string) ( $request['captcha_token'] ?? '' ),
			'captcha_answer'        => $request['captcha_answer'] ?? null,
			'cf_turnstile_response' => (string) ( $request['cf_turnstile_response'] ?? '' ),
			'fields'                => $fields,
		);
	}

	/**
	 * Rejects oversized field payloads before sanitization, schema parsing, or spam checks.
	 *
	 * Limits apply only to scalar field values. Array values are used by choice fields and
	 * are subsequently constrained by the stored form schema.
	 *
	 * @param array<string, mixed> $request Raw POST body.
	 * @return array{status_code: int, body: array<string, mixed>}|null
	 */
	private function validate_request_limits( array $request ): ?array {
		$max_fields      = max( 1, (int) apply_filters( 'smartlogix_swiftforms_submission_max_fields', self::DEFAULT_MAX_FIELDS ) );
		$max_value_bytes = max( 1, (int) apply_filters( 'smartlogix_swiftforms_submission_max_field_value_bytes', self::DEFAULT_MAX_FIELD_VALUE_BYTES ) );
		$raw_fields      = $request['fields'] ?? array();

		if ( ! is_array( $raw_fields ) || count( $raw_fields ) > $max_fields ) {
			return $this->error( 413, 'payload_too_large', __( 'This submission is too large. Please shorten your response and try again.', 'swiftforms' ) );
		}

		foreach ( $raw_fields as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['value'] ) || ! is_scalar( $row['value'] ) ) {
				continue;
			}

			if ( strlen( (string) $row['value'] ) > $max_value_bytes ) {
				return $this->error( 413, 'payload_too_large', __( 'This submission is too large. Please shorten your response and try again.', 'swiftforms' ) );
			}
		}

		return null;
	}

	/**
	 * Removes files moved during a submission that cannot be completed.
	 *
	 * @param array<int, array{name: string, path: string, size: int}> $uploaded_files Moved upload metadata.
	 */
	private function delete_uploaded_files( array $uploaded_files ): void {
		foreach ( $uploaded_files as $uploaded_file ) {
			if ( ! empty( $uploaded_file['path'] ) && file_exists( $uploaded_file['path'] ) ) {
				wp_delete_file( $uploaded_file['path'] );
			}
		}
	}

	/**
	 * @param array<string, mixed> $form_settings Resolved `_smartlogix_swiftforms_settings`.
	 * @return array{status_code: int, body: array<string, mixed>}
	 */
	private function success( int $entry_id, array $form_settings ): array {
		$body = array(
			'success' => true,
			'message' => $form_settings['successMessage'] ?? '',
		);

		if ( $entry_id > 0 ) {
			$body['entry_id'] = $entry_id;
		}

		return array(
			'status_code' => 200,
			'body'        => $body,
		);
	}

	/**
	 * @param array<string, mixed> $extra Extra response body keys (e.g. `errors`, `nonce`).
	 * @return array{status_code: int, body: array<string, mixed>}
	 */
	private function error( int $status, string $code, string $message, array $extra = array() ): array {
		return array(
			'status_code' => $status,
			'body'        => array_merge(
				array(
					'success' => false,
					'code'    => $code,
					'message' => $message,
				),
				$extra
			),
		);
	}
}
