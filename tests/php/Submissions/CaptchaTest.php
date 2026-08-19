<?php
/**
 * Tests for SwiftForms\Submissions\Captcha.
 *
 * @package SwiftForms
 */

declare(strict_types=1);

namespace SwiftForms\Tests\Submissions;

use SwiftForms\Submissions\Captcha;
use SwiftForms\Tests\TestCase;

final class CaptchaTest extends TestCase {

	public function test_build_returns_two_numbers_and_a_token(): void {
		$challenge = Captcha::build();

		$this->assertIsInt( $challenge['a'] );
		$this->assertIsInt( $challenge['b'] );
		$this->assertStringContainsString( '.', $challenge['token'] );
	}

	public function test_correct_answer_verifies(): void {
		$challenge = Captcha::build();

		$this->assertTrue( Captcha::verify( $challenge['token'], $challenge['a'] + $challenge['b'] ) );
	}

	public function test_wrong_answer_fails(): void {
		$challenge = Captcha::build();

		$this->assertFalse( Captcha::verify( $challenge['token'], $challenge['a'] + $challenge['b'] + 1 ) );
	}

	public function test_tampered_token_fails(): void {
		$challenge = Captcha::build();
		$answer    = $challenge['a'] + $challenge['b'];

		$this->assertFalse( Captcha::verify( $challenge['token'] . 'x', $answer ) );
	}

	public function test_expired_token_fails(): void {
		$issued_at = time() - 1801; // Just past the 1800s TTL.
		$signature = hash_hmac( 'sha256', '10|' . $issued_at, wp_salt( 'auth' ) );
		$token     = "{$issued_at}.{$signature}";

		$this->assertFalse( Captcha::verify( $token, 10 ) );
	}

	public function test_non_numeric_answer_fails(): void {
		$challenge = Captcha::build();

		$this->assertFalse( Captcha::verify( $challenge['token'], 'not-a-number' ) );
	}

	public function test_empty_token_fails(): void {
		$this->assertFalse( Captcha::verify( '', 5 ) );
	}
}
