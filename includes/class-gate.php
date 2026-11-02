<?php
/**
 * The pre-step of every public route.
 *
 * @package SwiftForms
 */

namespace SwiftForms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Gate
 *
 * What every public route does before it looks at anything else (ADR-002, C-3.11, security checklist
 * §public-endpoints), in this order, and cheap enough that a flood of garbage costs almost nothing:
 *
 * 1. the coarse per-client throttle (Throttle::allow()) -> rate_limited 429;
 * 2. request size: a body of at most 256 KB and at most 500 fields -> blocked 403;
 * 3. the (effective formId, source) pair must be a live location in the form index, one indexed query
 *    (Form_Index::is_live()); a pair that fails costs nothing more -> closed 403.
 *
 * A value that is not a canonical UUID or a valid source reference is refused without a query.
 *
 * check() returns null to continue, or the result to answer with. On REST routes WordPress parses the
 * body before the route's callback runs, so Rest_Public runs early() on rest_pre_dispatch (steps 1 and
 * the declared body size) and the callback then continues with check(), which does not count the
 * request twice.
 */
class Gate {

	/**
	 * Largest request body in bytes (JSON or form post, without files).
	 */
	const MAX_BODY = 262144;

	/**
	 * Most fields in one submission.
	 */
	const MAX_FIELDS = 500;

	/**
	 * The REST request early() already counted, if any.
	 *
	 * @var \WP_REST_Request|null
	 */
	private static $early_request = null;

	/**
	 * Steps 1 and 2a: the throttle and the declared body size. For REST, before WordPress parses the body.
	 *
	 * @param int                   $content_length Declared (and actual, if known) body size in bytes.
	 * @param \WP_REST_Request|null $request        The REST request, so that check() does not count it again.
	 * @return Submission_Result|null
	 */
	public static function early( $content_length, $request = null ) {
		self::$early_request = null;

		if ( ! Throttle::allow( Client_Ip::client_key() ) ) {
			return Submission_Result::make( Submission_Result::RATE_LIMITED );
		}
		if ( (int) $content_length > self::MAX_BODY ) {
			return Submission_Result::make( Submission_Result::BLOCKED );
		}

		self::$early_request = $request;
		return null;
	}

	/**
	 * The whole pre-step.
	 *
	 * @param mixed                 $form_id        Posted effective formId.
	 * @param mixed                 $source         Posted source reference.
	 * @param int                   $field_count    Number of submitted fields (0 for routes with none).
	 * @param int                   $content_length Declared body size; ignored when $request went through early().
	 * @param \WP_REST_Request|null $request        The REST request, when early() ran for it.
	 * @return Submission_Result|null Null to continue.
	 */
	public static function check( $form_id, $source, $field_count = 0, $content_length = 0, $request = null ) {
		$counted             = null !== $request && $request === self::$early_request;
		self::$early_request = null;

		if ( ! $counted ) {
			$refused = self::early( $content_length );
			if ( null !== $refused ) {
				return $refused;
			}
		}

		if ( (int) $field_count > self::MAX_FIELDS ) {
			return Submission_Result::make( Submission_Result::BLOCKED );
		}

		if ( ! is_string( $form_id ) || ! is_string( $source ) || ! Form_Index::is_live( $form_id, $source ) ) {
			return Submission_Result::make( Submission_Result::CLOSED );
		}

		return null;
	}

	/**
	 * Number of values in a posted `sf` array, nested lists included.
	 *
	 * @param mixed $sf The posted `sf` value.
	 * @return int
	 */
	public static function count_fields( $sf ) {
		if ( ! is_array( $sf ) ) {
			return 0;
		}
		$count = 0;
		array_walk_recursive(
			$sf,
			static function () use ( &$count ) {
				++$count;
			}
		);
		return max( $count, count( $sf ) );
	}
}
