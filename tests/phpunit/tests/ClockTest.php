<?php
/**
 * Tests for SwiftForms\Clock.
 *
 * @package SwiftForms
 */

use SwiftForms\Clock;

/**
 * Class ClockTest
 */
class ClockTest extends WP_UnitTestCase {

	/**
	 * Always let time run again.
	 *
	 * @return void
	 */
	public function tear_down() {
		Clock::unfreeze();
		parent::tear_down();
	}

	/**
	 * Time runs by default.
	 *
	 * @return void
	 */
	public function test_now_follows_real_time() {
		$before = time();
		$now    = Clock::now();
		$this->assertGreaterThanOrEqual( $before, $now );
		$this->assertLessThanOrEqual( time(), $now );
	}

	/**
	 * A frozen clock stays put and formats as UTC.
	 *
	 * @return void
	 */
	public function test_freeze_and_unfreeze() {
		Clock::freeze( 1795082400 );
		$this->assertSame( 1795082400, Clock::now() );
		$this->assertSame( '2026-11-19 10:00:00', Clock::gmt_mysql() );

		Clock::unfreeze();
		$this->assertNotSame( 1795082400, Clock::now() );
	}
}
