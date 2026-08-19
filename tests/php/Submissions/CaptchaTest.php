<?php
/**
 * CAPTCHA lifecycle tests.
 *
 * @package SwiftForms
 */

declare(strict_types=1);

namespace SwiftForms\Tests\Submissions;

use SwiftForms\Submissions\Captcha;
use SwiftForms\Tests\TestCase;

final class CaptchaTest extends TestCase {

	public function test_valid_challenge_is_single_use(): void {
		$challenge = Captcha::build();
		$answer    = $challenge['a'] + $challenge['b'];

		$this->assertTrue( Captcha::verify( $challenge['token'], $answer ) );
		$this->assertFalse( Captcha::verify( $challenge['token'], $answer ) );
	}
}
