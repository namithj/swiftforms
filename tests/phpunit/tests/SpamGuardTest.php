<?php
/**
 * Tests for SwiftForms\Spam_Guard.
 *
 * @package SwiftForms
 */

require_once dirname( __DIR__ ) . '/includes/class-sf-storage-test-case.php';

use SwiftForms\Clock;
use SwiftForms\Field_Types;
use SwiftForms\Form_Schema;
use SwiftForms\Render_Token;
use SwiftForms\Settings;
use SwiftForms\Spam_Guard;
use SwiftForms\Submission_Context;
use SwiftForms\Time_Trap;

/**
 * Class SpamGuardTest
 *
 * @group submission
 * @group security
 */
class SpamGuardTest extends SF_Storage_Test_Case {

	const FORM   = '11111111-1111-4111-8111-111111111111';
	const SOURCE = 'post:5';

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
	 * A context.
	 *
	 * @param array  $values    Submitted values.
	 * @param string $protection spamProtection attribute.
	 * @param string $transport  rest or nojs.
	 * @return Submission_Context
	 */
	private function ctx( array $values = [], $protection = 'default', $transport = 'rest' ) {
		$fields = [];
		foreach ( array_keys( $values ) as $name ) {
			$fields[] = array_merge( [ 'type' => 'hidden' === $name ? 'hidden' : 'text' ], Field_Types::defaults( 'text' ), [ 'name' => $name ] );
		}
		$schema = new Form_Schema(
			[
				'form_id'    => self::FORM,
				'source'     => self::SOURCE,
				'attributes' => array_merge( Field_Types::form_defaults(), [ 'spamProtection' => $protection ] ),
				'fields'     => $fields,
			]
		);
		return new Submission_Context(
			$schema,
			null,
			[
				'values'    => $values,
				'transport' => $transport,
			]
		);
	}

	/**
	 * A valid token verdict.
	 *
	 * @param string $state Token state.
	 * @return array
	 */
	private function token( $state = 'valid' ) {
		return [
			'state'       => $state,
			'schema_hash' => 'x',
		];
	}

	/**
	 * Controls of a clean REST post.
	 *
	 * @param array $override Overrides.
	 * @return array
	 */
	private function clean( array $override = [] ) {
		return array_merge(
			[
				'hp' => '',
				't'  => 'js.5000',
			],
			$override
		);
	}

	/**
	 * A clean submission passes.
	 *
	 * @return void
	 */
	public function test_clean() {
		$this->assertSame(
			[
				'spam'   => false,
				'reason' => '',
			],
			Spam_Guard::check( $this->ctx( [ 'a' => 'hello' ] ), $this->clean(), $this->token() )
		);
	}

	/**
	 * The honeypot.
	 *
	 * @return void
	 */
	public function test_honeypot() {
		$verdict = Spam_Guard::check( $this->ctx(), $this->clean( [ 'hp' => 'http://spam' ] ), $this->token() );
		$this->assertSame(
			[
				'spam'   => true,
				'reason' => 'honeypot',
			],
			$verdict
		);
		$this->assertTrue( Spam_Guard::check( $this->ctx(), $this->clean( [ 'hp' => '0' ] ), $this->token() )['spam'], 'Even "0" is something.' );
		$this->assertTrue( Spam_Guard::check( $this->ctx(), $this->clean( [ 'hp' => [ 'x' ] ] ), $this->token() )['spam'] );

		$this->set_settings( [ 'spam' => [ 'honeypot' => false ] ] );
		$this->assertFalse( Spam_Guard::check( $this->ctx(), $this->clean( [ 'hp' => 'filled' ] ), $this->token() )['spam'], 'Switched off in settings.' );
	}

	/**
	 * The time trap on REST (browser-measured time).
	 *
	 * @return void
	 */
	public function test_time_trap_rest() {
		$this->assertSame( 'too_fast', Spam_Guard::check( $this->ctx(), $this->clean( [ 't' => 'js.2999' ] ), $this->token() )['reason'] );
		$this->assertFalse( Spam_Guard::check( $this->ctx(), $this->clean( [ 't' => 'js.3000' ] ), $this->token() )['spam'] );
		$this->assertSame( 'time_missing', Spam_Guard::check( $this->ctx(), $this->clean( [ 't' => null ] ), $this->token() )['reason'] );
		$this->assertSame( 'time_missing', Spam_Guard::check( $this->ctx(), $this->clean( [ 't' => 'garbage' ] ), $this->token() )['reason'] );
		$this->assertSame( 'time_forged', Spam_Guard::check( $this->ctx(), $this->clean( [ 't' => '1800000000.' . str_repeat( 'a', 16 ) ] ), $this->token() )['reason'] );

		$this->set_settings( [ 'spam' => [ 'min_seconds' => 0 ] ] );
		$this->assertFalse( Spam_Guard::check( $this->ctx(), $this->clean( [ 't' => 'js.0' ] ), $this->token() )['spam'], 'Zero switches the trap off.' );
		$this->set_settings( [ 'spam' => [ 'min_seconds' => 10 ] ] );
		$this->assertTrue( Spam_Guard::check( $this->ctx(), $this->clean( [ 't' => 'js.9999' ] ), $this->token() )['spam'] );
		$this->assertFalse( Spam_Guard::check( $this->ctx(), $this->clean( [ 't' => 'js.10000' ] ), $this->token() )['spam'] );
	}

