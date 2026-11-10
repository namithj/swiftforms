<?php
/**
 * Tests for SwiftForms\Render_Token.
 *
 * @package SwiftForms
 */

require_once dirname( __DIR__ ) . '/includes/class-sf-storage-test-case.php';

use SwiftForms\Clock;
use SwiftForms\Render_Token;

/**
 * Class RenderTokenTest
 *
 * @group submission
 * @group security
 */
class RenderTokenTest extends SF_Storage_Test_Case {

	const FORM   = '11111111-1111-4111-8111-111111111111';
	const SOURCE = 'post:42';
	const HASH   = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
	const OLD    = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

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
	 * The format is exactly C-3.7.
	 *
	 * @return void
	 */
	public function test_format_is_c_3_7() {
		$token = Render_Token::issue( self::FORM, self::SOURCE, self::HASH );
		$this->assertMatchesRegularExpression( '/^v1\.[0-9a-z]+\.[0-9a-f]{32}$/', $token );

		list( , $issued, $mac ) = explode( '.', $token );
		$this->assertSame( intdiv( 1900000000, 3600 ), (int) base_convert( $issued, 36, 10 ), 'Hours since the epoch in base 36.' );

		$expected = substr( hash_hmac( 'sha256', 'v1|' . self::FORM . '|' . self::SOURCE . '|' . self::HASH . '|' . $issued, wp_salt( 'auth' ) . 'swiftforms' ), 0, 32 );
		$this->assertSame( $expected, $mac );
	}

	/**
	 * A fresh token verifies against its own hash.
	 *
	 * @return void
	 */
	public function test_valid() {
		$token  = Render_Token::issue( self::FORM, self::SOURCE, self::HASH );
		$result = Render_Token::verify( $token, self::FORM, self::SOURCE, [ self::HASH ] );
		$this->assertSame(
			[
				'state'       => 'valid',
				'schema_hash' => self::HASH,
			],
			$result
		);
	}

	/**
	 * A token for an older snapshot verifies and names that snapshot; the current hash is tried first.
	 *
	 * @return void
	 */
	public function test_older_hash() {
		$token  = Render_Token::issue( self::FORM, self::SOURCE, self::OLD );
		$result = Render_Token::verify( $token, self::FORM, self::SOURCE, [ self::HASH, self::OLD ] );
		$this->assertSame( 'valid', $result['state'] );
		$this->assertSame( self::OLD, $result['schema_hash'] );

		$this->assertSame( 'forged', Render_Token::verify( $token, self::FORM, self::SOURCE, [ self::HASH ] )['state'], 'A hash that is not a candidate matches nothing.' );
	}

	/**
	 * Valid for 168 whole hours, then expired (but still genuine).
	 *
	 * @return void
	 */
	public function test_expiry() {
		$token = Render_Token::issue( self::FORM, self::SOURCE, self::HASH );

		Clock::freeze( 1900000000 + 7 * DAY_IN_SECONDS - 1 );
		$this->assertSame( 'valid', Render_Token::verify( $token, self::FORM, self::SOURCE, [ self::HASH ] )['state'] );

		$issued_hour_start = intdiv( 1900000000, 3600 ) * 3600;
		Clock::freeze( $issued_hour_start + 168 * 3600 + 3599 );
		$this->assertSame( 'valid', Render_Token::verify( $token, self::FORM, self::SOURCE, [ self::HASH ] )['state'], '168 hours later, still inside.' );

		Clock::freeze( $issued_hour_start + 169 * 3600 );
		$result = Render_Token::verify( $token, self::FORM, self::SOURCE, [ self::HASH ] );
		$this->assertSame( 'expired', $result['state'] );
		$this->assertSame( self::HASH, $result['schema_hash'], 'An expired genuine token still names its schema (the no-JS re-render needs it).' );
	}

