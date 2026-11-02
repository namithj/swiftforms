<?php
/**
 * UUID helpers.
 *
 * @package SwiftForms
 */

namespace SwiftForms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Uuid
 *
 * Name-based UUIDs (version 5, RFC 4122, SHA-1) for the effective formIds of
 * copied, missing and duplicated form blocks (C-2.7). Random UUIDs for new
 * forms come from the editor, or from wp_generate_uuid4() (plan rule 4).
 */
class Uuid {

	/**
	 * Namespace UUID for every SwiftForms name-based ID (C-2.7).
	 */
	const NAMESPACE = '6f3c2a3e-5d1b-4c1e-9b8a-53776966746f';

	/**
	 * Version 5 UUID of a name in a namespace.
	 *
	 * @param string $name      Any string (bytes are hashed as they are).
	 * @param string $namespace A canonical UUID. Defaults to the SwiftForms namespace.
	 * @return string Lowercase canonical UUID.
	 */
	public static function v5( $name, $namespace = self::NAMESPACE ) {
		if ( ! self::is_valid( $namespace ) ) {
			_doing_it_wrong( __METHOD__, 'The namespace must be a canonical lowercase UUID.', '0.1.0' );
			$namespace = self::NAMESPACE;
		}

		$hash = sha1( (string) hex2bin( str_replace( '-', '', (string) $namespace ) ) . (string) $name );

		$time_hi = ( hexdec( substr( $hash, 12, 4 ) ) & 0x0fff ) | 0x5000;
		$clock   = ( hexdec( substr( $hash, 16, 4 ) ) & 0x3fff ) | 0x8000;

		return sprintf(
			'%s-%s-%04x-%04x-%s',
			substr( $hash, 0, 8 ),
			substr( $hash, 8, 4 ),
			$time_hi,
			$clock,
			substr( $hash, 20, 12 )
		);
	}

	/**
	 * Whether a value is a canonical UUID: lowercase hex in the 8-4-4-4-12 layout.
	 *
	 * @param mixed $value Value to test.
	 * @return bool
	 */
	public static function is_valid( $value ) {
		return is_string( $value ) && 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $value );
	}
}