	/**
	 * The time trap without JavaScript: the signed render time.
	 *
	 * @return void
	 */
	public function test_time_trap_nojs() {
		$signed = Time_Trap::issue( self::FORM, self::SOURCE );

		Clock::freeze( 1900000002 );
		$this->assertSame( 'too_fast', Spam_Guard::check( $this->ctx( [], 'default', 'nojs' ), $this->clean( [ 't' => $signed ] ), $this->token() )['reason'] );

		Clock::freeze( 1900000003 );
		$this->assertFalse( Spam_Guard::check( $this->ctx( [], 'default', 'nojs' ), $this->clean( [ 't' => $signed ] ), $this->token() )['spam'] );

		// A script cannot claim a browser-measured time on a form post.
		$this->assertSame( 'time_forged', Spam_Guard::check( $this->ctx( [], 'default', 'nojs' ), $this->clean( [ 't' => 'js.99999' ] ), $this->token() )['reason'] );

		// A forged signature is spam.
		$this->assertSame( 'time_forged', Spam_Guard::check( $this->ctx( [], 'default', 'nojs' ), $this->clean( [ 't' => '1800000000.' . str_repeat( '0', 16 ) ] ), $this->token() )['reason'] );
	}

	/**
	 * A forged or malformed token is spam, even with protection off.
	 *
	 * @return void
	 */
	public function test_forged_token() {
		foreach ( [ 'forged', 'malformed' ] as $state ) {
			$this->assertSame(
				[
					'spam'   => true,
					'reason' => 'token_forged',
				],
				Spam_Guard::check( $this->ctx(), $this->clean(), $this->token( $state ) )
			);
			$this->assertSame(
				[
					'spam'   => true,
					'reason' => 'token_forged',
				],
				Spam_Guard::check( $this->ctx( [], 'off' ), $this->clean(), $this->token( $state ) ),
				'Protection off does not excuse a forged token.'
			);
		}
		$this->assertFalse( Spam_Guard::check( $this->ctx(), $this->clean(), $this->token( 'valid' ) )['spam'] );
		$this->assertFalse( Spam_Guard::check( $this->ctx(), $this->clean(), $this->token( 'expired' ) )['spam'], 'Expired is not forged.' );
		$this->assertTrue( Spam_Guard::check( $this->ctx(), $this->clean(), [] )['spam'], 'A missing verdict counts as malformed.' );
	}

	/**
	 * The JS token: REST must carry the browser-measured time form.
	 *
	 * @return void
	 */
	public function test_js_token() {
		$signed = Time_Trap::issue( self::FORM, self::SOURCE );
		Clock::freeze( 1900000100 );

		$this->assertSame( 'js_token', Spam_Guard::check( $this->ctx(), $this->clean( [ 't' => $signed ] ), $this->token() )['reason'], 'REST with the signed form (no JS ran).' );

		$this->set_settings( [ 'spam' => [ 'js_token' => false ] ] );
		$this->assertFalse( Spam_Guard::check( $this->ctx(), $this->clean( [ 't' => $signed ] ), $this->token() )['spam'], 'Rule switched off.' );

		$this->set_settings( [ 'spam' => [ 'js_token' => true ] ] );
		$this->assertFalse( Spam_Guard::check( $this->ctx( [], 'default', 'nojs' ), $this->clean( [ 't' => $signed ] ), $this->token() )['spam'], 'The no-JS path is exempt.' );
	}

	/**
	 * Blocked words are literal, case-insensitive substrings over every visitor value.
	 *
	 * @return void
	 */
	public function test_blocked_words() {
		$this->set_settings( [ 'spam' => [ 'blocked_words' => "Viagra\ncasino, cheap.*pills\n  \n" ] ] );

		$this->assertSame( 'blocked_word', Spam_Guard::check( $this->ctx( [ 'm' => 'Buy VIAGRA now' ] ), $this->clean(), $this->token() )['reason'] );
		$this->assertTrue( Spam_Guard::check( $this->ctx( [ 'm' => 'Online Casino' ] ), $this->clean(), $this->token() )['spam'], 'Comma separated.' );
		$this->assertTrue(
			Spam_Guard::check(
				$this->ctx(
					[
						'a' => 'fine',
						'b' => [ 'ok', 'a casino here' ],
					]
				),
				$this->clean(),
				$this->token()
			)['spam'],
			'Values in a list are checked.'
		);
		$this->assertFalse( Spam_Guard::check( $this->ctx( [ 'm' => 'a perfectly fine message' ] ), $this->clean(), $this->token() )['spam'] );

		// Literal: regex syntax in the list is just text.
		$this->assertTrue( Spam_Guard::check( $this->ctx( [ 'm' => 'cheap.*pills' ] ), $this->clean(), $this->token() )['spam'] );
		$this->assertFalse( Spam_Guard::check( $this->ctx( [ 'm' => 'cheap blue pills' ] ), $this->clean(), $this->token() )['spam'], 'Not a regular expression.' );

		// Unicode case folding.
		$this->set_settings( [ 'spam' => [ 'blocked_words' => 'ÉCOLE' ] ] );
		$this->assertTrue( Spam_Guard::check( $this->ctx( [ 'm' => 'mon école' ] ), $this->clean(), $this->token() )['spam'] );

		// A regex delimiter or an unbalanced bracket in the list cannot break anything.
		$this->set_settings( [ 'spam' => [ 'blocked_words' => "(\n[\n/\n#\\" ] ] );
		$this->assertFalse( Spam_Guard::check( $this->ctx( [ 'm' => 'hello world' ] ), $this->clean(), $this->token() )['spam'] );
		$this->assertTrue( Spam_Guard::check( $this->ctx( [ 'm' => 'a [ b' ] ), $this->clean(), $this->token() )['spam'] );
	}

