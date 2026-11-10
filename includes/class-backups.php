<?php
/**
 * Backups of SwiftForms' options.
 *
 * @package SwiftForms
 */

namespace SwiftForms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Backups
 *
 * A ring of the five most recent copies of the plugin's options, taken before
 * a migration changes anything (C-4.6). Storage only: capability and nonce
 * checks belong to whoever offers a restore.
 *
 * The settings are one option per key (Settings::stored_options()): every
 * swiftforms_{section}_* option that exists is copied, raw.
 *
 * swiftforms_secrets (from R06, C-5.5) is deliberately not in OPTIONS: secrets
 * are never copied into a second option, an export or a restore file.
 */
class Backups {

	/**
	 * Option that holds the ring, oldest first. Autoload "no".
	 */
	const OPTION = 'swiftforms_backups';

	/**
	 * Number of backups kept.
	 */
	const KEEP = 5;

	/**
	 * Single options copied into every backup, besides the settings options.
	 */
	const OPTIONS = [ 'swiftforms_db_version', 'swiftforms_installed' ];

	/**
	 * Take a backup of the current options.
	 *
	 * Values are copied exactly as stored. An option that does not exist is left
	 * out of "options" (it is recorded as absent, not as a default), so a restore
	 * can tell "was never saved" from "was saved empty".
	 *
	 * @param string $reason Why the backup is taken (normalised to [a-z0-9._-]).
	 * @return array<string, mixed>|false The new entry { time, reason, version, options }, or false when it could not be stored.
	 */
	public static function create( $reason ) {
		$reason = preg_replace( '/[^a-z0-9._-]/', '', strtolower( (string) $reason ) );
		if ( '' === $reason ) {
			$reason = 'manual';
		}

		$options = Settings::stored_options();
		foreach ( self::OPTIONS as $name ) {
			$value = get_option( $name, null );
			if ( null !== $value ) {
				$options[ $name ] = $value;
			}
		}

		$entry = [
			'time'    => Clock::now(),
			'reason'  => $reason,
			'version' => SWIFTFORMS_VERSION,
			'options' => $options,
		];

		$ring   = self::all();
		$ring[] = $entry;
		$ring   = array_slice( $ring, -self::KEEP );

		// update_option() reports false for "no change", which is what a full ring of identical entries gives; check the stored copy.
		if ( ! update_option( self::OPTION, $ring, false ) && get_option( self::OPTION ) !== $ring ) {
			return false;
		}

		return $entry;
	}

	/**
	 * Every stored backup, oldest first (newest last). Malformed entries are skipped.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function all() {
		$stored = get_option( self::OPTION, [] );
		if ( ! is_array( $stored ) ) {
			return [];
		}

		$found = [];
		foreach ( $stored as $entry ) {
			if ( is_array( $entry ) && isset( $entry['time'], $entry['reason'], $entry['version'], $entry['options'] ) && is_array( $entry['options'] ) ) {
				$found[] = $entry;
			}
		}
		return $found;
	}
}
