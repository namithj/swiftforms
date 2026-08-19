<?php
/**
 * Tests for SwiftForms\Submissions\TimeTrap.
 *
 * @package SwiftForms
 */

declare(strict_types=1);

namespace SwiftForms\Tests\Submissions;

use SwiftForms\Submissions\TimeTrap;
use SwiftForms\Tests\TestCase;

final class TimeTrapTest extends TestCase {

	public function test_a_token_that_is_old_enough_passes(): void {
		$issued_at = time() - 10;
		$signature = hash_hmac( 'sha256', "rendered_at|{$issued_at}", wp_salt( 'auth' ) );
		$token     = "{$issued_at}.{$signature}";

		$this->assertTrue( TimeTrap::verify( $token, 3 ) );
	}

	public function test_a_token_that_is_too_fresh_fails(): void {
		$token = TimeTrap::build();

		$this->assertFalse( TimeTrap::verify( $token, 3 ) );
	}

	public function test_a_forged_token_fails_open_since_it_only_tightens_defenses(): void {
		$issued_at = time() - 10;
		$token     = "{$issued_at}.not-the-real-signature";

		$this->assertTrue( TimeTrap::verify( $token, 3 ) );
	}

	public function test_a_missing_token_passes(): void {
		$this->assertTrue( TimeTrap::verify( '', 3 ) );
	}
}
