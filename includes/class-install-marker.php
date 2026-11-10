<?php
/**
 * Install marker (C-1.4).
 *
 * @package SwiftForms
 */

namespace SwiftForms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Install_Marker
 *
 * The option swiftforms_installed records which version a site first ran. It is
 * the only way code tells a new install from an existing one (a "new installs
 * only" default, C-5.3), so it is written once and never touched again.
 *
 * Stored with autoload "no": it is read only when a default is decided.
 */
class Install_Marker {

	/**
	 * Option that holds the marker: { version, time }.
	 */
	const OPTION = 'swiftforms_installed';

	/**
	 * Version assumed for a site that has swiftforms_db_version but no marker.
	 */
	const LEGACY_VERSION = '1.0.0';

	/**
	 * Write the marker when neither it nor swiftforms_db_version exists.
	 *
	 * Never updates an existing marker, however old or damaged it is.
	 *
	 * @return bool Whether this call wrote the marker.
	 */
	public static function maybe_write() {
		if ( self::exists( self::OPTION ) || self::exists( Migrations::OPTION_DB_VERSION ) ) {
			return false;
		}

		// add_option() is atomic: when two requests race, exactly one of them writes.
		return add_option(
			self::OPTION,
			[
				'version' => SWIFTFORMS_VERSION,
				'time'    => Clock::now(),
			],
			'',
			false
		);
	}

	/**
	 * The version this site first ran.
	 *
	 * @return string The marker's version; "1.0.0" when swiftforms_db_version exists without a marker; otherwise the running version (a brand-new site that has not run yet).
	 */
	public static function installed_version() {
		$marker = get_option( self::OPTION, null );
		if ( is_array( $marker ) && isset( $marker['version'] ) && is_string( $marker['version'] ) && '' !== $marker['version'] ) {
			return $marker['version'];
		}
		if ( self::exists( Migrations::OPTION_DB_VERSION ) ) {
			return self::LEGACY_VERSION;
		}
		return SWIFTFORMS_VERSION;
	}

	/**
	 * Whether an option row exists, whatever its value.
	 *
	 * @param string $name Option name.
	 * @return bool
	 */
	private static function exists( $name ) {
		return null !== get_option( $name, null );
	}
}
