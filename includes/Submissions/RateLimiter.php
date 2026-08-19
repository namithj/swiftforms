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
 * A transient-based sliding-ish window: counts submissions per hashed IP
 * within a configurable window, reset when the window expires.
 */
final class RateLimiter {

	/**
	 * Whether the current client has exceeded the allowed submission rate.
	 * Increments the counter as a side effect when not limited.
	 */
	public function is_limited( int $form_id ): bool {
		$max    = max( 1, (int) apply_filters( 'swf_rate_limit_max_requests', GlobalSettings::instance()->get( 'rateLimitMaxRequests', 5 ) ) );
		$window = max( 1, (int) apply_filters( 'swf_rate_limit_window_seconds', GlobalSettings::instance()->get( 'rateLimitWindowSeconds', 60 ) ) );
		$key    = 'swf_rl_' . md5( $form_id . '|' . $this->client_ip() );

		$count = (int) get_transient( $key );

		if ( $count >= $max ) {
			return true;
		}

		if ( 0 === $count ) {
			set_transient( $key, 1, $window );
		} else {
			set_transient( $key, $count + 1, $window );
		}

		return false;
	}

	/**
	 * The requesting client's IP address, filterable for hosts behind a
	 * proxy/load balancer that need to trust a forwarded-for header.
	 */
	private function client_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		return (string) apply_filters( 'swf_client_ip', $ip );
	}
}
