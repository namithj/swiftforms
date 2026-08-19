<?php
/**
 * Per-IP submission rate limiting.
 *
 * @package SwiftForms
 */

declare(strict_types=1);

namespace SwiftForms\Submissions;

use SwiftForms\Settings\GlobalSettings;

/**
 * Counts submissions per hashed client identifier within a configurable
 * window. Persistent object caches use their atomic increment operation;
 * transient storage is retained as the compatibility fallback.
 */
final class RateLimiter {

	/**
	 * Cache group for counters when a persistent object cache is available.
	 */
	private const CACHE_GROUP = 'swiftforms';


	/**
	 * Whether the current client has exceeded the allowed submission rate.
	 * Increments the counter as a side effect when not limited.
	 */
	public function is_limited( int $form_id ): bool {
		$max    = max( 1, (int) apply_filters( 'swf_rate_limit_max_requests', GlobalSettings::instance()->get( 'rateLimitMaxRequests', 5 ) ) );
		$window = max( 1, (int) apply_filters( 'swf_rate_limit_window_seconds', GlobalSettings::instance()->get( 'rateLimitWindowSeconds', 60 ) ) );
		$client = $this->client_ip();

		// Without an identifier, every visitor would otherwise share one bucket.
		// Hosts behind proxies can supply a trusted identifier with swf_client_ip.
		if ( '' === $client ) {
			return false;
		}

		$key = 'swf_rl_' . md5( $form_id . '|' . $client );

		if ( wp_using_ext_object_cache() ) {
			$count = $this->increment_cached( $key, $window );

			if ( false !== $count ) {
				return $count > $max;
			}
		}

		return $this->increment_transient( $key, $window ) > $max;
	}

	/**
	 * Atomically creates or increments a persistent cache counter.
	 *
	 * @return int|false The incremented counter, or false when the cache fails.
	 */
	private function increment_cached( string $key, int $window ) {
		if ( wp_cache_add( $key, 1, self::CACHE_GROUP, $window ) ) {
			return 1;
		}

		$count = wp_cache_incr( $key, 1, self::CACHE_GROUP );

		if ( false !== $count ) {
			return (int) $count;
		}

		// The key may have expired between add() and incr(). Retry add once.
		return wp_cache_add( $key, 1, self::CACHE_GROUP, $window ) ? 1 : false;
	}

	/**
	 * Increments the transient fallback counter.
	 */
	private function increment_transient( string $key, int $window ): int {

		$count = (int) get_transient( $key );

		if ( 0 === $count ) {
			set_transient( $key, 1, $window );

			return 1;
		}

		set_transient( $key, $count + 1, $window );

		return $count + 1;
	}
	/**
	 * The requesting client's IP address, filterable for hosts behind a
	 * proxy/load balancer that need to trust a forwarded-for header.
	 */
	private function client_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		return trim( (string) apply_filters( 'swf_client_ip', $ip ) );
	}
}
