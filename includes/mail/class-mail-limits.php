<?php
/**
 * Limits for visitor-addressed mail.
 *
 * @package SwiftForms
 */

namespace SwiftForms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Mail_Limits
 *
 * C-6.4. A notification is visitor-addressed when a to/cc/bcc recipient came from a merge tag (a visitor
 * value). Such mail is limited, per recipient address:
 *
 * - at most once per address per form per 10 minutes (the key is an HMAC of the lower-cased address, so
 *   no address is stored);
 * - at most `email.visitor_mail_daily_cap` per form per day;
 * - at most `email.visitor_mail_hourly_cap` per site per hour.
 *
 * Counters are transients (they expire by themselves and are not autoloaded). Days and hours are fixed
 * UTC windows. A counter update is not atomic, so two simultaneous submissions can overshoot a cap by a
 * few mails; the limits stop floods, they are not a billing meter. A cap that is hit (the daily or the
 * hourly one) records a banner state in the option `swiftforms_mail_cap_notice` (Mail_Notice). The
 * per-address pause is a dedupe, not a cap: it is logged but does not raise the banner.
 */
class Mail_Limits {

	/**
	 * Seconds between two mails to one address from one form.
	 */
	const ADDRESS_WINDOW = 600;

	/**
	 * Option with the last cap hit.
	 */
	const NOTICE_OPTION = 'swiftforms_mail_cap_notice';

	/**
	 * Check the limits for one visitor-supplied address and, when it passes, count it.
	 *
	 * @param string $address Recipient address.
	 * @param string $form_id Effective formId.
	 * @return string Empty when the mail may go out, otherwise the limit that stopped it: address, daily or hourly.
	 */
	public static function reserve( $address, $form_id ) {
		$address_key = self::address_key( $address, $form_id );
		$day_key     = self::day_key( $form_id );
		$hour_key    = self::hour_key();

		if ( false !== get_transient( $address_key ) ) {
			Log::add( 'mail', 'info', 'A mail to a visitor-supplied address was skipped: one was sent to it from this form in the last 10 minutes.', [ 'limit' => 'address' ], $form_id );
			return 'address';
		}

		$daily_cap = self::cap( 'email.visitor_mail_daily_cap', 100 );
		$day_count = (int) get_transient( $day_key );
		if ( $day_count >= $daily_cap ) {
			self::hit( 'daily', $form_id, $daily_cap );
			return 'daily';
		}

		$hourly_cap = self::cap( 'email.visitor_mail_hourly_cap', 200 );
		$hour_count = (int) get_transient( $hour_key );
		if ( $hour_count >= $hourly_cap ) {
			self::hit( 'hourly', $form_id, $hourly_cap );
			return 'hourly';
		}

		set_transient( $address_key, 1, self::ADDRESS_WINDOW );
		set_transient( $day_key, $day_count + 1, DAY_IN_SECONDS + HOUR_IN_SECONDS );
		set_transient( $hour_key, $hour_count + 1, 2 * HOUR_IN_SECONDS );
		return '';
	}

	/**
	 * The last cap hit.
	 *
	 * @return array{time:int, cap:string, form:string, limit:int}|null Null when none was recorded.
	 */
	public static function notice_state() {
		$state = get_option( self::NOTICE_OPTION, null );
		if ( ! is_array( $state ) || ! isset( $state['time'], $state['cap'] ) ) {
			return null;
		}
		return [
			'time'  => (int) $state['time'],
			'cap'   => (string) $state['cap'],
			'form'  => isset( $state['form'] ) ? (string) $state['form'] : '',
			'limit' => isset( $state['limit'] ) ? (int) $state['limit'] : 0,
		];
	}

	/**
	 * Record and log a cap that was hit.
	 *
	 * @param string $cap     daily or hourly.
	 * @param string $form_id Effective formId.
	 * @param int    $limit   The cap.
	 * @return void
	 */
	private static function hit( $cap, $form_id, $limit ) {
		Log::add( 'mail', 'warning', 'A mail to a visitor-supplied address was skipped: the ' . $cap . ' limit was reached.', [ 'limit' => $cap ], $form_id );
		update_option(
			self::NOTICE_OPTION,
			[
				'time'  => Clock::now(),
				'cap'   => $cap,
				'form'  => $form_id,
				'limit' => (int) $limit,
			],
			false
		);
	}

	/**
	 * A cap from the settings (at least 1).
	 *
	 * @param string $path    Settings path.
	 * @param int    $default Fallback.
	 * @return int
	 */
	private static function cap( $path, $default ) {
		$value = Settings::get_value( $path );
		return is_int( $value ) && $value >= 1 ? $value : $default;
	}

	/**
	 * Transient key of the per-address pause. The address is only ever hashed.
	 *
	 * @param string $address Address.
	 * @param string $form_id Effective formId.
	 * @return string
	 */
	private static function address_key( $address, $form_id ) {
		return 'swiftforms_mla_' . hash_hmac( 'sha256', strtolower( (string) $address ), wp_salt( 'nonce' ) ) . '_' . substr( md5( (string) $form_id ), 0, 12 );
	}

	/**
	 * Transient key of the form's daily counter.
	 *
	 * @param string $form_id Effective formId.
	 * @return string
	 */
	private static function day_key( $form_id ) {
		return 'swiftforms_mld_' . substr( md5( (string) $form_id ), 0, 16 ) . '_' . gmdate( 'Ymd', Clock::now() );
	}

	/**
	 * Transient key of the site's hourly counter.
	 *
	 * @return string
	 */
	private static function hour_key() {
		return 'swiftforms_mlh_' . gmdate( 'YmdH', Clock::now() );
	}
}
