<?php
/**
 * Scheduled tasks.
 *
 * @package SwiftForms
 */

namespace SwiftForms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Cron
 *
 * One daily event. It is scheduled lazily on init (so network activation and
 * sites that were already running need no activation step) and removed on
 * deactivation. Data is never touched by deactivation.
 */
class Cron {

	/**
	 * Event name.
	 */
	const DAILY = 'swiftforms_daily';

	/**
	 * Hook the event and its lazy scheduling.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( self::DAILY, [ self::class, 'run_daily' ] );
		add_action( 'init', [ self::class, 'maybe_schedule' ] );
	}

	/**
	 * Schedule the daily event when it is missing.
	 *
	 * @return void
	 */
	public static function maybe_schedule() {
		if ( wp_installing() || wp_next_scheduled( self::DAILY ) ) {
			return;
		}
		wp_schedule_event( Clock::now() + HOUR_IN_SECONDS, 'daily', self::DAILY );
	}

	/**
	 * Remove every scheduled run of the event, whatever its arguments.
	 *
	 * @return void
	 */
	public static function unschedule() {
		wp_unschedule_hook( self::DAILY );
	}

	/**
	 * Daily tasks: prune the log and apply the entry retention setting.
	 *
	 * @return void
	 */
	public static function run_daily() {
		Log::prune();
		Retention::run();
	}
}
