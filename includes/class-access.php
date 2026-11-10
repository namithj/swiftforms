<?php
/**
 * Capabilities and the one access check.
 *
 * @package SwiftForms
 */

namespace SwiftForms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Access
 *
 * SwiftForms capabilities are meta capabilities (C-8.4): they exist only as a
 * mapping to a primitive capability, `manage_options` unless the site changes
 * it. No role or user is ever modified in the database, so uninstalling leaves
 * nothing behind. Every surface (REST, export, files, resend, admin screens)
 * asks Access::can().
 */
class Access {

	/**
	 * The capabilities SwiftForms defines.
	 */
	const CAPS = [
		'swiftforms_manage_settings',
		'swiftforms_view_entries',
		'swiftforms_edit_entries',
		'swiftforms_delete_entries',
		'swiftforms_export_entries',
		'swiftforms_manage_integrations',
	];

	/**
	 * Primitive capability every SwiftForms capability maps to by default.
	 */
	const DEFAULT_PRIMITIVE = 'manage_options';

	/**
	 * Hook the capability mapping.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'map_meta_cap', [ self::class, 'map_meta_cap' ], 10, 2 );
	}

	/**
	 * The capability map: SwiftForms capability => primitive capability.
	 *
	 * @return array<string, string>
	 */
	public static function map() {
		$default = array_fill_keys( self::CAPS, self::DEFAULT_PRIMITIVE );

		/**
		 * Filters which primitive capability each SwiftForms capability maps to.
		 *
		 * Use it to let a custom role, for example "support", view entries without
		 * granting it `manage_options`. Return the array you received with the
		 * values you want changed. A value must be a primitive capability name
		 * (a non-empty string of letters, digits, underscores, hyphens or
		 * colons) and not one of SwiftForms' own capabilities. Anything else, and
		 * any capability you add that SwiftForms does not define, is ignored and
		 * the default `manage_options` is used instead. Roles are never modified.
		 *
		 * This is a public hook (C-8.3) and is never removed.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, string> $map SwiftForms capability => primitive capability. Keys: swiftforms_manage_settings,
		 *                                   swiftforms_view_entries, swiftforms_edit_entries, swiftforms_delete_entries,
		 *                                   swiftforms_export_entries, swiftforms_manage_integrations.
		 */
		$filtered = apply_filters( 'swiftforms_capability_map', $default );
		if ( ! is_array( $filtered ) ) {
			return $default;
		}

		$map = $default;
		foreach ( self::CAPS as $cap ) {
			$primitive = $filtered[ $cap ] ?? null;
			if ( is_string( $primitive ) && 1 === preg_match( '/^[A-Za-z0-9_:\-]+$/', $primitive ) && ! in_array( $primitive, self::CAPS, true ) ) {
				$map[ $cap ] = $primitive;
			}
		}
		return $map;
	}

	/**
	 * `map_meta_cap` callback: turn a SwiftForms capability into its primitive capability.
	 *
	 * @param string[] $caps Primitive capabilities WordPress has worked out so far.
	 * @param string   $cap  The capability being checked.
	 * @return string[]
	 */
	public static function map_meta_cap( $caps, $cap ) {
		if ( in_array( $cap, self::CAPS, true ) ) {
			$map = self::map();
			return [ $map[ $cap ] ];
		}
		return $caps;
	}

	/**
	 * Whether a user holds a SwiftForms capability. The single access check.
	 *
	 * @param string   $cap     One of self::CAPS. Anything else is refused.
	 * @param int|null $user_id User ID, or null for the current user.
	 * @return bool
	 */
	public static function can( $cap, $user_id = null ) {
		if ( ! in_array( $cap, self::CAPS, true ) ) {
			return false;
		}
		$user_id = null === $user_id ? get_current_user_id() : (int) $user_id;
		if ( $user_id <= 0 ) {
			return false;
		}
		return user_can( $user_id, $cap );
	}
}