	/**
	 * Hidden fields (computed by the server) are not visitor values.
	 *
	 * @return void
	 */
	public function test_blocked_words_skip_hidden_fields() {
		$this->set_settings( [ 'spam' => [ 'blocked_words' => 'campaign' ] ] );
		$this->assertFalse( Spam_Guard::check( $this->ctx( [ 'hidden' => 'campaign-1' ] ), $this->clean(), $this->token() )['spam'] );
	}

	/**
	 * The swiftforms_spam_check filter.
	 *
	 * @return void
	 */
	public function test_spam_check_filter() {
		$seen = [];
		add_filter(
			'swiftforms_spam_check',
			static function ( $verdict, $context ) use ( &$seen ) {
				$seen[] = $context;
				return isset( $context->values['flag'] ) ? $context->values['flag'] : $verdict;
			},
			10,
			2
		);

		$this->assertTrue( Spam_Guard::check( $this->ctx( [ 'flag' => 'x' ] ), $this->clean(), $this->token() )['spam'] === true );
		$this->assertSame( 'filter:x', Spam_Guard::check( $this->ctx( [ 'flag' => 'x' ] ), $this->clean(), $this->token() )['reason'] );
		$this->assertSame( 'filter:rbl.hitb', Spam_Guard::check( $this->ctx( [ 'flag' => 'rbl.hit <b>' ] ), $this->clean(), $this->token() )['reason'], 'The reason is sanitised.' );
		$this->assertFalse( Spam_Guard::check( $this->ctx( [ 'flag' => '' ] ), $this->clean(), $this->token() )['spam'], 'An empty string is clean.' );

		$seen = [];
		Spam_Guard::check( $this->ctx(), $this->clean( [ 'hp' => 'x' ] ), $this->token() );
		$this->assertSame( [], $seen, 'Not asked when the submission is already spam.' );
		Spam_Guard::check( $this->ctx( [], 'off' ), $this->clean(), $this->token() );
		$this->assertSame( [], $seen, 'Not asked when protection is off.' );

		remove_all_filters( 'swiftforms_spam_check' );
		add_filter(
			'swiftforms_spam_check',
			static function () {
				return true;
			}
		);
		$this->assertSame( 'filter', Spam_Guard::check( $this->ctx(), $this->clean(), $this->token() )['reason'] );
	}

	/**
	 * Protection "off" skips honeypot, time trap, JS token, words and the filter.
	 *
	 * @return void
	 */
	public function test_protection_off() {
		$this->set_settings( [ 'spam' => [ 'blocked_words' => 'casino' ] ] );
		add_filter( 'swiftforms_spam_check', '__return_true' );

		$verdict = Spam_Guard::check(
			$this->ctx( [ 'm' => 'casino' ], 'off' ),
			[
				'hp' => 'filled',
				't'  => 'js.1',
			],
			$this->token()
		);
		$this->assertSame(
			[
				'spam'   => false,
				'reason' => '',
			],
			$verdict
		);
		$this->assertSame(
			[
				'spam'   => false,
				'reason' => '',
			],
			Spam_Guard::check(
				$this->ctx( [ 'm' => 'casino' ], 'off' ),
				[
					'hp' => '',
					't'  => null,
				],
				$this->token()
			),
			'No time control needed either.'
		);
	}

	/**
	 * Real tokens flow through unchanged (integration of the two classes).
	 *
	 * @return void
	 */
	public function test_with_a_real_token() {
		$token  = Render_Token::issue( self::FORM, self::SOURCE, str_repeat( 'a', 64 ) );
		$result = Render_Token::verify( $token, self::FORM, self::SOURCE, [ str_repeat( 'a', 64 ) ] );
		$this->assertFalse( Spam_Guard::check( $this->ctx(), $this->clean(), $result )['spam'] );
		$this->assertTrue( Settings::get_value( 'spam.js_token' ) );
	}
}
