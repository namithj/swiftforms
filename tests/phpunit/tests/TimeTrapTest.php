<?php
/**
 * Tests for SwiftForms\Time_Trap.
 *
 * @package SwiftForms
 */

require_once dirname( __DIR__ ) . '/includes/class-sf-storage-test-case.php';

use SwiftForms\Clock;
use SwiftForms\Time_Trap;

/**
 * Class TimeTrapTest
 *
 * @group submission
 * @group security
 */
class TimeTrapTest extends SF_Storage_Test_Case {

	const FORM   = '11111111-1111-4111-8111-111111111111';
	const SOURCE = 'post:42';

	/**
	 * Freeze the clock.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		Clock::freeze( 1900000000 );
	}

	/**
	 * The signed format and its elapsed time.
	 *
	 * @return void
	 */
	public function test_signed() {
		$value = Time_Trap::issue( self::FORM, self::SOURCE );
		$this->assertMatchesRegularExpression( '/^1900000000\.[0-9a-f]{16}$/', $value );

		Clock::freeze( 1900000005 );
		$parsed = Time_Trap::parse( $value, self::FORM, self::SOURCE );
		$this->assertSame( 'signed', $parsed['format'] );
		$this->assertSame( 5.0, $parsed['seconds'] );

		// A clock that moved backwards never gives a negative time.
		Clock::freeze( 1899999000 );
		$this->assertSame( 0.0, Time_Trap::parse( $value, self::FORM, self::SOURCE )['seconds'] );
	}

	/**
	 * Any change to the value, form or source breaks the signature.
	 *
	 * @return void
	 */
	public function test_forged() {
		$value              = Time_Trap::issue( self::FORM, self::SOURCE );
		list( $unix, $mac ) = explode( '.', $value );

		$this->assertSame( 'forged', Time_Trap::parse( '1800000000.' . $mac, self::FORM, self::SOURCE )['format'], 'Older time, same MAC.' );
		$this->assertSame( 'forged', Time_Trap::parse( $unix . '.' . str_repeat( '0', 16 ), self::FORM, self::SOURCE )['format'] );
		$this->assertSame( 'forged', Time_Trap::parse( $value, self::FORM, 'post:43' )['format'] );
		$this->assertSame( 'forged', Time_Trap::parse( $value, '22222222-2222-4222-8222-222222222222', self::SOURCE )['format'] );
	}

	/**
	 * The browser-measured format.
	 *
	 * @return void
	 */
	public function test_js() {
		$parsed = Time_Trap::parse( 'js.4500', self::FORM, self::SOURCE );
		$this->assertSame( 'js', $parsed['format'] );
		$this->assertSame( 4.5, $parsed['seconds'] );
		$this->assertSame( 0.0, Time_Trap::parse( 'js.0', self::FORM, self::SOURCE )['seconds'] );
	}

	/**
	 * Everything else is missing.
	 *
	 * @return void
	 */
	public function test_missing() {
		foreach ( [ '', 'js.', 'js.-5', 'js.1.5', 'JS.100', 'js.1e3', 'x', '123', '123.abc', null, false, 7, [ 'js.100' ], str_repeat( '1', 100 ) ] as $bad ) {
			$this->assertSame( 'missing', Time_Trap::parse( $bad, self::FORM, self::SOURCE )['format'], wp_json_encode( $bad ) );
		}
	}
}
