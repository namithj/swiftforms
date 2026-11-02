<?php
/**
 * Submission result.
 *
 * @package SwiftForms
 */

namespace SwiftForms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Submission_Result
 *
 * What the public routes answer (C-3.4): a status, an HTTP code and a body. Built only through the
 * named constructors, so every route answers the same shapes. Statuses are additive; a client treats
 * an unknown one as "blocked".
 *
 *   success       200 { status, confirmation: { type: message|redirect, html?|url? } }
 *   invalid       422 { status, errors: { field: message } }
 *   expired       403 { status }
 *   closed        403 { status }
 *   rate_limited  429 { status }
 *   blocked       403 { status }
 *
 * The result never holds the visitor's input. entry_id is kept apart from the body: only the REST
 * layer adds "entryId", and only for an authenticated caller who may view entries.
 */
class Submission_Result {

	const SUCCESS      = 'success';
	const INVALID      = 'invalid';
	const EXPIRED      = 'expired';
	const CLOSED       = 'closed';
	const RATE_LIMITED = 'rate_limited';
	const BLOCKED      = 'blocked';

	/**
	 * HTTP status by result status.
	 */
	const HTTP = [
		self::SUCCESS      => 200,
		self::INVALID      => 422,
		self::EXPIRED      => 403,
		self::CLOSED       => 403,
		self::RATE_LIMITED => 429,
		self::BLOCKED      => 403,
	];

	/**
	 * Status.
	 *
	 * @var string
	 */
	public $status = self::BLOCKED;

	/**
	 * HTTP code.
	 *
	 * @var int
	 */
	public $http = 403;

	/**
	 * Field errors (invalid only): name => message.
	 *
	 * @var array<string, string>
	 */
	public $errors = [];

	/**
	 * Confirmation (success only): { type: message, html } or { type: redirect, url }.
	 *
	 * @var array<string, string>
	 */
	public $confirmation = [];

	/**
	 * Stored entry ID, 0 when none. Not part of the body.
	 *
	 * @var int
	 */
	public $entry_id = 0;

	/**
	 * Values the visitor sent, sanitised (for the no-JS re-render). Not part of the body.
	 *
	 * @var array<string, string|string[]>
	 */
	public $values = [];

	/**
	 * Submission context (success, invalid and expired when it was built). Not part of the body.
	 *
	 * @var Submission_Context|null
	 */
	public $context = null;

	/**
	 * A result with a status.
	 *
	 * @param string $status One of the status constants.
	 * @return Submission_Result
	 */
	public static function make( $status ) {
		$result         = new self();
		$result->status = isset( self::HTTP[ $status ] ) ? $status : self::BLOCKED;
		$result->http   = self::HTTP[ $result->status ];
		return $result;
	}

	/**
	 * Success with a confirmation.
	 *
	 * @param array<string, string> $confirmation Confirmation (Confirmation::build()).
	 * @param int                   $entry_id     Stored entry ID, 0 for none.
	 * @return Submission_Result
	 */
	public static function success( array $confirmation, $entry_id = 0 ) {
		$result               = self::make( self::SUCCESS );
		$result->confirmation = $confirmation;
		$result->entry_id     = (int) $entry_id;
		return $result;
	}

	/**
	 * Validation failed.
	 *
	 * @param array<string, string> $errors Field name => message.
	 * @return Submission_Result
	 */
	public static function invalid( array $errors ) {
		$result         = self::make( self::INVALID );
		$result->errors = $errors;
		return $result;
	}

	/**
	 * The JSON body.
	 *
	 * @param bool $include_entry_id Whether to add "entryId" (success only; the REST layer decides).
	 * @return array<string, mixed>
	 */
	public function body( $include_entry_id = false ) {
		$body = [ 'status' => $this->status ];

		if ( self::SUCCESS === $this->status ) {
			$body['confirmation'] = $this->confirmation;
			if ( $include_entry_id && $this->entry_id > 0 ) {
				$body['entryId'] = $this->entry_id;
			}
		} elseif ( self::INVALID === $this->status ) {
			$body['errors'] = (object) $this->errors;
		}

		return $body;
	}
}
