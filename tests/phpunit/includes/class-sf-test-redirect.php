<?php
/**
 * Exception that stands in for a redirect in the submission tests.
 *
 * @package SwiftForms
 */

/**
 * Thrown by the wp_redirect filter so a test can read the redirect instead of exiting.
 */
class SF_Test_Redirect extends RuntimeException {

	/**
	 * Redirect target.
	 *
	 * @var string
	 */
	public $location = '';

	/**
	 * HTTP status.
	 *
	 * @var int
	 */
	public $status = 0;
}
