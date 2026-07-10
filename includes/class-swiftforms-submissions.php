<?php
/**
 * Submission processing.
 *
 * @package SwiftForms
 */

declare(strict_types=1);

/**
 * Validates, stores, and relays frontend form submissions.
 */
class SwiftForms_Submissions {
	/**
	 * How long a signed captcha token stays valid, in seconds.
	 */
	private const CAPTCHA_TTL_SECONDS = 1800;

	/**
	 * Maximum live submissions accepted per IP within the rate limit window.
	 */
	private const RATE_LIMIT_MAX_REQUESTS = 5;

	/**
	 * Rate limit window size, in seconds.
	 */
	private const RATE_LIMIT_WINDOW_SECONDS = 60;

	/**
	 * Handles a frontend submission request.
	 *
	 * @param array<string, mixed>|null $request Optional request data for direct testing.
	 *
	 * @return array<string, mixed>
	 */
	public function handle_submission( ?array $request = null ): array {
		$should_send_json = null === $request;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The payload carries its own nonce, verified via verify_nonce() below.
		$request = null === $request ? wp_unslash( $_POST ) : $request;

		if ( $should_send_json ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The payload carries its own nonce, verified via verify_nonce() below.
			$request = $this->merge_uploaded_files( $request, $_FILES['swiftforms_files'] ?? array() );
		}

		$request = $this->normalize_request( $request );

		// Notification recipients/subjects/templates must only ever come from
		// the stored form settings, never from the live request: anyone who
		// can reach admin-ajax.php with a valid nonce could otherwise turn
		// this endpoint into an arbitrary-recipient email relay. Direct/
		// programmatic callers (tests, internal integrations) are still
		// trusted to pass an explicit override.
		if ( $should_send_json ) {
			unset( $request['notifications'] );

			if ( $this->is_rate_limited() ) {
				$response = array(
					'success' => false,
					'code'    => 'rate_limited',
					'message' => __( 'Too many submissions. Please try again in a minute.', 'swiftforms' ),
				);

				return $this->maybe_send_json( $response, $should_send_json, 429 );
			}
		}

		$nonce = isset( $request['nonce'] ) ? (string) $request['nonce'] : '';
		if ( ! $this->verify_nonce( $nonce ) ) {
			$response = array(
				'success' => false,
				'code'    => 'invalid_nonce',
				'message' => __( 'The form session has expired.', 'swiftforms' ),
				// A page cache can serve the same logged-out nonce well past
				// its rotation; ship a fresh one so the frontend can retry
				// once instead of forcing the visitor to reload the page.
				'nonce'   => wp_create_nonce( 'swiftforms_ajax' ),
			);

			return $this->maybe_send_json( $response, $should_send_json, 400 );
		}

		$honeypot = isset( $request['honeypot'] ) ? (string) $request['honeypot'] : '';
		if ( ! $this->validate_honeypot( $honeypot ) ) {
			$response = array(
				'success' => true,
				'code'    => 'spam_blocked',
				'message' => __( 'Submission ignored.', 'swiftforms' ),
			);

			return $this->maybe_send_json( $response, $should_send_json, 200 );
		}

		// The time trap is absorbed exactly like the honeypot: a bot that
		// posts faster than a human can type learns nothing from the response.
		if ( $should_send_json && ! $this->validate_time_trap( $request ) ) {
			$response = array(
				'success' => true,
				'code'    => 'spam_blocked',
				'message' => __( 'Submission ignored.', 'swiftforms' ),
			);

			return $this->maybe_send_json( $response, $should_send_json, 200 );
		}

		// Live traffic never gets to define its own field rules: the stored
		// form post is the source of truth for types, required flags, number
		// constraints, and select options, and unknown slugs are dropped.
		// Direct/programmatic callers (tests, integrations) pass an explicit
		// request array and remain responsible for their own field config.
		if ( $should_send_json ) {
			$enforced_request = $this->enforce_form_schema( $request );

			if ( is_wp_error( $enforced_request ) ) {
				$response = array(
					'success' => false,
					'code'    => 'invalid_form',
					'message' => $enforced_request->get_error_message(),
				);

				return $this->maybe_send_json( $response, $should_send_json, 400 );
			}

			$request = $enforced_request;

			// A bot that simply omits the captcha inputs must not slip past a
			// form that has the captcha enabled.
			$form_settings = SwiftForms_CPTs::get_form_settings( (int) ( $request['form_id'] ?? 0 ) );
			if ( ! empty( $form_settings['enableCaptcha'] ) && '' === trim( (string) ( $request['captcha_token'] ?? '' ) ) ) {
				$response = array(
					'success' => false,
					'code'    => 'invalid_captcha',
					'message' => __( 'The captcha answer is incorrect.', 'swiftforms' ),
				);

				return $this->maybe_send_json( $response, $should_send_json, 400 );
			}

			if ( ! $this->validate_turnstile( $request ) ) {
				$response = array(
					'success' => false,
					'code'    => 'invalid_captcha',
					'message' => __( 'The anti-spam check failed. Please try again.', 'swiftforms' ),
				);

				return $this->maybe_send_json( $response, $should_send_json, 400 );
			}
		}

		if ( ! $this->validate_captcha( $request ) ) {
			$response = array(
				'success' => false,
				'code'    => 'invalid_captcha',
				'message' => __( 'The captcha answer is incorrect.', 'swiftforms' ),
			);

			return $this->maybe_send_json( $response, $should_send_json, 400 );
		}

		$field_errors = $this->validate_fields( $request );
		if ( ! empty( $field_errors ) ) {
			$response = array(
				'success' => false,
				'code'    => 'validation_failed',
				'errors'  => $field_errors,
			);

			return $this->maybe_send_json( $response, $should_send_json, 400 );
		}

		do_action( 'swiftforms_pre_submission', $request, $this );

		// Akismet (when enabled) files matches as reviewable spam entries
		// instead of rejecting them, and only ever runs for live traffic. The
		// response stays a normal success so spammers learn nothing.
		$is_spam = false;
		if ( $should_send_json ) {
			$settings = SwiftForms_Settings::get_settings();
			$is_spam  = ! empty( $settings['akismetEnabled'] ) && SwiftForms_Spam::check( $request );
		}

		// A form can opt out of storing entries (per-form 'saveEntries'
		// setting, falling back to the global default). Notifications and
		// webhooks still fire either way; only persistence is skipped, and
		// downstream consumers see submission_id 0.
		$submission_id = 0;
		if ( SwiftForms_Settings::should_save_entries( isset( $request['form_id'] ) ? (int) $request['form_id'] : 0 ) ) {
			$submission_id = $this->create_submission_post( $request, $is_spam );
			if ( is_wp_error( $submission_id ) ) {
				$response = array(
					'success' => false,
					'code'    => $submission_id->get_error_code(),
					'message' => $submission_id->get_error_message(),
				);

				return $this->maybe_send_json( $response, $should_send_json, 500 );
			}

			$this->save_field_meta( $submission_id, $request['fields'] ?? array() );
		}

		if ( ! $is_spam ) {
			$this->send_notifications( $submission_id, $request );
			$this->send_webhook( $submission_id, $request );
		}

		do_action( 'swiftforms_post_submission', $submission_id, $request, $this );

		$response = array(
			'success'       => true,
			'message'       => __( 'Form submitted successfully.', 'swiftforms' ),
			'submission_id' => $submission_id,
		);

		return $this->maybe_send_json( $response, $should_send_json, 200 );
	}

