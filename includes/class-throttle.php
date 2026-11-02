<?php
/**
 * Rate limiters and the duplicate guard.
 *
 * @package SwiftForms
 */

namespace SwiftForms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Throttle
 *
 * Three small counters, all keyed by the client key (Client_Ip), never by an address:
 *
 * - allow():      the coarse limiter. A fixed one-minute window per client across all public
 *                 routes (60 requests by default), checked before any parsing (C-9.1, ADR-002).
 * - allow_form(): the per-form limiter. A fixed one-minute window per client and form
 *                 (spam.rate_limit_per_minute), counted only after the schema resolved.
 * - claim() / release(): the duplicate guard. The same payload for the same form from the same
 *                 client within 10 seconds is one submission. It never stores or returns a result,
 *                 so it cannot hand one client another client's answer.
 *
 * Storage is bounded and never autoloaded: with a persistent object cache the counters live there
 * (atomic increments); otherwise they are transients, which WordPress stores as non-autoloaded
 * options that expire after two windows and are removed by core's expired-transient cleanup. A
 * transient counter is read and written without a lock, so concurrent requests can undercount a
 * little; a limiter is a brake, not an exact meter.
 */
class Throttle {

	/**
	 * Default coarse limit: requests per client and minute.
	 */
	const DEFAULT_LIMIT = 60;

	/**
	 * Window length in seconds.
	 */
	const WINDOW = 60;

	/**
	 * Seconds the duplicate guard remembers a payload.
	 */
	const DUPLICATE_SECONDS = 10;

	/**
	 * Object cache group of the counters.
	 */
	const CACHE_GROUP = 'swiftforms_throttle';

	/**
	 * The coarse limit.
	 *
	 * @return int
	 */
	public static function limit() {
		/**
		 * Filters how many requests one client may make to SwiftForms' public routes per minute.
		 *
		 * The coarse limiter runs before any other work on every public route (submit, token), counts
		 * per client key (a salted hash of the address, IPv6 by its /64 prefix) and answers 429
		 * rate_limited when the count passes this number. Raise it for sites where many visitors share
		 * one address (a school, an office, a proxy without swiftforms_client_ip), lower it under attack.
		 * Values below 1 are ignored.
		 *
		 * @since 1.0.0
		 *
		 * @param int $limit Requests per client key per minute. Default 60.
		 */
		$limit = apply_filters( 'swiftforms_throttle_limit', self::DEFAULT_LIMIT );
		return ( is_numeric( $limit ) && (int) $limit >= 1 ) ? (int) $limit : self::DEFAULT_LIMIT;
	}

	/**
	 * Count one request against the coarse limiter.
	 *
	 * @param string $client_key Client key.
	 * @return bool True while the client is within the limit, false once it is over.
	 */
	public static function allow( $client_key ) {
		return self::bump( 'c', (string) $client_key ) <= self::limit();
	}

	/**
	 * Count one submission against the per-form limiter.
	 *
	 * @param string $form_id    Effective formId.
	 * @param string $client_key Client key.
	 * @param int    $limit      Submissions per client and minute (spam.rate_limit_per_minute). 0 switches the limiter off.
	 * @return bool True while the client is within the limit.
	 */
	public static function allow_form( $form_id, $client_key, $limit ) {
		$limit = (int) $limit;
		if ( $limit <= 0 ) {
			return true;
		}
		return self::bump( 'f', md5( (string) $form_id ) . '_' . $client_key ) <= $limit;
	}

	/**
	 * Claim a payload for the duplicate guard.
	 *
	 * @param string $form_id    Effective formId.
	 * @param string $client_key Client key.
	 * @param string $payload    A hash of the submitted values.
	 * @return bool True when the payload is new (now claimed), false when the same client sent the same payload in the last 10 seconds.
	 */
	public static function claim( $form_id, $client_key, $payload ) {
		$name = self::duplicate_name( $form_id, $client_key, $payload );
		$seen = self::read( $name );
		if ( $seen > 0 && Clock::now() - $seen < self::DUPLICATE_SECONDS ) {
			return false;
		}
		self::write( $name, Clock::now(), self::DUPLICATE_SECONDS * 2 );
		return true;
	}

	/**
	 * Forget a claim (the submission turned out invalid, so the visitor may send it again).
	 *
	 * @param string $form_id    Effective formId.
	 * @param string $client_key Client key.
	 * @param string $payload    A hash of the submitted values.
	 * @return void
	 */
	public static function release( $form_id, $client_key, $payload ) {
		$name = self::duplicate_name( $form_id, $client_key, $payload );
		if ( wp_using_ext_object_cache() ) {
			wp_cache_delete( $name, self::CACHE_GROUP );
		} else {
			delete_transient( $name );
		}
	}

	/**
	 * Add one to the counter of the current window.
	 *
	 * @param string $kind Counter kind (c or f).
	 * @param string $key  Counter key.
	 * @return int The new count.
	 */
	private static function bump( $kind, $key ) {
		$name = 'swiftforms_' . $kind . '_' . (int) floor( Clock::now() / self::WINDOW ) . '_' . $key;

		if ( wp_using_ext_object_cache() ) {
			wp_cache_add( $name, 0, self::CACHE_GROUP, self::WINDOW * 2 );
			$count = wp_cache_incr( $name, 1, self::CACHE_GROUP );
			return false === $count ? 1 : (int) $count;
		}

		$count = self::read( $name ) + 1;
		self::write( $name, $count, self::WINDOW * 2 );
		return $count;
	}

	/**
	 * Name of a duplicate-guard entry.
	 *
	 * @param string $form_id    Effective formId.
	 * @param string $client_key Client key.
	 * @param string $payload    Payload hash.
	 * @return string
	 */
	private static function duplicate_name( $form_id, $client_key, $payload ) {
		return 'swiftforms_d_' . md5( $form_id . '|' . $client_key . '|' . $payload );
	}

	/**
	 * Read a number.
	 *
	 * @param string $name Name.
	 * @return int
	 */
	private static function read( $name ) {
		$value = wp_using_ext_object_cache() ? wp_cache_get( $name, self::CACHE_GROUP ) : get_transient( $name );
		return is_numeric( $value ) ? (int) $value : 0;
	}

	/**
	 * Write a number with an expiry.
	 *
	 * @param string $name    Name.
	 * @param int    $value   Value.
	 * @param int    $seconds Seconds until it expires.
	 * @return void
	 */
	private static function write( $name, $value, $seconds ) {
		if ( wp_using_ext_object_cache() ) {
			wp_cache_set( $name, $value, self::CACHE_GROUP, $seconds );
		} else {
			set_transient( $name, $value, $seconds );
		}
	}
}
