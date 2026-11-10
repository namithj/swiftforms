<?php
/**
 * Schema version tracking and the numbered migration runner.
 *
 * @package SwiftForms
 */

namespace SwiftForms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Migrations
 *
 * swiftforms_db_version only ever goes up (C-4.6). Every migration is idempotent,
 * runs under a lock, is preceded by one backup of the options (when the site has
 * any data to lose) and is logged.
 *
 * The version option is stored with autoload "yes", unlike the other
 * bookkeeping options: it is read on every request to see whether a migration is
 * due, and it is one small integer, so autoloading it saves a query per request.
 * The lock, the backups and the install marker are not autoloaded.
 */
class Migrations {

	/**
	 * Current schema version. 1 means "the six tables exist".
	 */
	const DB_VERSION = 1;

	/**
	 * Option that holds the schema version.
	 */
	const OPTION_DB_VERSION = 'swiftforms_db_version';

	/**
	 * Option that holds the lock: "<expiry unix time>:<owner token>". Autoload "no".
	 */
	const OPTION_LOCK = 'swiftforms_migration_lock';

	/**
	 * Seconds a lock is honoured. A crashed run frees itself after this.
	 */
	const LOCK_TTL = 120;

	/**
	 * Schema version => migration method. Every method must be idempotent.
	 */
	const MIGRATIONS = [
		1 => 'migration_1',
	];

	/**
	 * Run the pending migrations, if any.
	 *
	 * Cheap when nothing is due (one cached option read). A second call after a
	 * successful run does nothing: no backup, no log row, no change to the install marker.
	 *
	 * @return bool Whether this call ran at least one migration.
	 */
	public static function maybe_run() {
		if ( self::stored_version() >= self::DB_VERSION ) {
			return false;
		}

		$token = self::acquire_lock();
		if ( null === $token ) {
			return false; // Another request is migrating; this one carries on and the next sees the result.
		}

		try {
			return self::run();
		} finally {
			self::release_lock( $token );
		}
	}

	/**
	 * Run every migration newer than the stored version. The caller holds the lock.
	 *
	 * @return bool
	 */
	private static function run() {
		// The version may have moved while this request waited for the lock.
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( self::OPTION_DB_VERSION, 'options' );
		$from = self::stored_version();

		$pending = array_keys( self::MIGRATIONS );
		sort( $pending, SORT_NUMERIC );
		$pending = array_values(
			array_filter(
				$pending,
				static function ( $version ) use ( $from ) {
					return $version > $from;
				}
			)
		);
		if ( ! $pending ) {
			return false;
		}
		$to = (int) end( $pending );

		// A new install has nothing to back up (the marker alone is not data).
		if ( Settings::has_stored() || null !== get_option( self::OPTION_DB_VERSION, null ) ) {
			if ( false === Backups::create( 'migration-' . $to ) ) {
				Log::add( 'migration', 'error', 'The backup before the migration could not be stored, so the migration did not run.', [ 'to' => $to ], 'migration-' . $to );
				return false; // Retry on the next request; never migrate without the backup.
			}
		}

		$ran = false;
		foreach ( $pending as $version ) {
			try {
				call_user_func( [ self::class, self::MIGRATIONS[ $version ] ] );
			} catch ( \Throwable $e ) {
				// Stop here; the version stays where it was and the next request retries.
				Log::add( 'migration', 'error', 'Migration ' . $version . ' failed: ' . $e->getMessage(), [ 'version' => $version ], 'migration-' . $version );
				break;
			}

			update_option( self::OPTION_DB_VERSION, $version, true );
			Log::add(
				'migration',
				'info',
				'Migration ' . $version . ' applied.',
				[
					'from' => $from,
					'to'   => $version,
				],
				'migration-' . $version
			);
			$ran = true;
		}

		if ( $ran ) {
			/**
			 * Fires after the stored schema version moved. Internal, not a public extension point.
			 *
			 * The form index (R01) rebuilds on it, as the architecture requires.
			 *
			 * @param int $from Version before this run.
			 * @param int $to   Version after this run.
			 */
			do_action( 'swiftforms_db_version_changed', $from, self::stored_version() );
		}

		return $ran;
	}

	/**
	 * Migration 1: create the six tables.
	 *
	 * Idempotent: dbDelta changes nothing when they already exist.
	 *
	 * @throws \RuntimeException When a table could not be created.
	 * @return void
	 */
	public static function migration_1() {
		Database::install();

		$missing = Database::missing();
		if ( $missing ) {
			throw new \RuntimeException( 'Could not create: ' . implode( ', ', $missing ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Never printed: the runner catches it and logs it.
		}
	}

	/**
	 * The stored schema version (0 when the site has none yet).
	 *
	 * @return int
	 */
	private static function stored_version() {
		return (int) get_option( self::OPTION_DB_VERSION, 0 );
	}

	/**
	 * Take the migration lock.
	 *
	 * add_option() inserts atomically, so of two racing requests exactly one gets
	 * the lock. A lock past its expiry belongs to a crashed run: it is removed
	 * only if it still holds the exact expired value, so a fresh lock taken in
	 * between is never deleted.
	 *
	 * @return string|null The owner token, or null when another run holds the lock.
	 */
	private static function acquire_lock() {
		global $wpdb;

		$token = Random::token( 8 );
		$value = ( Clock::now() + self::LOCK_TTL ) . ':' . $token;

		if ( add_option( self::OPTION_LOCK, $value, '', false ) ) {
			return $token;
		}

		$held = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::OPTION_LOCK ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Must bypass the object cache: another request owns this row.
		if ( ! is_string( $held ) || (int) $held >= Clock::now() ) {
			return null;
		}

		// Delete only the exact expired row, so a lock another request took in the meantime survives.
		$removed = $wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Compare-and-delete must be one atomic statement.
			$wpdb->options,
			[
				'option_name'  => self::OPTION_LOCK,
				'option_value' => $held,
			]
		);
		self::forget_lock_cache();

		return $removed && add_option( self::OPTION_LOCK, $value, '', false ) ? $token : null;
	}

	/**
	 * Release the lock, but only if this run still owns it.
	 *
	 * @param string $token Owner token from acquire_lock().
	 * @return void
	 */
	private static function release_lock( $token ) {
		global $wpdb;

		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value LIKE %s", self::OPTION_LOCK, '%:' . $wpdb->esc_like( $token ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		self::forget_lock_cache();
	}

	/**
	 * Drop the lock option from the object cache after a direct database change.
	 *
	 * @return void
	 */
	private static function forget_lock_cache() {
		wp_cache_delete( self::OPTION_LOCK, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		wp_cache_delete( 'alloptions', 'options' );
	}
}
