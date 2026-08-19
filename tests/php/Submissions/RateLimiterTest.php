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
		add_filter( 'smartlogix_swiftforms_rate_limit_max_requests', fn() => 2 );

		$limiter = new RateLimiter();

		$this->assertFalse( $limiter->is_limited( 1 ) );
		$this->assertFalse( $limiter->is_limited( 1 ) );
		$this->assertTrue( $limiter->is_limited( 1 ) );
	}

	public function test_client_ip_filter_can_override_the_bucketing_key(): void {
		add_filter( 'smartlogix_swiftforms_rate_limit_max_requests', fn() => 1 );
		$client = 'first-proxy-client';
		add_filter(
			'smartlogix_swiftforms_client_ip',
			static function () use ( &$client ): string {
				return $client;
			}
		);

		$limiter = new RateLimiter();

		$this->assertFalse( $limiter->is_limited( 1 ) );
		$this->assertTrue( $limiter->is_limited( 1 ) );

		$client = 'second-proxy-client';

		$this->assertFalse( $limiter->is_limited( 1 ) );
	}

	public function test_each_form_has_an_independent_counter(): void {
		add_filter( 'smartlogix_swiftforms_rate_limit_max_requests', fn() => 1 );
		add_filter( 'smartlogix_swiftforms_client_ip', fn() => 'shared-client' );

		$limiter = new RateLimiter();

		$this->assertFalse( $limiter->is_limited( 101 ) );
		$this->assertFalse( $limiter->is_limited( 102 ) );
		$this->assertTrue( $limiter->is_limited( 101 ) );
	}

	public function test_empty_client_identifier_does_not_create_a_shared_bucket(): void {
		add_filter( 'smartlogix_swiftforms_rate_limit_max_requests', fn() => 1 );
		add_filter( 'smartlogix_swiftforms_client_ip', fn() => '' );

		$limiter = new RateLimiter();

		$this->assertFalse( $limiter->is_limited( 1 ) );
		$this->assertFalse( $limiter->is_limited( 1 ) );
	}
}