	/**
	 * Verifies the AJAX nonce.
	 *
	 * @param string $nonce Nonce value from the request.
	 */
	public function verify_nonce( string $nonce ): bool {
		$result = wp_verify_nonce( $nonce, 'swiftforms_ajax' );

		return 1 === $result || 2 === $result;
	}

	/**
	 * Rewrites the submitted field rows against the stored form's field schema.
	 *
	 * - Rejects the submission outright when the form ID doesn't resolve to a
	 *   real form post.
	 * - Drops rows whose slug isn't a field in the form (they'd otherwise
	 *   become arbitrary `_sf_field_*` meta writes).
	 * - Overrides type/required/min/max/step/options with the stored values,
	 *   discarding whatever the client claimed.
	 * - Injects an empty row for any required field the client omitted, so
	 *   validation reports it instead of silently accepting the gap.
	 *
	 * @param array<string, mixed> $request Submission payload.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	private function enforce_form_schema( array $request ) {
		$form_id = isset( $request['form_id'] ) ? (int) $request['form_id'] : 0;
		$form    = $form_id > 0 ? get_post( $form_id ) : null;

		if ( ! $form instanceof WP_Post || SwiftForms_CPTs::FORM_POST_TYPE !== $form->post_type ) {
			return new WP_Error( 'invalid_form', __( 'This form is no longer available.', 'swiftforms' ) );
		}

		$schema   = SwiftForms_CPTs::get_form_field_schema( $form_id );
		$fields   = is_array( $request['fields'] ?? null ) ? $request['fields'] : array();
		$enforced = array();
		$seen     = array();

		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}

			$slug = isset( $field['slug'] ) ? sanitize_key( (string) $field['slug'] ) : '';

			if ( '' === $slug || ! isset( $schema[ $slug ] ) || isset( $seen[ $slug ] ) ) {
				continue;
			}

			$enforced[]    = array_merge(
				$field,
				$schema[ $slug ],
				array( 'slug' => $slug )
			);
			$seen[ $slug ] = true;
		}

		// Conditional visibility is re-evaluated server-side from the stored
		// rules and the submitted values: a client must be able to neither
		// smuggle in values for hidden fields nor be blocked by a required
		// field its own answers hid.
		$visibility = SwiftForms_Conditions::compute_visibility( $schema, $this->get_condition_value_map( $enforced ) );

		$enforced = array_values(
			array_filter(
				$enforced,
				static fn ( array $row ): bool => false !== ( $visibility[ $row['slug'] ] ?? true )
			)
		);

		foreach ( $schema as $slug => $config ) {
			if ( isset( $seen[ $slug ] ) || empty( $config['required'] ) || false === ( $visibility[ $slug ] ?? true ) ) {
				continue;
			}

			$enforced[] = array_merge(
				$config,
				array(
					'slug'  => $slug,
					'value' => '',
				)
			);
		}

		$request['fields'] = $enforced;

		return $request;
	}

	/**
	 * Builds the slug => string value map conditions are evaluated against.
	 *
	 * Scalar values pass through as strings; file uploads count as '1' when a
	 * file is attached so rules like "not empty" work on file fields. The
	 * frontend engine mirrors these exact semantics.
	 *
	 * @param array<int, array<string, mixed>> $fields Enforced field rows.
	 *
	 * @return array<string, string>
	 */
	private function get_condition_value_map( array $fields ): array {
		$values = array();

		foreach ( $fields as $field ) {
			$slug = isset( $field['slug'] ) ? sanitize_key( (string) $field['slug'] ) : '';

			if ( '' === $slug ) {
				continue;
			}

			$value = $field['value'] ?? '';

			if ( is_array( $value ) ) {
				$values[ $slug ] = '' !== trim( (string) ( $value['name'] ?? '' ) ) ? '1' : '';
				continue;
			}

			$values[ $slug ] = is_scalar( $value ) ? (string) $value : '';
		}

		return $values;
	}

	/**
	 * Maps a submission response payload to its HTTP status code.
	 *
	 * Shared by the REST endpoint; the AJAX path passes explicit codes to
	 * wp_send_json at each return site.
	 *
	 * @param array<string, mixed> $response Response payload.
	 */
	public static function get_status_for_response( array $response ): int {
		if ( ! empty( $response['success'] ) ) {
			return 200;
		}

		$code = (string) ( $response['code'] ?? '' );

		if ( 'rate_limited' === $code ) {
			return 429;
		}

		$bad_request_codes = array( 'invalid_captcha', 'invalid_form', 'invalid_nonce', 'validation_failed' );

		return in_array( $code, $bad_request_codes, true ) ? 400 : 500;
	}

