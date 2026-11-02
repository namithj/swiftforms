<?php
/**
 * Client address and client key.
 *
 * @package SwiftForms
 */

namespace SwiftForms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Client_Ip
 *
 * Who is asking. The address is REMOTE_ADDR and nothing else: a header such as X-Forwarded-For is
 * client-controlled and would let one visitor hide behind a thousand addresses, or lock another
 * out. A site behind a trusted proxy or CDN opts in through the swiftforms_client_ip filter.
 *
 * The limiters and the duplicate guard never see the address, only the client key: an HMAC of the
 * normalised address (C-9.1). IPv4-mapped IPv6 addresses count as the IPv4 address, and every IPv6
 * address counts as its /64 prefix (one subscriber is handed 2^64 addresses).
 *
 * The last 20 client keys of accepted submissions are kept in the option swiftforms_recent_clients
 * (autoload no) for the system-status warning (R04): when they are all the same, the site is
 * probably behind a proxy nobody configured, and the limiters treat every visitor as one.
 */
class Client_Ip {

	/**
	 * Option holding the recent client keys.
	 */
	const RECENT_OPTION = 'swiftforms_recent_clients';

	/**
	 * How many recent keys are kept.
	 */
	const RECENT_MAX = 20;

	/**
	 * How many recent keys are needed before the warning can fire.
	 */
	const RECENT_MIN_FOR_WARNING = 10;

	/**
	 * The client's address: a valid IP string, or an empty string when there is none.
	 *
	 * @return string
	 */
	public static function address() {
		$remote = '';
		if ( isset( $_SERVER['REMOTE_ADDR'] ) && is_string( $_SERVER['REMOTE_ADDR'] ) ) {
			$remote = trim( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validated with FILTER_VALIDATE_IP below.
		}
		if ( false === filter_var( $remote, FILTER_VALIDATE_IP ) ) {
			$remote = '';
		}

		/**
		 * Filters the address SwiftForms treats as the visitor's.
		 *
		 * By default this is REMOTE_ADDR, the only value a visitor cannot choose. If the site sits
		 * behind a reverse proxy or CDN, REMOTE_ADDR is the proxy and every visitor looks the same,
		 * so the rate limits would hit all of them at once. Return the real visitor address from a
		 * header ONLY when that header is set by infrastructure you control (for example
		 * Cloudflare's CF-Connecting-IP after restoring real IPs, or X-Forwarded-For from your own
		 * load balancer, taking the last hop you trust). Trusting a header any visitor can send lets
		 * them dodge the limits.
		 *
		 * The result is checked with FILTER_VALIDATE_IP; anything else is ignored and REMOTE_ADDR is used.
		 *
		 * @since 1.0.0
		 *
		 * @param string $ip The address from REMOTE_ADDR, or an empty string when it is missing or invalid.
		 */
		$filtered = apply_filters( 'swiftforms_client_ip', $remote );
		if ( is_string( $filtered ) ) {
			$filtered = trim( $filtered );
			if ( false !== filter_var( $filtered, FILTER_VALIDATE_IP ) ) {
				return $filtered;
			}
		}
		return $remote;
	}

	/**
	 * The normalised form of an address, the text the client key is made from.
	 *
	 * IPv4: the dotted address. IPv4-mapped IPv6: the IPv4 address. Other IPv6: its /64 prefix.
	 * An empty or invalid address gives "unknown".
	 *
	 * @param string $ip IP address.
	 * @return string
	 */
	public static function normalise( $ip ) {
		$packed = is_string( $ip ) ? @inet_pton( $ip ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- inet_pton() warns on invalid text.
		if ( false === $packed ) {
			return 'unknown';
		}
		if ( 4 === strlen( $packed ) ) {
			return 'v4:' . inet_ntop( $packed );
		}
		if ( 16 === strlen( $packed ) ) {
			// ::ffff:a.b.c.d is the IPv4 address a.b.c.d.
			if ( "\0\0\0\0\0\0\0\0\0\0\xff\xff" === substr( $packed, 0, 12 ) ) {
				return 'v4:' . inet_ntop( substr( $packed, 12 ) );
			}
			return 'v6:' . bin2hex( substr( $packed, 0, 8 ) );
		}
		return 'unknown';
	}

	/**
	 * The client key of an address: the first 32 hex characters of HMAC-SHA256( normalised address ).
	 *
	 * @param string $ip IP address.
	 * @return string
	 */
	public static function key_for( $ip ) {
		return substr( hash_hmac( 'sha256', self::normalise( $ip ), wp_salt( 'nonce' ) ), 0, 32 );
	}

	/**
	 * The client key of the current request.
	 *
	 * @return string
	 */
	public static function client_key() {
		return self::key_for( self::address() );
	}

	/**
	 * Remember the client key of an accepted submission (the last 20 are kept).
	 *
	 * @param string $key Client key.
	 * @return void
	 */
	public static function record_accepted( $key ) {
		$recent   = self::recent();
		$recent[] = (string) $key;
		$recent   = array_slice( $recent, -self::RECENT_MAX );
		update_option( self::RECENT_OPTION, $recent, false );
	}

	/**
	 * The recent client keys, oldest first.
	 *
	 * @return string[]
	 */
	public static function recent() {
		$recent = get_option( self::RECENT_OPTION, [] );
		if ( ! is_array( $recent ) ) {
			return [];
		}
		return array_slice( array_values( array_filter( $recent, 'is_string' ) ), -self::RECENT_MAX );
	}

	/**
	 * Whether every recent accepted submission came from one client (a proxy nobody configured).
	 *
	 * @return bool
	 */
	public static function single_ip_suspected() {
		$recent = self::recent();
		return count( $recent ) >= self::RECENT_MIN_FOR_WARNING && 1 === count( array_unique( $recent ) );
	}
}