	/**
	 * Anything that does not match is forged.
	 *
	 * @return void
	 */
	public function test_forged() {
		$token                    = Render_Token::issue( self::FORM, self::SOURCE, self::HASH );
		list( $v, $issued, $mac ) = explode( '.', $token );

		$flipped = $v . '.' . $issued . '.' . ( 'a' === $mac[0] ? 'b' : 'a' ) . substr( $mac, 1 );
		$cases   = [
			'wrong mac'    => [ $flipped, self::FORM, self::SOURCE ],
			'other form'   => [ $token, '22222222-2222-4222-8222-222222222222', self::SOURCE ],
			'other source' => [ $token, self::FORM, 'post:43' ],
			'other issued' => [ $v . '.' . base_convert( (string) ( intdiv( 1900000000, 3600 ) - 1 ), 10, 36 ) . '.' . $mac, self::FORM, self::SOURCE ],
		];
		foreach ( $cases as $label => $case ) {
			$result = Render_Token::verify( $case[0], $case[1], $case[2], [ self::HASH ] );
			$this->assertSame( 'forged', $result['state'], $label );
			$this->assertSame( '', $result['schema_hash'], $label );
		}
	}

	/**
	 * A genuine MAC with an issue time in the future is not something we issued.
	 *
	 * @return void
	 */
	public function test_future_token_is_forged() {
		Clock::freeze( 1900000000 + 30 * DAY_IN_SECONDS );
		$token = Render_Token::issue( self::FORM, self::SOURCE, self::HASH );
		Clock::freeze( 1900000000 );
		$this->assertSame( 'forged', Render_Token::verify( $token, self::FORM, self::SOURCE, [ self::HASH ] )['state'] );
	}

	/**
	 * Not-a-token inputs are malformed.
	 *
	 * @return void
	 */
	public function test_malformed() {
		$good = Render_Token::issue( self::FORM, self::SOURCE, self::HASH );
		foreach ( [ '', 'v1', 'v1.abc', 'v1..' . str_repeat( 'a', 32 ), 'v1.abc.' . str_repeat( 'A', 32 ), 'v1.abc.' . str_repeat( 'a', 31 ), 'v1.abc.' . str_repeat( 'a', 33 ), 'v2.abc.' . str_repeat( 'a', 32 ), 'V1.abc.' . str_repeat( 'a', 32 ), $good . "\n", ' ' . $good, str_repeat( 'x', 500 ), null, false, 5, [ $good ] ] as $bad ) {
			$this->assertSame( 'malformed', Render_Token::verify( $bad, self::FORM, self::SOURCE, [ self::HASH ] )['state'], wp_json_encode( $bad ) );
		}
	}

	/**
	 * Candidates are bounded.
	 *
	 * @return void
	 */
	public function test_candidates_are_bounded() {
		$many = [];
		for ( $i = 0; $i < 40; $i++ ) {
			$many[] = hash( 'sha256', (string) $i );
		}
		$token = Render_Token::issue( self::FORM, self::SOURCE, $many[30] );
		$this->assertSame( 'forged', Render_Token::verify( $token, self::FORM, self::SOURCE, $many )['state'], 'Only the first 26 are tried.' );
		$token = Render_Token::issue( self::FORM, self::SOURCE, $many[25] );
		$this->assertSame( 'valid', Render_Token::verify( $token, self::FORM, self::SOURCE, $many )['state'] );
	}

	/**
	 * v1 stays accepted whatever the current format becomes (C-3.7), and the comparison is constant-time.
	 *
	 * @return void
	 */
	public function test_formats_map_and_constant_time_compare() {
		$this->assertArrayHasKey( 'v1', Render_Token::FORMATS );
		$this->assertArrayHasKey( Render_Token::CURRENT, Render_Token::FORMATS );

		$source = (string) file_get_contents( dirname( __DIR__, 3 ) . '/includes/class-render-token.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$this->assertStringContainsString( 'hash_equals(', $source );
		$this->assertDoesNotMatchRegularExpression( '/\$m\[3\]\s*[!=]==?\s|[!=]==?\s*\$m\[3\]/', $source, 'The MAC is never compared with == or ===.' );
	}
}