	/**
	 * Returns whether the current requester has exceeded the live submission rate limit.
	 *
	 * Only ever consulted for real, internet-facing submissions (never for
	 * direct/programmatic calls), so it can't throttle the PHPUnit suite,
	 * which always passes an explicit $request array.
	 */
	private function is_rate_limited(): bool {
		$key   = 'swiftforms_rl_' . md5( $this->get_client_ip() );
		$count = (int) get_transient( $key );

		$max_requests = (int) apply_filters( 'swiftforms_rate_limit_max_requests', self::RATE_LIMIT_MAX_REQUESTS );
		$window       = (int) apply_filters( 'swiftforms_rate_limit_window_seconds', self::RATE_LIMIT_WINDOW_SECONDS );

		if ( $count >= $max_requests ) {
			return true;
		}

		set_transient( $key, $count + 1, $window );

		return false;
	}

	/**
	 * Resolves the requester's IP address for rate limiting.
	 *
	 * Trusts REMOTE_ADDR by default; sites behind a proxy or CDN can filter
	 * in a trusted forwarded-for header instead.
	 */
	private function get_client_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';

		return (string) apply_filters( 'swiftforms_client_ip', $ip );
	}

	/**
	 * Validates the honeypot anti-spam field.
	 *
	 * @param string $value Honeypot input value.
	 */
	public function validate_honeypot( string $value ): bool {
		return '' === trim( $value );
	}

	/**
	 * Validates the optional math captcha answer against its signed, time-limited token.
	 *
	 * The expected answer never travels to the browser. Instead the form ships a
	 * token of the form `{issued_at}.{hmac}` (see SwiftForms_Blocks::build_captcha())
	 * which binds the HMAC to both the correct sum and the time it was issued. A
	 * submission passes only when the HMAC matches and the token hasn't expired, so
	 * a bot must solve the current challenge rather than replay a captured token
	 * indefinitely.
	 *
	 * @param array<string, mixed> $request Submission payload.
	 */
	public function validate_captcha( array $request ): bool {
		$token = isset( $request['captcha_token'] ) ? (string) $request['captcha_token'] : '';
		if ( '' === $token ) {
			return true;
		}

		$parts = explode( '.', $token, 2 );
		if ( 2 !== count( $parts ) || 1 !== preg_match( '/^\d+$/', $parts[0] ) ) {
			return false;
		}

		$issued_at = (int) $parts[0];
		if ( $issued_at > time() || ( time() - $issued_at ) > self::CAPTCHA_TTL_SECONDS ) {
			return false;
		}

		$answer = isset( $request['captcha_answer'] ) ? (int) $request['captcha_answer'] : PHP_INT_MIN;

		return hash_equals( $parts[1], self::hash_captcha_answer( $answer, $issued_at ) );
	}

	/**
	 * Computes the HMAC token for a captcha answer, bound to its issue time.
	 *
	 * Shared by the form renderer (to build the token) and the validator (to
	 * verify it) so the signing scheme stays in one place.
	 *
	 * @param int $answer    Expected or submitted answer.
	 * @param int $issued_at Unix timestamp the challenge was issued.
	 */
	public static function hash_captcha_answer( int $answer, int $issued_at ): string {
		return hash_hmac( 'sha256', $answer . '|' . $issued_at, wp_salt( 'auth' ) );
	}

	/**
	 * Computes the HMAC token for a form render timestamp.
	 *
	 * Shared by the form renderer and validate_time_trap() so the signing
	 * scheme stays in one place.
	 *
	 * @param int $issued_at Unix timestamp the form was rendered.
	 */
	public static function hash_render_timestamp( int $issued_at ): string {
		return hash_hmac( 'sha256', 'rendered_at|' . $issued_at, wp_salt( 'auth' ) );
	}

	/**
	 * Validates the minimum-age time trap on live submissions.
	 *
	 * A missing token passes — pages cached before this feature shipped must
	 * keep working — and only a minimum age is enforced, never a maximum,
	 * because page caches legitimately serve old render timestamps.
	 *
	 * @param array<string, mixed> $request Submission payload.
	 */
	public function validate_time_trap( array $request ): bool {
		$token = isset( $request['render_ts'] ) ? (string) $request['render_ts'] : '';
		if ( '' === $token ) {
			return true;
		}

		$minimum_seconds = (int) apply_filters( 'swiftforms_min_submit_seconds', 3 );
		if ( $minimum_seconds <= 0 ) {
			return true;
		}

		$parts = explode( '.', $token, 2 );
		if ( 2 !== count( $parts ) || 1 !== preg_match( '/^\d+$/', $parts[0] ) ) {
			return false;
		}

		$issued_at = (int) $parts[0];
		if ( ! hash_equals( self::hash_render_timestamp( $issued_at ), $parts[1] ) ) {
			return false;
		}

		return ( time() - $issued_at ) >= $minimum_seconds;
	}

	/**
	 * Verifies a Cloudflare Turnstile response for forms that enable it.
	 *
	 * Passes when the form doesn't use Turnstile or no secret is configured
	 * (graceful degrade). The decoded siteverify body runs through the
	 * `swiftforms_turnstile_verify_response` filter so tests can stub the
	 * HTTP round-trip.
	 *
	 * @param array<string, mixed> $request Submission payload.
	 */
	public function validate_turnstile( array $request ): bool {
		$form_settings = SwiftForms_CPTs::get_form_settings( (int) ( $request['form_id'] ?? 0 ) );

		if ( empty( $form_settings['enableTurnstile'] ) ) {
			return true;
		}

		$settings = SwiftForms_Settings::get_settings();
		$secret   = (string) $settings['turnstileSecretKey'];

		if ( '' === $secret ) {
			return true;
		}

		$response = wp_remote_post(
			'https://challenges.cloudflare.com/turnstile/v0/siteverify',
			array(
				'body'    => array(
					'secret'   => $secret,
					'response' => (string) ( $request['cf_turnstile_response'] ?? '' ),
					'remoteip' => $this->get_client_ip(),
				),
				'timeout' => 5,
			)
		);

		$decoded = is_wp_error( $response ) ? array() : json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$decoded = apply_filters( 'swiftforms_turnstile_verify_response', is_array( $decoded ) ? $decoded : array(), $request );

		return ! empty( $decoded['success'] );
	}

	/**
	 * Validates a value for a specific field type.
	 *
	 * @param string               $type  Field type.
	 * @param mixed                $value Submitted value.
	 * @param array<string, mixed> $field Submitted field configuration.
	 *
	 * @return true|WP_Error
	 */
	public function validate_field_type( string $type, mixed $value, array $field = array() ) {
		if ( 'email' === $type ) {
			if ( ! is_string( $value ) || ! is_email( $value ) ) {
				return new WP_Error( 'invalid_email', __( 'Please enter a valid email address.', 'swiftforms' ) );
			}

			return true;
		}

		if ( 'url' === $type ) {
			if ( ! is_string( $value ) || false === filter_var( $value, FILTER_VALIDATE_URL ) ) {
				return new WP_Error( 'invalid_url', __( 'Please enter a valid URL.', 'swiftforms' ) );
			}

			return true;
		}

		if ( 'number' === $type ) {
			if ( ! is_scalar( $value ) || '' === trim( (string) $value ) || ! is_numeric( (string) $value ) ) {
				return new WP_Error( 'invalid_number', __( 'Please enter a valid number.', 'swiftforms' ) );
			}

			$numeric_value = (float) $value;

			if ( isset( $field['min'] ) && '' !== (string) $field['min'] && $numeric_value < (float) $field['min'] ) {
				return new WP_Error( 'invalid_number_min', __( 'Please enter a number above the minimum value.', 'swiftforms' ) );
			}

			if ( isset( $field['max'] ) && '' !== (string) $field['max'] && $numeric_value > (float) $field['max'] ) {
				return new WP_Error( 'invalid_number_max', __( 'Please enter a number below the maximum value.', 'swiftforms' ) );
			}

			if ( isset( $field['step'] ) && '' !== (string) $field['step'] ) {
				$step = (float) $field['step'];
				if ( $step > 0 ) {
					$minimum = isset( $field['min'] ) && '' !== (string) $field['min'] ? (float) $field['min'] : 0.0;
					$offset  = ( $numeric_value - $minimum ) / $step;

					if ( abs( $offset - round( $offset ) ) > 0.00001 ) {
						return new WP_Error( 'invalid_number_step', __( 'Please enter a valid increment.', 'swiftforms' ) );
					}
				}
			}

			return true;
		}

		if ( 'tel' === $type ) {
			if ( ! is_string( $value ) || '' === trim( $value ) || 1 !== preg_match( '/^\+?[0-9\s().-]{6,20}$/', $value ) ) {
				return new WP_Error( 'invalid_tel', __( 'Please enter a valid phone number.', 'swiftforms' ) );
			}

			return true;
		}

		if ( 'date' === $type ) {
			if ( ! is_string( $value ) || 1 !== preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $date_parts ) || ! checkdate( (int) $date_parts[2], (int) $date_parts[3], (int) $date_parts[1] ) ) {
				return new WP_Error( 'invalid_date', __( 'Please enter a valid date.', 'swiftforms' ) );
			}

			// ISO dates compare correctly as plain strings.
			if ( isset( $field['min'] ) && '' !== (string) $field['min'] && $value < (string) $field['min'] ) {
				return new WP_Error( 'invalid_date_min', __( 'Please choose a later date.', 'swiftforms' ) );
			}

			if ( isset( $field['max'] ) && '' !== (string) $field['max'] && $value > (string) $field['max'] ) {
				return new WP_Error( 'invalid_date_max', __( 'Please choose an earlier date.', 'swiftforms' ) );
			}

			return true;
		}

		if ( 'select' === $type || 'radio' === $type ) {
			$string_value = is_scalar( $value ) ? trim( (string) $value ) : '';
			$options      = $this->normalize_select_options( $field['options'] ?? array() );

			if ( '' === $string_value ) {
				if ( $this->is_field_required( $field ) ) {
					return new WP_Error( 'required_select', __( 'Please select an option.', 'swiftforms' ) );
				}

				return true;
			}

			if ( ! empty( $options ) && ! in_array( $string_value, $options, true ) ) {
				return new WP_Error( 'invalid_select', __( 'Please select a valid option.', 'swiftforms' ) );
			}

			return true;
		}

		if ( 'checkbox' === $type ) {
			$string_value = is_scalar( $value ) ? trim( (string) $value ) : '';

			if ( $this->is_field_required( $field ) && '' === $string_value ) {
				return new WP_Error( 'required_checkbox', __( 'Please check this box to continue.', 'swiftforms' ) );
			}

			return true;
		}

		if ( 'file' === $type ) {
			if ( ! is_array( $value ) ) {
				return new WP_Error( 'invalid_file', __( 'The uploaded file is invalid.', 'swiftforms' ) );
			}

			$size = isset( $value['size'] ) ? (int) $value['size'] : 0;
			if ( $size > wp_max_upload_size() ) {
				return new WP_Error( 'file_too_large', __( 'File too large.', 'swiftforms' ) );
			}

			return true;
		}

		return true;
	}

	/**
	 * Creates the submission post wrapper.
	 *
	 * Spam entries carry `_sf_spam` and skip the unread flag so they never
	 * inflate the admin menu's unread bubble.
	 *
	 * @param array<string, mixed> $request Submission payload.
	 * @param bool                 $is_spam Whether the submission was classified as spam.
	 *
	 * @return int|WP_Error
	 */
	public function create_submission_post( array $request, bool $is_spam = false ) {
		$meta_input = array(
			'_sf_form_id' => isset( $request['form_id'] ) ? (int) $request['form_id'] : 0,
		);

		if ( $is_spam ) {
			$meta_input['_sf_spam'] = 1;
		} else {
			$meta_input['_sf_unread'] = 1;
			delete_transient( 'swiftforms_unread_count' );
		}

		$post_id = wp_insert_post(
			array(
				'post_type'   => SwiftForms_CPTs::SUBMISSION_POST_TYPE,
				'post_status' => 'private',
				'post_title'  => 'Submission',
				'meta_input'  => $meta_input,
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		wp_update_post(
			array(
				'ID'         => $post_id,
				'post_title' => sprintf( 'Submission #%d', $post_id ),
			)
		);

		return $post_id;
	}

	/**
	 * Saves each submitted field as post meta.
	 *
	 * @param int                              $post_id Submission post ID.
	 * @param array<int, array<string, mixed>> $fields  Submitted field rows.
	 */
	public function save_field_meta( int $post_id, array $fields ): void {
		foreach ( $fields as $field ) {
			if ( empty( $field['slug'] ) ) {
				continue;
			}

			$slug  = sanitize_key( (string) $field['slug'] );
			$type  = isset( $field['type'] ) ? (string) $field['type'] : 'text';
			$value = $field['value'] ?? '';

			if ( 'file' === $type && is_array( $value ) ) {
				$upload = $this->handle_file_upload( $value );

				if ( ! is_wp_error( $upload ) ) {
					update_post_meta( $post_id, '_sf_field_' . $slug, $upload['path'] );
				}

				continue;
			}

			if ( is_scalar( $value ) ) {
				update_post_meta( $post_id, '_sf_field_' . $slug, (string) $value );
			}
		}
	}

	/**
	 * Sends admin and auto-responder notifications for a stored submission.
	 *
	 * @param int                  $submission_id Stored submission post ID (0 when entries are not saved).
	 * @param array<string, mixed> $request       Submission payload.
	 */
	public function send_notifications( int $submission_id, array $request ): void {
		$notification_config = $this->resolve_notification_config( $submission_id, $request );

		$form_id         = isset( $request['form_id'] ) ? (int) $request['form_id'] : 0;
		$stored_settings = $form_id > 0 ? SwiftForms_CPTs::get_form_settings( $form_id ) : SwiftForms_CPTs::get_default_form_settings();
		$preferred_field = sanitize_key( (string) ( $stored_settings['autoresponderField'] ?? '' ) );

		$reply_to_email = $this->get_autoresponder_recipient( $request['fields'] ?? array(), $preferred_field );
		$admin_headers  = '' !== $reply_to_email ? array( 'Reply-To: ' . $reply_to_email ) : array();

		$admin_message = $this->build_email_content( 'admin', $submission_id, $request, $notification_config['adminTemplate'] );
		wp_mail( $notification_config['adminRecipients'], $notification_config['adminSubject'], $admin_message, $admin_headers );

		if ( '' !== $reply_to_email ) {
			$autoresponder_message = $this->build_email_content( 'autoresponder', $submission_id, $request, $notification_config['autoresponderTemplate'] );
			wp_mail( $reply_to_email, $notification_config['autoresponderSubject'], $autoresponder_message );
		}
	}

	/**
	 * Builds the text body for a notification email.
	 *
	 * @param string               $context       Email context: 'admin' or 'autoresponder'.
	 * @param int                  $submission_id Stored submission post ID.
	 * @param array<string, mixed> $request       Submission payload.
	 * @param string               $template      Optional template override.
	 */
	public function build_email_content( string $context, int $submission_id, array $request, string $template = '' ): string {
		$message = '' !== trim( $template )
			? $this->render_notification_template( $template, $submission_id, $request )
			: $this->get_default_email_content( $submission_id, $request );

		return (string) apply_filters( 'swiftforms_email_content', $message, $context, $submission_id, $request );
	}

	/**
	 * Resolves notification recipients, subjects, and templates for a submission.
	 *
	 * Sourced from the form's stored settings by default. A `notifications` override
	 * in $request is only honored for direct/programmatic callers: handle_submission()
	 * strips that key from the request before it ever reaches here whenever the
	 * request came from the live, visitor-facing AJAX endpoint, since trusting
	 * client-supplied recipients/templates there would turn the endpoint into an
	 * arbitrary-recipient email relay.
	 *
	 * @param int                  $submission_id Stored submission post ID.
	 * @param array<string, mixed> $request       Submission payload.
	 *
	 * @return array<string, array<int, string>|string>
	 */
	public function resolve_notification_config( int $submission_id, array $request ): array {
		$form_id         = isset( $request['form_id'] ) ? (int) $request['form_id'] : 0;
		$stored_settings = $form_id > 0 ? SwiftForms_CPTs::get_form_settings( $form_id ) : SwiftForms_CPTs::get_default_form_settings();
		$config          = isset( $request['notifications'] ) && is_array( $request['notifications'] )
			? $request['notifications']
			: array();

		$admin_recipients = $this->parse_notification_recipients( $config['adminRecipients'] ?? $stored_settings['adminRecipients'] ?? '' );

		// Fallback chain: form recipients → global default recipients
		// (Forms → Settings) → site admin email.
		if ( empty( $admin_recipients ) ) {
			$global_settings  = SwiftForms_Settings::get_settings();
			$admin_recipients = $this->parse_notification_recipients( $global_settings['defaultAdminRecipients'] );
		}

		if ( empty( $admin_recipients ) ) {
			$admin_recipients = array( (string) get_option( 'admin_email' ) );
		}

		$admin_subject_template         = isset( $config['adminSubject'] ) && '' !== trim( (string) $config['adminSubject'] )
			? (string) $config['adminSubject']
			: (string) $stored_settings['adminSubject'];
		$autoresponder_subject_template = isset( $config['autoresponderSubject'] ) && '' !== trim( (string) $config['autoresponderSubject'] )
			? (string) $config['autoresponderSubject']
			: (string) $stored_settings['autoresponderSubject'];

		return array(
			'adminRecipients'       => $admin_recipients,
			'adminSubject'          => sanitize_text_field( $this->render_notification_template( $admin_subject_template, $submission_id, $request ) ),
			'adminTemplate'         => isset( $config['adminTemplate'] ) && '' !== trim( (string) $config['adminTemplate'] )
				? (string) $config['adminTemplate']
				: (string) $stored_settings['adminTemplate'],
			'autoresponderSubject'  => sanitize_text_field( $this->render_notification_template( $autoresponder_subject_template, $submission_id, $request ) ),
			'autoresponderTemplate' => isset( $config['autoresponderTemplate'] ) && '' !== trim( (string) $config['autoresponderTemplate'] )
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
	public function parse_notification_recipients( mixed $recipients ): array {
		if ( is_array( $recipients ) ) {
			$candidate_recipients = $recipients;
		} else {
			$split_recipients     = preg_split( '/[\r\n,;]+/', (string) $recipients );
			$candidate_recipients = false === $split_recipients ? array() : $split_recipients;
		}

		$parsed = array();

		foreach ( $candidate_recipients as $candidate ) {
			$candidate = trim( (string) $candidate );

			if ( '' !== $candidate && is_email( $candidate ) ) {
				$parsed[] = $candidate;
			}
		}

		return array_values( array_unique( $parsed ) );
	}

	/**
	 * Renders a simple notification template with submission placeholders.
	 *
	 * Supported placeholders: {submission_id}, {form_id}, {fields}, and {field:slug}.
	 *
	 * @param string               $template      Template text with placeholders.
	 * @param int                  $submission_id Stored submission post ID.
	 * @param array<string, mixed> $request       Submission payload.
	 */
	public function render_notification_template( string $template, int $submission_id, array $request ): string {
		$field_map = $this->get_scalar_field_map( $request['fields'] ?? array() );

		$rendered = str_replace(
			array( '{submission_id}', '{form_id}', '{fields}' ),
			array(
				(string) $submission_id,
				(string) ( isset( $request['form_id'] ) ? (int) $request['form_id'] : 0 ),
				$this->format_field_lines( $field_map ),
			),
			$template
		);

		return (string) preg_replace_callback(
			'/\{field:([a-z0-9_\-]+)\}/i',
			static function ( array $matches ) use ( $field_map ): string {
				$slug = sanitize_key( $matches[1] );

				return $field_map[ $slug ] ?? '';
			},
			$rendered
		);
	}

	/**
	 * Returns the default email body when no template is configured.
	 *
	 * @param int                  $submission_id Stored submission post ID.
	 * @param array<string, mixed> $request       Submission payload.
	 */
	private function get_default_email_content( int $submission_id, array $request ): string {
		$lines = array(
			sprintf( 'Submission ID: %d', $submission_id ),
			sprintf( 'Form ID: %d', isset( $request['form_id'] ) ? (int) $request['form_id'] : 0 ),
		);

		foreach ( $this->get_scalar_field_map( $request['fields'] ?? array() ) as $slug => $value ) {
			$lines[] = sprintf( '%s: %s', $slug, $value );
		}

		return implode( "\n", $lines );
	}

	/**
	 * Returns scalar submission fields keyed by sanitized slug.
	 *
	 * @param mixed $fields Submitted field rows.
	 *
	 * @return array<string, string>
	 */
	private function get_scalar_field_map( mixed $fields ): array {
		if ( ! is_array( $fields ) ) {
			return array();
		}

		$field_map = array();

		foreach ( $fields as $field ) {
			$slug  = isset( $field['slug'] ) ? sanitize_key( (string) $field['slug'] ) : '';
			$value = $field['value'] ?? '';

			if ( '' === $slug || ! is_scalar( $value ) ) {
				continue;
			}

			$field_map[ $slug ] = (string) $value;
		}

		return $field_map;
	}

	/**
	 * Formats scalar fields for the {fields} template placeholder.
	 *
	 * @param array<string, string> $field_map Scalar submission fields.
	 */
	private function format_field_lines( array $field_map ): string {
		$lines = array();

		foreach ( $field_map as $slug => $value ) {
			$lines[] = sprintf( '%s: %s', $slug, $value );
		}

		return implode( "\n", $lines );
	}

	/**
	 * Finds the visitor email address for auto-responder delivery.
	 *
	 * A form can pin the autoresponder to a specific field slug via its
	 * `autoresponderField` setting; otherwise the first valid email-type
	 * field wins.
	 *
	 * @param array<int, array<string, mixed>> $fields         Submitted field rows.
	 * @param string                           $preferred_slug Optional slug configured on the form.
	 */
	public function get_autoresponder_recipient( array $fields, string $preferred_slug = '' ): string {
		if ( '' !== $preferred_slug ) {
			foreach ( $fields as $field ) {
				$slug  = isset( $field['slug'] ) ? sanitize_key( (string) $field['slug'] ) : '';
				$value = $field['value'] ?? '';

				if ( $slug === $preferred_slug && is_string( $value ) && is_email( $value ) ) {
					return $value;
				}
			}
		}

		foreach ( $fields as $field ) {
			$type  = isset( $field['type'] ) ? (string) $field['type'] : '';
			$value = $field['value'] ?? '';

			if ( 'email' === $type && is_string( $value ) && is_email( $value ) ) {
				return $value;
			}
		}

		return '';
	}

	/**
	 * POSTs the stored submission to the form's configured webhook URL.
	 *
	 * The URL comes exclusively from the stored form settings — never from the
	 * request — and the payload passes through `swiftforms_webhook_payload`
	 * so integrations can reshape it.
	 *
	 * @param int                  $submission_id Stored submission post ID (0 when entries are not saved).
	 * @param array<string, mixed> $request       Submission payload.
	 */
	public function send_webhook( int $submission_id, array $request ): void {
		$form_id = isset( $request['form_id'] ) ? (int) $request['form_id'] : 0;

		if ( $form_id <= 0 ) {
			return;
		}

		$settings = SwiftForms_CPTs::get_form_settings( $form_id );
		$url      = (string) ( $settings['webhookUrl'] ?? '' );

		if ( '' === $url || ! wp_http_validate_url( $url ) ) {
			return;
		}

		$payload = apply_filters(
			'swiftforms_webhook_payload',
			array(
				'fields'        => $this->get_scalar_field_map( $request['fields'] ?? array() ),
				'form_id'       => $form_id,
				'submission_id' => $submission_id,
				'submitted_at'  => gmdate( 'c' ),
			),
			$submission_id,
			$request
		);

		wp_remote_post(
			$url,
			array(
				'body'    => wp_json_encode( $payload ),
				'headers' => array( 'Content-Type' => 'application/json' ),
				'timeout' => 5,
			)
		);
	}

	/**
	 * Handles a validated file upload into the SwiftForms uploads directory.
	 *
	 * @param array<string, mixed> $file Uploaded file array.
	 *
	 * @return array<string, string>|WP_Error
	 */
	public function handle_file_upload( array $file ) {
		$tmp_name      = isset( $file['tmp_name'] ) ? (string) $file['tmp_name'] : '';
		$original_name = isset( $file['name'] ) ? (string) $file['name'] : '';

		if ( '' === $tmp_name || ! file_exists( $tmp_name ) ) {
			return new WP_Error( 'missing_file', __( 'The uploaded file could not be found.', 'swiftforms' ) );
		}

		$validation = $this->validate_field_type( 'file', $file );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		$allowed_types = apply_filters(
			'swiftforms_allowed_upload_types',
			array(
				'jpg|jpeg|jpe' => 'image/jpeg',
				'pdf'          => 'application/pdf',
				'png'          => 'image/png',
				'txt'          => 'text/plain',
			)
		);

		// Sniffs the real file content in addition to matching the extension,
		// so a script renamed to a permitted extension is still rejected.
		$filetype = wp_check_filetype_and_ext( $tmp_name, $original_name, $allowed_types );
		if ( empty( $filetype['ext'] ) || empty( $filetype['type'] ) ) {
			return new WP_Error( 'invalid_file_type', __( 'File type not allowed.', 'swiftforms' ) );
		}

		$uploads    = wp_upload_dir();
		$subdir     = '/swiftforms/' . gmdate( 'Y' ) . '/' . gmdate( 'm' );
		$target_dir = $uploads['basedir'] . $subdir;

		wp_mkdir_p( $target_dir );
		$this->protect_uploads_directory( $uploads['basedir'] . '/swiftforms' );

		$hashed_name = hash_file( 'sha256', $tmp_name ) . '.' . $filetype['ext'];
		$target_path = wp_unique_filename( $target_dir, $hashed_name );
		$destination = trailingslashit( $target_dir ) . $target_path;

		if ( ! copy( $tmp_name, $destination ) ) {
			return new WP_Error( 'upload_failed', __( 'The uploaded file could not be stored.', 'swiftforms' ) );
		}

		return array(
			'file' => $destination,
			'path' => $destination,
			'type' => $filetype['type'],
			'url'  => $uploads['baseurl'] . $subdir . '/' . basename( $destination ),
		);
	}

	/**
	 * Drops guard files into the uploads root so a misconfigured server won't execute
	 * anything an attacker manages to plant there.
	 *
	 * @param string $directory Absolute path of the uploads directory to protect.
	 */
	private function protect_uploads_directory( string $directory ): void {
		$index_file = trailingslashit( $directory ) . 'index.html';
		if ( ! file_exists( $index_file ) ) {
			file_put_contents( $index_file, '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Tiny static guard file; WP_Filesystem is unnecessary mid-request.
		}

		$htaccess_file = trailingslashit( $directory ) . '.htaccess';
		if ( ! file_exists( $htaccess_file ) ) {
			file_put_contents( $htaccess_file, "php_flag engine off\n<FilesMatch \"\\.ph(p[3457]?|t|tml)$\">\nRequire all denied\n</FilesMatch>\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Tiny static guard file; WP_Filesystem is unnecessary mid-request.
		}
	}

	/**
	 * Validates the submitted field collection.
	 *
	 * @param array<string, mixed> $request Submission payload.
	 *
	 * @return array<string, string>
	 */
	private function validate_fields( array $request ): array {
		$errors = array();
		$fields = $request['fields'] ?? array();

		if ( ! is_array( $fields ) ) {
			return array( 'fields' => __( 'Submitted fields are invalid.', 'swiftforms' ) );
		}

		foreach ( $fields as $field ) {
			$slug         = isset( $field['slug'] ) ? sanitize_key( (string) $field['slug'] ) : '';
			$type         = isset( $field['type'] ) ? (string) $field['type'] : 'text';
			$value        = $field['value'] ?? '';
			$field_config = is_array( $field ) ? $field : array();

			if ( $this->is_field_required( $field_config ) && $this->is_empty_value( $value ) ) {
				$errors[ '' === $slug ? 'field' : $slug ] = __( 'This field is required.', 'swiftforms' );

				continue;
			}

			$validation = $this->validate_field_type( $type, $value, $field_config );
			if ( is_wp_error( $validation ) ) {
				$errors[ '' === $slug ? 'field' : $slug ] = $validation->get_error_message();
			}
		}

		return $errors;
	}

	/**
	 * Determines whether a submitted field value counts as empty.
	 *
	 * Scalars are empty when they trim to an empty string. File fields arrive as
	 * arrays and are empty when no upload name or size is present.
	 *
	 * @param mixed $value Submitted field value.
	 */
	private function is_empty_value( mixed $value ): bool {
		if ( is_array( $value ) ) {
			$name = isset( $value['name'] ) ? trim( (string) $value['name'] ) : '';
			$size = isset( $value['size'] ) ? (int) $value['size'] : 0;

			return '' === $name && $size <= 0;
		}

		if ( is_scalar( $value ) ) {
			return '' === trim( (string) $value );
		}

		return true;
	}

	/**
	 * Merges uploaded files into the normalized request field payload.
	 *
	 * @param array<string, mixed> $request Submission payload.
	 * @param mixed                $uploaded_files Raw uploaded file data.
	 *
	 * @return array<string, mixed>
	 */
	private function merge_uploaded_files( array $request, mixed $uploaded_files ): array {
		$fields = $request['fields'] ?? array();

		if ( ! is_array( $fields ) || ! is_array( $uploaded_files ) || ! isset( $uploaded_files['name'] ) || ! is_array( $uploaded_files['name'] ) ) {
			return $request;
		}

		foreach ( $uploaded_files['name'] as $index => $name ) {
			if ( ! isset( $fields[ $index ] ) || ! is_array( $fields[ $index ] ) ) {
				continue;
			}

			$fields[ $index ]['value'] = array(
				'name'     => (string) $name,
				'size'     => isset( $uploaded_files['size'][ $index ] ) ? (int) $uploaded_files['size'][ $index ] : 0,
				'tmp_name' => isset( $uploaded_files['tmp_name'][ $index ] ) ? (string) $uploaded_files['tmp_name'][ $index ] : '',
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
	private function normalize_request( array $request ): array {
		$fields = $request['fields'] ?? array();

		if ( ! is_array( $fields ) ) {
			return $request;
		}

		foreach ( $fields as $index => $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}

			$type  = isset( $field['type'] ) ? (string) $field['type'] : 'text';
			$value = $field['value'] ?? '';

			if ( isset( $field['slug'] ) ) {
				$field['slug'] = sanitize_key( (string) $field['slug'] );
			}

			if ( isset( $field['required'] ) ) {
				$field['required'] = $this->is_truthy( $field['required'] );
			}

			if ( ( 'select' === $type || 'radio' === $type ) && array_key_exists( 'options', $field ) ) {
				$field['options'] = $this->normalize_select_options( $field['options'] );
			}

			if ( 'number' === $type ) {
				foreach ( array( 'min', 'max', 'step' ) as $numeric_key ) {
					if ( isset( $field[ $numeric_key ] ) ) {
						$field[ $numeric_key ] = trim( (string) $field[ $numeric_key ] );
					}
				}
			}

			$field['value']   = $this->normalize_field_value( $type, $value );
			$fields[ $index ] = $field;
		}

		$request['fields'] = $fields;

		return $request;
	}

	/**
	 * Normalizes a scalar field value for persistence and email rendering.
	 *
	 * @param string $type  Field type.
	 * @param mixed  $value Submitted value.
	 */
	private function normalize_field_value( string $type, mixed $value ): mixed {
		if ( 'file' === $type || ! is_scalar( $value ) ) {
			return $value;
		}

		$string_value = trim( (string) $value );

		if ( 'number' === $type && '' !== $string_value && is_numeric( $string_value ) ) {
			if ( preg_match( '/^-?\d+$/', $string_value ) ) {
				return (string) (int) $string_value;
			}

			return rtrim( rtrim( sprintf( '%.10F', (float) $string_value ), '0' ), '.' );
		}

		return $string_value;
	}

	/**
	 * Parses a newline-delimited options string into label/value pairs.
	 *
	 * Each line is `Label|value`, split on the first pipe only. A line with
	 * no pipe (or an empty value half) uses its label as the value, keeping
	 * legacy label-only options working unchanged. Empty labels are skipped.
	 * The editor mirrors these exact rules in field-utils.js.
	 *
	 * @param mixed $options Raw option configuration (string or line array).
	 *
	 * @return array<int, array{label: string, value: string}>
	 */
	public static function parse_option_pairs( mixed $options ): array {
		if ( is_array( $options ) ) {
			$lines = $options;
		} else {
			$split_lines = preg_split( '/\r?\n/', (string) $options );
			$lines       = false === $split_lines ? array() : $split_lines;
		}

		$pairs = array();

		foreach ( $lines as $line ) {
			$line       = (string) $line;
			$pipe_index = strpos( $line, '|' );
			$label      = trim( false === $pipe_index ? $line : substr( $line, 0, $pipe_index ) );
			$value      = trim( false === $pipe_index ? '' : substr( $line, $pipe_index + 1 ) );

			if ( '' === $label ) {
				continue;
			}

			$pairs[] = array(
				'label' => $label,
				'value' => '' !== $value ? $value : $label,
			);
		}

		return $pairs;
	}

	/**
	 * Normalizes select/radio options into the list of submittable values.
	 *
	 * @param mixed $options Raw option configuration.
	 *
	 * @return string[]
	 */
	private function normalize_select_options( mixed $options ): array {
		$values = array();

		foreach ( self::parse_option_pairs( $options ) as $pair ) {
			$values[] = $pair['value'];
		}

		return array_values( array_unique( $values ) );
	}

	/**
	 * Returns whether a field is marked as required.
	 *
	 * @param array<string, mixed> $field Submitted field configuration.
	 */
	private function is_field_required( array $field ): bool {
		return isset( $field['required'] ) && $this->is_truthy( $field['required'] );
	}

	/**
	 * Normalizes common truthy request values.
	 *
	 * @param mixed $value Raw request value.
	 */
	private function is_truthy( mixed $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}

		return in_array( strtolower( trim( (string) $value ) ), array( '1', 'true', 'on', 'yes' ), true );
	}

	/**
	 * Sends a JSON response for live AJAX requests and always returns the payload.
	 *
	 * @param array<string, mixed> $response         Response payload.
	 * @param bool                 $should_send_json Whether this is a live AJAX request.
	 * @param int                  $status_code      HTTP status code for the JSON response.
	 *
	 * @return array<string, mixed>
	 */
	private function maybe_send_json( array $response, bool $should_send_json, int $status_code ): array {
		if ( $should_send_json && wp_doing_ajax() ) {
			wp_send_json( $response, $status_code );
		}

		return $response;
	}
}
