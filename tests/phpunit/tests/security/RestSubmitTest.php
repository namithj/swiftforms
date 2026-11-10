<?php
/**
 * Tests for the submit route and the pipeline behind it.
 *
 * @package SwiftForms
 */

require_once dirname( __DIR__, 2 ) . '/includes/class-sf-submission-test-case.php';

use SwiftForms\Clock;
use SwiftForms\Field_Types;
use SwiftForms\Form_Index;
use SwiftForms\Render_Token;
use SwiftForms\Schema_Store;
use SwiftForms\Submission;
use SwiftForms\Submission_Context;
use SwiftForms\Time_Trap;

/**
 * Class RestSubmitTest
 *
 * @group submission
 * @group security
 */
class RestSubmitTest extends SF_Submission_Test_Case {

	/**
	 * Contexts seen by the hooks.
	 *
	 * @var Submission_Context[]
	 */
	private $saved = [];

	/**
	 * Contexts handed to the notifier.
	 *
	 * @var Submission_Context[]
	 */
	private $notified = [];

	/**
	 * Contexts seen by swiftforms_submission_complete.
	 *
	 * @var Submission_Context[]
	 */
	private $completed = [];

	/**
	 * Per-form limit off, hooks recorded.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		$this->set_settings( [ 'spam' => [ 'rate_limit_per_minute' => 0 ] ] );

		$this->saved     = [];
		$this->notified  = [];
		$this->completed = [];
		add_action(
			'swiftforms_entry_saved',
			function ( $entry_id, $ctx ) {
				$this->saved[] = [ $entry_id, $ctx ];
			},
			10,
			2
		);
		add_action(
			'swiftforms_submission_complete',
			function ( $ctx, $result ) {
				$this->completed[] = [ $ctx, $result ];
			},
			10,
			2
		);
		Submission::$notifier = function ( $ctx ) {
			$this->notified[] = $ctx;
		};
	}

	/**
	 * Remove the notifier.
	 *
	 * @return void
	 */
	public function tear_down() {
		Submission::$notifier = null;
		parent::tear_down();
	}

	/**
	 * A good submission: C-3.4 shape, one entry, hooks, notification.
	 *
	 * @return void
	 */
	public function test_success() {
		$this->make_form();
		$res = $this->submit( $this->good() );

		$this->assertSame( 200, $res->get_status() );
		$data                         = $res->get_data();
		$data['confirmation']['html'] = $this->norm( $data['confirmation']['html'] );
		$this->assertSame(
			[
				'status'       => 'success',
				'confirmation' => [
					'type' => 'message',
					'html' => '<p>Thanks!</p>',
				],
			],
			$data
		);

		$entries = $this->entries();
		$this->assertCount( 1, $entries );
		$this->assertSame( $this->form_id, $entries[0]['form_id'] );
		$this->assertSame( $this->schema()->hash, $entries[0]['schema_hash'] );
		$this->assertSame( $this->source, $entries[0]['source'] );
		$this->assertSame( 'new', $entries[0]['status'] );
		$this->assertSame( '0', $entries[0]['user_id'] );
		$this->assertSame(
			[
				'email'   => [ 'ada@example.com' ],
				'message' => [ 'Hello there' ],
				'name'    => [ 'Ada Lovelace' ],
			],
			SwiftForms\Entries_Repository::get( (int) $entries[0]['id'] )['values']
		);

		$this->assertCount( 1, $this->saved );
		$this->assertSame( (int) $entries[0]['id'], $this->saved[0][0] );
		$this->assertCount( 1, $this->notified );
		$this->assertSame( (int) $entries[0]['id'], $this->notified[0]->entry_id );
		$this->assertCount( 1, $this->completed );
		$this->assertSame( 'rest', $this->notified[0]->transport );
		$this->assertNull( $this->notified[0]->user );
		$this->assertSame( get_permalink( (int) substr( $this->source, 5 ) ), $this->notified[0]->page_url );
		$this->assertSame( 'Test page', $this->notified[0]->page_title );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', $this->notified[0]->client_key );
	}

	/**
	 * The response is never cached.
	 *
	 * @return void
	 */
	public function test_response_is_no_store() {
		$this->make_form();
		$headers = $this->submit( $this->good() )->get_headers();
		$this->assertStringContainsString( 'no-store', $headers['Cache-Control'] );
	}

