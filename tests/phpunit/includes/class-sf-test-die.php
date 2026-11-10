<?php
/**
 * Exception that stands in for wp_die() in the submission tests.
 *
 * @package SwiftForms
 */

/**
 * Thrown by the wp_die handler so a test can read the answer instead of dying.
 */
class SF_Test_Die extends RuntimeException {

	/**
	 * HTTP status.
	 *
	 * @var int
	 */
	public $status = 0;
}
