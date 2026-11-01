<?php
/**
 * Time seam.
 *
 * @package SwiftForms
 */

namespace SwiftForms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Clock
 *
 * Every "what time is it" question in SwiftForms goes through here, so tests
 * can freeze time. Production code only ever calls now() and gmt_mysql().
 */
class Clock {

	/**
	 * Frozen UTC timestamp, or null when time runs normally.
	 *
	 * @var int|null
	 */
	private static $frozen = null;

	/**
	 * Current UTC unix timestamp.
	 *
	 * @return int
	 */
	public static function now() {
		return null === self::$frozen ? time() : self::$frozen;
	}

	/**
	 * Current UTC time as a MySQL datetime string.
	 *
	 * @return string
	 */
	public static function gmt_mysql() {
		return gmdate( 'Y-m-d H:i:s', self::now() );
	}

	/**
	 * Freeze time. Tests only: needs SWIFTFORMS_TESTS or SWIFTFORMS_TEST_NOW.
	 *
	 * @param int $timestamp UTC unix timestamp.
	 * @return void
	 */
	public static function freeze( $timestamp ) {
		if ( ! self::testing_allowed() ) {
			_doing_it_wrong( __METHOD__, 'Clock::freeze() is for tests only.', '0.1.0' );
			return;
		}
		self::$frozen = (int) $timestamp;
	}

	/**
	 * Let time run again.
	 *
	 * @return void
	 */
	public static function unfreeze() {
		self::$frozen = null;
	}

	/**
	 * Whether the test-only switches may be used.
	 *
	 * @return bool
	 */
	private static function testing_allowed() {
		return ( defined( 'SWIFTFORMS_TESTS' ) && SWIFTFORMS_TESTS ) || defined( 'SWIFTFORMS_TEST_NOW' );
	}
}
