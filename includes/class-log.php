<?php
/**
 * Diagnostics log.
 *
 * @package SwiftForms
 */

namespace SwiftForms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Log
 *
 * Rows in {prefix}swiftforms_log: mail, webhook, error and migration events,
 * pruned after 30 days. It is for diagnostics. It never holds anything a
 * user created (entry values, notes, payment records, C-4.5), so callers pass
 * identifiers and counts in the message and context, never field values.
 */
class Log {

	/**
	 * Days a row is kept.
	 */
	const RETENTION_DAYS = 30;

	/**
	 * Allowed row types.
	 */
	const TYPES = [ 'mail', 'webhook', 'error', 'migration' ];

	/**
	 * Allowed levels, lowest first.
	 */
	const LEVELS = [ 'debug', 'info', 'warning', 'error' ];

	/**
	 * Add a row.
	 *
	 * "debug" rows are written only while advanced.debug_log is on. Safe to call
	 * before the table exists (a fresh site, a failed migration): it returns
	 * false and prints nothing.
	 *
	 * @param string $type    One of self::TYPES.
	 * @param string $level   One of self::LEVELS (anything else counts as "info").
	 * @param string $message Short plain-text message. Never user-created data.
	 * @param array<string, mixed> $context Extra diagnostics, stored as JSON. Never user-created data.
	 * @param string $ref     Optional reference, for example a form ID or a migration name (100 characters at most).
	 * @return int|false The row ID, or false when nothing was written.
	 */
	public static function add( $type, $level, $message, array $context = [], $ref = '' ) {
		global $wpdb;

		if ( ! in_array( $type, self::TYPES, true ) ) {
			return false;
		}
		if ( ! in_array( $level, self::LEVELS, true ) ) {
			$level = 'info';
		}
		if ( 'debug' === $level && true !== Settings::get_value( 'advanced.debug_log' ) ) {
			return false;
		}

		$json = wp_json_encode( $context );

		$suppress = $wpdb->suppress_errors();
		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Own table.
			Database::table( 'log' ),
			[
				'type'        => $type,
				'ref'         => substr( (string) $ref, 0, 100 ),
				'level'       => $level,
				'message'     => (string) $message,
				'context'     => false === $json ? '{}' : $json,
				'created_gmt' => Clock::gmt_mysql(),
			]
		);
		$id       = $inserted ? (int) $wpdb->insert_id : false;
		$wpdb->suppress_errors( $suppress );

		return $id;
	}

	/**
	 * Delete rows older than 30 days. A row exactly 30 days old is kept.
	 *
	 * @return int Number of rows deleted (0 when the table does not exist).
	 */
	public static function prune() {
		global $wpdb;

		$cutoff = gmdate( 'Y-m-d H:i:s', Clock::now() - self::RETENTION_DAYS * DAY_IN_SECONDS );
		$table  = esc_sql( Database::table( 'log' ) );

		$suppress = $wpdb->suppress_errors();
		$deleted  = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_gmt < %s", $cutoff ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Own table; name built from $wpdb->prefix.
		$wpdb->suppress_errors( $suppress );

		return (int) $deleted;
	}
}
