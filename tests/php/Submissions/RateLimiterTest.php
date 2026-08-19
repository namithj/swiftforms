<?php
/**
 * Tests for SwiftForms\Submissions\RateLimiter.
 *
 * @package SwiftForms
 */

declare(strict_types=1);

namespace SwiftForms\Tests\Submissions;

use SwiftForms\Submissions\RateLimiter;
use SwiftForms\Tests\TestCase;

final class RateLimiterTest extends TestCase {

	public function test_allows_requests_up_to_the_configured_maximum(): void {
		add_filter( 'swf_rate_limit_max_requests', fn() => 2 );

		$limiter = new RateLimiter();

		$this->assertFalse( $limiter->is_limited() );
		$this->assertFalse( $limiter->is_limited() );
		$this->assertTrue( $limiter->is_limited() );
	}

	public function test_client_ip_filter_can_override_the_bucketing_key(): void {
		add_filter( 'swf_rate_limit_max_requests', fn() => 1 );
		add_filter( 'swf_client_ip', fn() => 'test-fixed-ip' );

		$limiter = new RateLimiter();

		$this->assertFalse( $limiter->is_limited() );
		$this->assertTrue( $limiter->is_limited() );
	}
}
