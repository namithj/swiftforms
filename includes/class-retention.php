<?php
/**
 * Entry retention.
 *
 * @package SwiftForms
 */

namespace SwiftForms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Retention
 *
 * With general.retention_days above 0, the daily event deletes entries older than that many days, with their
 * values and meta. "Older" is strict: an entry created exactly N x 86,400 seconds ago stays until the next
 * second has passed. Spam and trash go first, then the rest, 500 entries per query.
 */
class Retention {

	/**
	 * Most batches one run deletes, so a huge backlog cannot hold up the cron request. The rest goes the next day.
	 */
	const MAX_BATCHES = 200;

	/**
	 * Delete the entries past retention.
	 *
	 * @return int Number of entries deleted.
	 */
	public static function run() {
		$days = Settings::get_value( 'general.retention_days', 0 );
		if ( ! is_int( $days ) || $days <= 0 ) {
			return 0;
		}

		$cutoff  = gmdate( 'Y-m-d H:i:s', Clock::now() - $days * DAY_IN_SECONDS );
		$deleted = 0;
		$batches = 0;

		foreach ( [ [ 'spam', 'trash' ], [] ] as $statuses ) {
			while ( $batches < self::MAX_BATCHES ) {
				$ids = Entries_Repository::ids_before( $cutoff, $statuses, Entries_Repository::DELETE_BATCH );
				if ( ! $ids ) {
					break;
				}
				++$batches;
				$removed  = Entries_Repository::delete_many( $ids );
				$deleted += $removed;
				if ( 0 === $removed ) {
					break;
				}
			}
		}
		return $deleted;
	}
}
