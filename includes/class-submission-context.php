<?php
/**
 * Submission context.
 *
 * @package SwiftForms
 */

namespace SwiftForms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Submission_Context
 *
 * Everything about one submission, shared by the pipeline, the hooks, the merge-tag engine,
 * the mailer (P5) and the admin (P7). A plain value object: public properties, no behaviour.
 *
 * - form              The CURRENT Form_Schema. Recipients, routing, limits and the confirmation come from it (C-3.8).
 * - validation_schema The schema snapshot the fields were validated against (the token's schema_hash). It is
 *                     the current schema unless the visitor's page was cached before the form was edited.
 * - source            Source reference the form was submitted from.
 * - values            Field name => string, or list of strings for multi-value fields. One key per field of the
 *                     validation schema, empty string (or empty list) for an empty value. Plain text, never HTML.
 * - entry_id          Stored entry ID, 0 when nothing was stored.
 * - status            new or spam (the status the entry is stored with).
 * - is_spam           Whether the submission was judged spam. Spam never reaches notifications or integrations.
 * - spam_reason       Machine reason (honeypot, time_trap, ...); empty for clean submissions. Never shown to visitors.
 * - user              The authenticated user (cookie and a valid nonce), or null for a guest.
 * - client_key        Salted HMAC of the visitor's IP (Client_Ip::client_key()).
 * - page_url          Page the form was on, as far as the server can verify it; may be empty.
 * - page_title        Title of that page; empty when it is not a post.
 * - created_gmt       UTC time of the submission, "Y-m-d H:i:s".
 * - transport         rest or nojs.
 */
class Submission_Context {

	/**
	 * Current form.
	 *
	 * @var Form_Schema
	 */
	public $form;

	/**
	 * Schema the fields were validated against.
	 *
	 * @var Form_Schema
	 */
	public $validation_schema;

	/**
	 * Source reference.
	 *
	 * @var string
	 */
	public $source = '';

	/**
	 * Submitted values.
	 *
	 * @var array<string, string|string[]>
	 */
	public $values = [];

	/**
	 * Entry ID, 0 when not stored.
	 *
	 * @var int
	 */
	public $entry_id = 0;

	/**
	 * Entry status: new or spam.
	 *
	 * @var string
	 */
	public $status = 'new';

	/**
	 * Whether the submission is spam.
	 *
	 * @var bool
	 */
	public $is_spam = false;

	/**
	 * Why it is spam.
	 *
	 * @var string
	 */
	public $spam_reason = '';

	/**
	 * The authenticated user, or null.
	 *
	 * @var \WP_User|null
	 */
	public $user = null;

	/**
	 * Client key.
	 *
	 * @var string
	 */
	public $client_key = '';

	/**
	 * Page URL.
	 *
	 * @var string
	 */
	public $page_url = '';

	/**
	 * Page title.
	 *
	 * @var string
	 */
	public $page_title = '';

	/**
	 * UTC time.
	 *
	 * @var string
	 */
	public $created_gmt = '';

	/**
	 * Transport: rest or nojs.
	 *
	 * @var string
	 */
	public $transport = 'rest';

	/**
	 * Build a context.
	 *
	 * @param Form_Schema          $form              Current form.
	 * @param Form_Schema|null     $validation_schema Schema validated against (defaults to the current form).
	 * @param array<string, mixed> $data              Optional property values by name.
	 */
	public function __construct( Form_Schema $form, ?Form_Schema $validation_schema = null, array $data = [] ) {
		$this->form              = $form;
		$this->validation_schema = $validation_schema ?? $form;
		$this->source            = $form->source;
		$this->created_gmt       = Clock::gmt_mysql();

		foreach ( $data as $key => $value ) {
			if ( property_exists( $this, $key ) ) {
				$this->$key = $value;
			}
		}
	}

	/**
	 * Id of the authenticated user, 0 for a guest.
	 *
	 * @return int
	 */
	public function user_id() {
		return $this->user instanceof \WP_User ? (int) $this->user->ID : 0;
	}
}