	/**
	 * A guest never gets an entryId; an authenticated caller with the capability does; one without it does not.
	 *
	 * @return void
	 */
	public function test_entry_id_only_for_a_privileged_caller() {
		$this->make_form();

		$guest = $this->submit( $this->good( [ 'name' => 'Guest' ] ) )->get_data();
		$this->assertArrayNotHasKey( 'entryId', $guest );
		$this->assertStringNotContainsString( 'entryId', wp_json_encode( $guest ) );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$subscriber = $this->submit( $this->good( [ 'name' => 'Sub' ] ) )->get_data();
		$this->assertArrayNotHasKey( 'entryId', $subscriber );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$admin = $this->submit( $this->good( [ 'name' => 'Admin' ] ) )->get_data();
		$this->assertArrayHasKey( 'entryId', $admin );
		$last = $this->entries();
		$this->assertSame( (int) end( $last )['id'], $admin['entryId'] );
		$this->assertSame( 'success', $admin['status'] );
	}

	/**
	 * A logged-in submitter is the context's user; a guest is null.
	 *
	 * @return void
	 */
	public function test_user_in_context() {
		$this->make_form();
		$user_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $user_id );
		$this->submit( $this->good() );
		$this->assertSame( $user_id, $this->notified[0]->user_id() );
		$this->assertSame( (string) $user_id, $this->entries()[0]['user_id'] );
	}

	/**
	 * Spam and success are indistinguishable: same status, same body bytes, same headers; storage differs silently.
	 *
	 * @return void
	 */
	public function test_spam_response_is_byte_identical() {
		$this->make_form();
		$clean = $this->submit( $this->good() );

		$this->set_settings( [ 'spam' => [ 'blocked_words' => 'casino' ] ] );
		$spam_cases = [
			'honeypot'  => [ $this->good( [ 'name' => 'A' ] ), [ '_sf_hp' => 'http://spam.example' ] ],
			'too fast'  => [ $this->good( [ 'name' => 'B' ] ), [ '_sf_t' => 'js.100' ] ],
			'no js'     => [ $this->good( [ 'name' => 'C' ] ), [ '_sf_t' => Time_Trap::issue( $this->form_id, $this->source ) ] ],
			'bad token' => [ $this->good( [ 'name' => 'D' ] ), [ '_sf_token' => 'v1.abc.' . str_repeat( 'a', 32 ) ] ],
			'no token'  => [ $this->good( [ 'name' => 'E' ] ), [ '_sf_token' => null ] ],
			'word'      => [ $this->good( [ 'name' => 'Casino royale' ] ), [] ],
		];
		foreach ( $spam_cases as $label => $case ) {
			$spam = $this->submit( $case[0], $case[1] );
			$this->assertSame( $clean->get_status(), $spam->get_status(), $label );
			$this->assertSame( wp_json_encode( $clean->get_data() ), wp_json_encode( $spam->get_data() ), $label );
			$this->assertSame( $clean->get_headers(), $spam->get_headers(), $label );
		}

		$statuses = array_column( $this->entries(), 'status' );
		$this->assertSame(
			1,
			count(
				array_filter(
					$statuses,
					static function ( $s ) {
						return 'new' === $s;
					}
				)
			)
		);
		$this->assertSame(
			6,
			count(
				array_filter(
					$statuses,
					static function ( $s ) {
						return 'spam' === $s;
					}
				)
			),
			'Spam is stored with status spam by default.'
		);

		// Hooks and mail only for the clean one.
		$this->assertCount( 1, $this->saved );
		$this->assertCount( 1, $this->notified );
		$this->assertCount( 1, $this->completed );
	}

	/**
	 * Discarded spam gives the same answer and stores nothing.
	 *
	 * @return void
	 */
	public function test_discarded_spam_is_indistinguishable() {
		$this->make_form();
		$clean = $this->submit( $this->good() );
		$this->set_settings( [ 'spam' => [ 'spam_action' => 'discard' ] ] );
		$spam = $this->submit( $this->good( [ 'name' => 'Spammer' ] ), [ '_sf_hp' => 'x' ] );

		$this->assertSame( wp_json_encode( $clean->get_data() ), wp_json_encode( $spam->get_data() ) );
		$this->assertSame( $clean->get_status(), $spam->get_status() );
		$this->assertCount( 1, $this->entries(), 'Only the clean one.' );
	}

	/**
	 * A spam submission with invalid values still gets success; it is not validated.
	 *
	 * @return void
	 */
	public function test_spam_with_invalid_values_still_looks_like_success() {
		$this->make_form();
		$res = $this->submit( [ 'email' => 'not-an-email' ], [ '_sf_hp' => 'x' ] );
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( 'success', $res->get_data()['status'] );
	}

	/**
	 * A confirmation that uses {entry:id} does not tell spam from success or leak numbers.
	 *
	 * @return void
	 */
	public function test_entry_number_never_in_the_confirmation() {
		$this->make_form( $this->default_inner( '<!-- wp:paragraph --><p>Ref [{entry:id}] for {field:name}</p><!-- /wp:paragraph -->' ) );
		$clean = $this->submit( $this->good() );
		$spam  = $this->submit( $this->good(), [ '_sf_hp' => 'x' ] );
		$this->assertSame( $clean->get_data(), $spam->get_data() );
		$this->assertStringContainsString( 'Ref [] for Ada Lovelace', $clean->get_data()['confirmation']['html'] );
	}

	/**
	 * Invalid: 422 with errors keyed by field name, nothing stored, nothing echoed.
	 *
	 * @return void
	 */
	public function test_invalid() {
		$this->make_form();
		$res = $this->submit(
			[
				'name'    => '',
				'email'   => 'SECRET<script>',
				'message' => 'x',
			]
		);

		$this->assertSame( 422, $res->get_status() );
		$data = $res->get_data();
		$this->assertSame( 'invalid', $data['status'] );
		$this->assertSame( [ 'name', 'email' ], array_keys( (array) $data['errors'] ) );
		$this->assertSame( 'This field is required.', $data['errors']->name );
		$this->assertStringNotContainsString( 'SECRET', wp_json_encode( $data ) );
		$this->assertCount( 0, $this->entries() );
		$this->assertCount( 0, $this->saved );
		$this->assertCount( 0, $this->notified );

		// The same payload can be corrected and sent again at once: an invalid try is not a duplicate.
		$ok = $this->submit( $this->good( [ 'message' => 'x' ] ) );
		$this->assertSame( 200, $ok->get_status() );
	}

	/**
	 * Tampered option values, extra fields and hidden-field values (C-3.2, C-3.10).
	 *
	 * @return void
	 */
	public function test_tampering() {
		$inner = $this->field_markup(
			'text',
			[
				'name'  => 'name',
				'label' => 'Name',
			]
		)
			. $this->field_markup(
				'select',
				[
					'name'     => 'plan',
					'label'    => 'Plan',
					'required' => true,
					'options'  => [
						[
							'value' => 'free',
							'label' => 'Free',
						],
						[
							'value' => 'a,b|c',
							'label' => 'Odd',
						],
					],
				]
			)
			. $this->field_markup(
				'hidden',
				[
					'name'  => 'campaign',
					'value' => 'spring',
				]
			)
			. '<!-- wp:swiftforms/submit /-->';
		$this->make_form( $inner );

		$bad = $this->submit(
			[
				'name' => 'x',
				'plan' => 'enterprise',
			]
		);
		$this->assertSame( 422, $bad->get_status() );
		$this->assertSame( [ 'plan' ], array_keys( (array) $bad->get_data()['errors'] ) );

		$good = $this->submit(
			[
				'name'     => 'x',
				'plan'     => 'a,b|c',
				'campaign' => 'HACKED',
				'is_admin' => '1',
				'sf'       => [ 'nested' ],
			]
		);
		$this->assertSame( 200, $good->get_status() );
		$entry = SwiftForms\Entries_Repository::get( (int) $this->entries()[0]['id'] );
		$this->assertSame(
			[
				'campaign' => [ 'spring' ],
				'name'     => [ 'x' ],
				'plan'     => [ 'a,b|c' ],
			],
			$entry['values'],
			'The hidden value is recomputed and the extra fields are gone.'
		);
	}

	/**
	 * Form-encoded bodies work like JSON.
	 *
	 * @return void
	 */
	public function test_form_encoded() {
		$this->make_form();
		$request = new WP_REST_Request( 'POST', '/swiftforms/v1/submit' );
		$request->set_header( 'content-type', 'application/x-www-form-urlencoded' );
		$request->set_body_params( array_merge( $this->controls(), [ 'sf' => $this->good() ] ) );
		$res = rest_do_request( $request );
		$this->assertSame( 200, $res->get_status() );
		$this->assertCount( 1, $this->entries() );
	}

	/**
	 * Wrong types in the body are treated as missing, never as errors in the server.
	 *
	 * @return void
	 */
	public function test_weird_bodies() {
		$this->make_form();
		foreach ( [
			[],
			[ 'sf' => 'string' ],
			[
				'_sf_form'   => [ 'a' ],
				'_sf_source' => 5,
			],
			[
				'_sf_form'   => $this->form_id,
				'_sf_source' => $this->source,
				'sf'         => [ 'name' => [ [ [ 'deep' ] ] ] ],
			],
		] as $body ) {
			$request = new WP_REST_Request( 'POST', '/swiftforms/v1/submit' );
			$request->set_header( 'content-type', 'application/json' );
			$request->set_body( wp_json_encode( $body ) );
			$res = rest_do_request( $request );
			$this->assertContains( $res->get_status(), [ 403, 422, 200 ] );
			$this->assertArrayHasKey( 'status', $res->get_data() );
		}
	}

	/**
	 * An expired token answers expired; so does nothing else.
	 *
	 * @return void
	 */
	public function test_expired() {
		$this->make_form();
		$token = Render_Token::issue( $this->form_id, $this->source, $this->schema()->hash );
		Clock::freeze( time() + 8 * DAY_IN_SECONDS );

		$res = $this->submit( $this->good(), [ '_sf_token' => $token ] );
		$this->assertSame( 403, $res->get_status() );
		$this->assertSame( [ 'status' => 'expired' ], $res->get_data() );
		$this->assertCount( 0, $this->entries() );

		// A forged token that LOOKS expired is spam, not "expired".
		$forged = $this->submit( $this->good(), [ '_sf_token' => 'v1.1.' . str_repeat( 'a', 32 ) ] );
		$this->assertSame( 200, $forged->get_status() );
	}

	/**
	 * Stale schema (C-3.8): fields are validated against the snapshot, everything else from the current form.
	 *
	 * @return void
	 */
	public function test_stale_schema() {
		$page = $this->make_form( $this->default_inner(), [ 'title' => 'Old title' ] );
		$old  = $this->schema();
		$this->assertTrue( Schema_Store::has( $old->hash ), 'The index saved the first snapshot.' );
		$token = Render_Token::issue( $this->form_id, $this->source, $old->hash );

		// The form is edited: "email" becomes a number field, "extra" is required, and a notification appears.
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		wp_update_post(
			wp_slash(
				[
					'ID'           => $page,
					'post_content' => $this->form_markup(
						$this->form_id,
						$this->field_markup(
							'text',
							[
								'name'     => 'name',
								'label'    => 'Name',
								'required' => true,
							]
						)
						. $this->field_markup(
							'number',
							[
								'name'  => 'email',
								'label' => 'Email',
							]
						)
						. $this->field_markup(
							'text',
							[
								'name'     => 'extra',
								'label'    => 'Extra',
								'required' => true,
							]
						),
						[
							'title'         => 'New title',
							'notifications' => [
								[
									'to'      => 'new-recipient@example.com',
									'subject' => 'S',
									'body'    => 'B',
								],
							],
						]
					),
				]
			)
		);
		wp_set_current_user( 0 );
		Form_Index::flush_cache();
		$new = $this->schema();
		$this->assertNotSame( $old->hash, $new->hash );

		// Values valid for the OLD schema are accepted, with the old schema's hash.
		$res = $this->submit(
			[
				'name'  => 'Ada',
				'email' => 'ada@example.com',
			],
			[ '_sf_token' => $token ]
		);
		$this->assertSame( 200, $res->get_status(), wp_json_encode( $res->get_data() ) );

		$entries = $this->entries();
		$this->assertCount( 1, $entries );
		$this->assertSame( $old->hash, $entries[0]['schema_hash'], 'Stored with the schema the visitor saw.' );
		$this->assertSame(
			[
				'email' => [ 'ada@example.com' ],
				'name'  => [ 'Ada' ],
			],
			SwiftForms\Entries_Repository::get( (int) $entries[0]['id'] )['values']
		);

		// The context carries both: validation against the snapshot, recipients and routing from the current form.
		$ctx = $this->notified[0];
		$this->assertSame( $old->hash, $ctx->validation_schema->hash );
		$this->assertSame( $new->hash, $ctx->form->hash );
		$this->assertSame( 'New title', $ctx->form->title() );
		$this->assertSame( 'new-recipient@example.com', $ctx->form->attributes['notifications'][0]['to'] );
		$this->assertTrue( Schema_Store::has( $old->hash ) );

		// A token for the NEW schema is validated against the new one.
		$token_new = Render_Token::issue( $this->form_id, $this->source, $new->hash );
		$res       = $this->submit(
			[
				'name'  => 'Ada',
				'email' => 'ada@example.com',
			],
			[ '_sf_token' => $token_new ]
		);
		$this->assertSame( 422, $res->get_status(), 'email is a number field now and "extra" is required.' );
		$this->assertSame( [ 'email', 'extra' ], array_keys( (array) $res->get_data()['errors'] ) );
	}

	/**
	 * A copy with identical fields shares the snapshot row of the first form; the copy's own history still finds the old
	 * hash after an edit, so a visitor holding the pre-edit token is validated against the old snapshot (not spam).
	 *
	 * @return void
	 */
	public function test_stale_token_of_a_copy_with_a_shared_snapshot() {
		$stored = wp_generate_uuid4();
		$inner  = $this->default_inner();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$owner = $this->make_post( $this->form_markup( $stored, $inner ) );
		$copy  = $this->make_post( $this->form_markup( $stored, $inner ) );
		wp_set_current_user( 0 );

		$this->source  = 'post:' . $copy;
		$this->form_id = Form_Index::copy_id( $stored, $this->source );
		Form_Index::flush_cache();
		$old = $this->schema();
		$this->assertSame( $this->form_id, $old->form_id );
		$this->assertNotSame( $stored, $this->form_id );
		$this->assertSame( [], Schema_Store::recent_hashes( $this->form_id ), 'The snapshot row belongs to the owner.' );
		$this->assertSame( [ $old->hash ], Form_Index::hash_history( $this->form_id ) );
		$this->assertNotNull( $owner );

		$token = Render_Token::issue( $this->form_id, $this->source, $old->hash );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		wp_update_post(
			wp_slash(
				[
					'ID'           => $copy,
					'post_content' => $this->form_markup(
						$stored,
						$inner . $this->field_markup(
							'text',
							[
								'name'     => 'extra',
								'required' => true,
							]
						)
					),
				]
			)
		);
		wp_set_current_user( 0 );
		Form_Index::flush_cache();
		$new = $this->schema();
		$this->assertNotSame( $old->hash, $new->hash );
		$this->assertSame( [ $new->hash, $old->hash ], Form_Index::hash_history( $this->form_id ) );

		$res = $this->submit( $this->good(), [ '_sf_token' => $token ] );
		$this->assertSame( 200, $res->get_status() );
		$entries = $this->entries();
		$this->assertCount( 1, $entries );
		$this->assertSame( 'new', $entries[0]['status'], 'Not spam.' );
		$this->assertSame( $old->hash, $entries[0]['schema_hash'], 'Validated against, and stored with, the old snapshot.' );
		$this->assertSame( $old->hash, $this->notified[0]->validation_schema->hash );
		$this->assertSame( $new->hash, $this->notified[0]->form->hash );
	}

	/**
	 * The hash history is distinct, newest first, capped, and survives status changes.
	 *
	 * @return void
	 */
	public function test_hash_history() {
		$page   = $this->make_form();
		$first  = $this->schema()->hash;
		$hashes = [ $first ];
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		for ( $i = 1; $i <= 27; $i++ ) {
			wp_update_post(
				wp_slash(
					[
						'ID'           => $page,
						'post_content' => $this->form_markup( $this->form_id, $this->field_markup( 'text', [ 'name' => 'f' . $i ] ) ),
					]
				)
			);
			Form_Index::flush_cache();
			array_unshift( $hashes, $this->schema()->hash );
		}
		// Back to an earlier version: moves to the front, no duplicate.
		wp_update_post(
			wp_slash(
				[
					'ID'           => $page,
					'post_content' => $this->form_markup( $this->form_id, $this->field_markup( 'text', [ 'name' => 'f27' ] ) ),
				]
			)
		);
		wp_update_post(
			wp_slash(
				[
					'ID'          => $page,
					'post_status' => 'draft',
				]
			)
		);
		wp_update_post(
			wp_slash(
				[
					'ID'          => $page,
					'post_status' => 'publish',
				]
			)
		);
		Form_Index::flush_cache();
		$history = Form_Index::hash_history( $this->form_id );
		$this->assertCount( 25, $history );
		$this->assertSame( $hashes[0], $history[0] );
		$this->assertSame( $hashes[1], $history[1] );
		$this->assertSame( count( $history ), count( array_unique( $history ) ) );
		$this->assertNotContains( $first, $history );
	}

	/**
	 * Entries are stored BEFORE the notification; a failing notifier never loses the entry (C-3.3).
	 *
	 * @return void
	 */
	public function test_entry_is_stored_before_the_notification() {
		$this->make_form();
		$seen                 = [];
		Submission::$notifier = function () use ( &$seen ) {
			$seen['entries_at_notify'] = count( $this->entries() );
			$seen['saved_at_notify']   = count( $this->saved );
			throw new RuntimeException( 'SMTP is down' );
		};

		$res = $this->submit( $this->good() );
		$this->assertSame( 200, $res->get_status(), 'A mail failure is not the visitor\'s problem.' );
		$this->assertSame( 'success', $res->get_data()['status'] );
		$this->assertSame( 1, $seen['entries_at_notify'], 'The entry was already in the database.' );
		$this->assertSame( 1, $seen['saved_at_notify'], 'swiftforms_entry_saved fired first.' );
		$this->assertCount( 1, $this->entries() );
		$this->assertCount( 1, $this->completed, 'The submission still completes.' );

		$log = array_column( $this->rows( 'log' ), 'message' );
		$this->assertContains( 'The notification failed: RuntimeException', $log );
		$this->assertStringNotContainsString( 'SMTP', implode( ' ', $log ), 'The exception message is not logged.' );
	}

	/**
	 * Storage settings: on, off, the form override, and no storage still emails.
	 *
	 * @return void
	 */
	public function test_storage_switches() {
		$this->make_form();
		$this->submit( $this->good( [ 'name' => 'One' ] ) );
		$this->assertCount( 1, $this->entries() );

		// Off in the settings: nothing stored, mail still sent, no entry hook.
		$this->set_settings( [ 'general' => [ 'store_entries' => false ] ] );
		$res = $this->submit( $this->good( [ 'name' => 'Two' ] ) );
		$this->assertSame( 'success', $res->get_data()['status'] );
		$this->assertCount( 1, $this->entries() );
		$this->assertCount( 2, $this->notified );
		$this->assertSame( 0, $this->notified[1]->entry_id );
		$this->assertCount( 1, $this->saved );
		$this->assertCount( 2, $this->completed );

		// The form says yes: stored although the setting says no.
		$this->make_form( null, [ 'storeEntries' => 'yes' ] );
		$this->submit( $this->good( [ 'name' => 'Three' ] ) );
		$this->assertCount( 2, $this->entries() );

		// The form says no: not stored although the setting says yes.
		$this->set_settings( [ 'general' => [ 'store_entries' => true ] ] );
		$this->make_form( null, [ 'storeEntries' => 'no' ] );
		$this->submit( $this->good( [ 'name' => 'Four' ] ) );
		$this->assertCount( 2, $this->entries() );
		$this->assertCount( 4, $this->notified );
	}

	/**
	 * Spam is not stored when storage is off, and not stored when it is discarded.
	 *
	 * @return void
	 */
	public function test_spam_storage() {
		$this->make_form();
		$this->submit( $this->good(), [ '_sf_hp' => 'x' ] );
		$this->assertSame( [ 'spam' ], array_column( $this->entries(), 'status' ) );

		$this->set_settings( [ 'general' => [ 'store_entries' => false ] ] );
		$this->submit( $this->good( [ 'name' => 'Other' ] ), [ '_sf_hp' => 'x' ] );
		$this->assertCount( 1, $this->entries() );
		$this->assertCount( 0, $this->notified );
	}

	/**
	 * Duplicate guard: the same payload from the same client within 10 seconds is stored once and answered as a success.
	 *
	 * @return void
	 */
	public function test_duplicate_guard() {
		$this->make_form();
		Clock::freeze( time() );

		$first = $this->submit( $this->good() );
		$twice = $this->submit( $this->good() );
		$this->assertSame( 200, $twice->get_status() );
		$this->assertSame( $first->get_data(), $twice->get_data(), 'Same shape and content.' );
		$this->assertCount( 1, $this->entries(), 'Stored once.' );
		$this->assertCount( 1, $this->notified, 'Mailed once.' );

		// Another client with the same payload is not a duplicate.
		$_SERVER['REMOTE_ADDR'] = '198.51.100.20';
		$this->submit( $this->good() );
		$this->assertCount( 2, $this->entries() );

		// Another payload from the first client is not a duplicate.
		$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
		$this->submit( $this->good( [ 'message' => 'different' ] ) );
		$this->assertCount( 3, $this->entries() );

		// Ten seconds later the same payload is a new submission.
		Clock::freeze( time() + 11 );
		$this->submit( $this->good() );
		$this->assertCount( 4, $this->entries() );
	}

	/**
	 * The duplicate answer is the caller's own: an administrator's entry ID is never repeated to a duplicate sender.
	 *
	 * @return void
	 */
	public function test_duplicate_never_returns_another_clients_result() {
		$this->make_form();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$first = $this->submit( $this->good() );
		$this->assertArrayHasKey( 'entryId', $first->get_data() );

		wp_set_current_user( 0 );
		$guest = $this->submit( $this->good() );
		$this->assertArrayNotHasKey( 'entryId', $guest->get_data(), 'A guest sending the same payload from the same address gets no ID.' );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$again = $this->submit( $this->good() );
		$this->assertArrayNotHasKey( 'entryId', $again->get_data(), 'A duplicate has no entry of its own.' );
		$this->assertCount( 1, $this->entries() );
	}

	/**
	 * The per-form limiter answers 429 after the configured number, per client.
	 *
	 * @return void
	 */
	public function test_per_form_limiter() {
		$this->make_form();
		$this->set_settings( [ 'spam' => [ 'rate_limit_per_minute' => 2 ] ] );

		$this->assertSame( 200, $this->submit( $this->good( [ 'name' => 'One' ] ) )->get_status() );
		$this->assertSame( 200, $this->submit( $this->good( [ 'name' => 'Two' ] ) )->get_status() );
		$third = $this->submit( $this->good( [ 'name' => 'Three' ] ) );
		$this->assertSame( 429, $third->get_status() );
		$this->assertSame( [ 'status' => 'rate_limited' ], $third->get_data() );
		$this->assertCount( 2, $this->entries() );

		$_SERVER['REMOTE_ADDR'] = '198.51.100.20';
		$this->assertSame( 200, $this->submit( $this->good( [ 'name' => 'Other client' ] ) )->get_status() );
	}

	/**
	 * The per-form limiter is counted only after the schema resolves: closed requests do not use it up.
	 *
	 * @return void
	 */
	public function test_per_form_limiter_is_counted_after_the_schema() {
		$this->make_form();
		$this->set_settings( [ 'spam' => [ 'rate_limit_per_minute' => 2 ] ] );

		for ( $i = 0; $i < 10; $i++ ) {
			$res = $this->submit( $this->good(), [ '_sf_form' => wp_generate_uuid4() ] );
			$this->assertSame( 403, $res->get_status() );
		}
		$this->assertSame( 200, $this->submit( $this->good( [ 'name' => 'One' ] ) )->get_status() );
		$this->assertSame( 200, $this->submit( $this->good( [ 'name' => 'Two' ] ) )->get_status() );
		$this->assertSame( 429, $this->submit( $this->good( [ 'name' => 'Three' ] ) )->get_status() );
	}

	/**
	 * Hooks get the documented arguments.
	 *
	 * @return void
	 */
	public function test_hook_arguments() {
		$this->make_form();
		$this->submit( $this->good() );

		list( $entry_id, $ctx ) = $this->saved[0];
		$this->assertIsInt( $entry_id );
		$this->assertInstanceOf( Submission_Context::class, $ctx );
		$this->assertSame(
			[
				'name'    => 'Ada Lovelace',
				'email'   => 'ada@example.com',
				'message' => 'Hello there',
			],
			$ctx->values
		);
		$this->assertFalse( $ctx->is_spam );
		$this->assertSame( 'new', $ctx->status );
		$this->assertSame( '', $ctx->spam_reason );
		$this->assertSame( $this->source, $ctx->source );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $ctx->created_gmt );

		list( $ctx2, $result ) = $this->completed[0];
		$this->assertSame( $ctx, $ctx2 );
		$this->assertInstanceOf( SwiftForms\Submission_Result::class, $result );
		$this->assertSame( 'success', $result->status );
	}

	/**
	 * A source that is not open answers closed from the pipeline too (password pages, drafts that slipped past the index).
	 *
	 * @return void
	 */
	public function test_closed_when_the_source_is_not_open() {
		$page = $this->make_form( null, [], [ 'post_password' => 'secret' ] );
		$this->assertTrue( Form_Index::is_live( $this->form_id, $this->source ), 'The index calls a password page live; the request decides.' );

		$res = $this->submit( $this->good() );
		$this->assertSame( 403, $res->get_status() );
		$this->assertSame( [ 'status' => 'closed' ], $res->get_data() );

		// With the visitor's cookie it opens.
		require_once ABSPATH . WPINC . '/class-phpass.php';
		$hasher                                 = new PasswordHash( 8, true );
		$_COOKIE[ 'wp-postpass_' . COOKIEHASH ] = $hasher->HashPassword( 'secret' );
		$this->assertSame( 200, $this->submit( $this->good() )->get_status() );
		unset( $_COOKIE[ 'wp-postpass_' . COOKIEHASH ] );
		$this->assertNotNull( $page );
	}

	/**
	 * Every source type runs the full pipeline.
	 *
	 * @return void
	 */
	public function test_every_source_type() {
		$form  = function ( $id ) {
			return $this->form_markup( $id, $this->default_inner() );
		};
		$cases = [];

		// Page (the baseline).
		$cases['page'] = function () {
			$this->make_form();
		};

		// Synced pattern shown on a page.
		$cases['synced pattern'] = function () use ( $form ) {
			$id = wp_generate_uuid4();
			wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
			$block = $this->make_post( $form( $id ), [ 'post_type' => 'wp_block' ] );
			$this->make_post( '<!-- wp:block {"ref":' . $block . '} /-->' );
			wp_set_current_user( 0 );
			$this->source  = 'post:' . $block;
			$this->form_id = $id;
		};

		// Database template and part of the active theme.
		$cases['template']      = function () use ( $form ) {
			$id = wp_generate_uuid4();
			$this->make_theme( 'sf-p4a' );
			$this->make_template_post( 'wp_template', 'sf-p4a', 'single', $form( $id ) );
			wp_set_current_user( 0 );
			$this->source  = 'template:sf-p4a//single';
			$this->form_id = $id;
		};
		$cases['template part'] = function () use ( $form ) {
			$id = wp_generate_uuid4();
			$this->make_theme( 'sf-p4b' );
			$this->make_template_post( 'wp_template_part', 'sf-p4b', 'footer', $form( $id ) );
			wp_set_current_user( 0 );
			$this->source  = 'template_part:sf-p4b//footer';
			$this->form_id = $id;
		};

		// A template file of the theme.
		$cases['file template'] = function () use ( $form ) {
			$id = wp_generate_uuid4();
			$this->make_theme( 'sf-p4c', [ 'templates/single.html' => $form( $id ) ] );
			Form_Index::rebuild();
			$this->source  = 'template:sf-p4c//single';
			$this->form_id = $id;
		};

		// A block widget in an active sidebar.
		$cases['widget'] = function () use ( $form ) {
			$id                              = wp_generate_uuid4();
			$GLOBALS['_wp_sidebars_widgets'] = null; // The REST server of set_up() cached the default sidebars.
			$this->place_widget( 7, $form( $id ), 'sf-sidebar' );
			$GLOBALS['_wp_sidebars_widgets'] = null;
			Form_Index::reindex_widgets();
			$this->source  = 'widget:block-7';
			$this->form_id = $id;
		};

		// A file pattern referenced by core/pattern from an active template.
		$cases['core/pattern in a template'] = function () use ( $form ) {
			$id = wp_generate_uuid4();
			$this->register_pattern( 'sf/p4-contact', $form( $id ) );
			$this->make_theme( 'sf-p4d', [ 'templates/single.html' => '<!-- wp:pattern {"slug":"sf/p4-contact"} /-->' ] );
			Form_Index::rebuild();
			$this->source  = 'pattern:sf/p4-contact';
			$this->form_id = $id;
		};

		foreach ( $cases as $label => $setup ) {
			$before = count( $this->entries() );
			$setup();
			Form_Index::flush_cache();
			$this->assertTrue( Form_Index::is_live( $this->form_id, $this->source ), $label . ' is live.' );

			$res = $this->submit( $this->good() );
			$this->assertSame( 200, $res->get_status(), $label . ': ' . wp_json_encode( $res->get_data() ) );
			$this->assertSame( 'success', $res->get_data()['status'], $label );
			$this->assertCount( $before + 1, $this->entries(), $label );
			$last = $this->entries();
			$this->assertSame( $this->source, end( $last )['source'], $label );
			$this->assertSame( $this->form_id, end( $last )['form_id'], $label );
		}
	}

	/**
	 * Field definitions come from the stored form, so the pipeline never needs the request's idea of them.
	 *
	 * @return void
	 */
	public function test_client_schema_is_ignored() {
		$this->make_form();
		$request = new WP_REST_Request( 'POST', '/swiftforms/v1/submit' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array_merge(
					$this->controls(),
					[
						'sf'      => [
							'name'  => 'A',
							'email' => 'nope',
						],
						'schema'  => [
							'fields' => [
								[
									'type'     => 'text',
									'name'     => 'email',
									'required' => false,
								],
							],
						],
						'fields'  => [
							[
								'name' => 'email',
								'type' => 'text',
							],
						],
						'options' => [ 'x' ],
					]
				)
			)
		);
		$res = rest_do_request( $request );
		$this->assertSame( 422, $res->get_status() );
		$this->assertSame( [ 'email' ], array_keys( (array) $res->get_data()['errors'] ) );
		$this->assertNotEmpty( Field_Types::types() );
	}
}
