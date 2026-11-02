<?php
/**
 * Settings reader (C-5).
 *
 * @package SwiftForms
 */

namespace SwiftForms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Settings
 *
 * One option per setting, swiftforms_{section}_{key} (for example swiftforms_spam_honeypot), written by the cassette-cmf
 * settings page (Settings_Page). This class rebuilds the logical C-5 array from them, so every caller of get() and
 * get_value() sees the same shape as before. The rules (C-5.2 to C-5.4):
 *
 * - An absent option, a wrong-typed value or a corrupt one means the default. Readers never fatal.
 * - Options this class does not know are never touched.
 * - A value is never altered on read: a valid value comes back as stored (no trimming, no clamping); only its type is
 *   normalised, because the options table returns strings ("1", "0", "5").
 * - Saving something that changes nothing writes nothing (Settings_Page::skip_unchanged()).
 * - Nothing here writes: the plugin never creates a settings option on activation or on read, and there is no
 *   array writer. Tests and importers write the per-key options directly.
 *
 * Secrets (C-5.5) are not settings: they live in swiftforms_secrets (from R06).
 *
 * Output escaping is the caller's job: footer_text and default_success_message may hold HTML (cleaned with
 * wp_kses_post() on write by the settings page) and are escaped or filtered again where they are printed.
 */
class Settings {

	/**
	 * Prefix of every settings option. The settings page has the id "swiftforms", so cassette-cmf names a field
	 * "{section}_{key}" as "swiftforms_{section}_{key}".
	 */
	const PREFIX = 'swiftforms_';

	/**
	 * Schema version in the returned array's "_v". Never stored: the page declares no schema_version until a release needs an upgrade.
	 */
	const SCHEMA_VERSION = 1;

	/**
	 * Keys that need more than a type check, by "section.key".
	 *
	 * Every other key is checked against the type of its default: bool, int >= 0 or string.
	 */
	const RULES = [
		'email.from_email'      => 'email',
		'email.notification_to' => 'emails',
		'email.accent_color'    => 'color',
		'spam.spam_action'      => 'enum:store,discard',
	];

	/**
	 * Keys that may hold HTML.
	 */
	const HTML_KEYS = [ 'general.default_success_message', 'email.footer_text' ];

	/**
	 * Largest integer a stored integer setting may have.
	 */
	const MAX_INT = 2147483647;

	/**
	 * Every key by section, in the order of the C-5 tree. Kept apart from the defaults because the defaults hold a translated
	 * text and the keys are needed at plugins_loaded, before translations may load.
	 */
	const KEYS = [
		'general'  => [ 'store_entries', 'store_ip', 'store_user_agent', 'retention_days', 'load_styles', 'default_success_message' ],
		'email'    => [ 'from_name', 'from_email', 'notification_to', 'html_template', 'logo_id', 'accent_color', 'footer_text', 'visitor_mail_daily_cap', 'visitor_mail_hourly_cap' ],
		'spam'     => [ 'honeypot', 'min_seconds', 'js_token', 'rate_limit_per_minute', 'blocked_words', 'spam_action' ],
		'privacy'  => [ 'exporter', 'eraser' ],
		'advanced' => [ 'delete_on_uninstall', 'debug_log' ],
	];

	/**
	 * Normalised settings per site, for the rest of the request.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private static $cache = [];

	/**
	 * Hook the cache flush and the corrupt-value guard.
	 *
	 * @return void
	 */
	public static function init() {
		foreach ( [ 'added_option', 'updated_option', 'deleted_option' ] as $hook ) {
			add_action( $hook, [ self::class, 'on_option_change' ] );
		}
		// The install marker decides "new installs only" defaults (C-5.3).
		add_action( 'add_option_' . Install_Marker::OPTION, [ self::class, 'flush_cache' ] );

		// A stored value of the wrong type reads as the default everywhere, also on the settings route and screen.
		foreach ( static::paths() as $path ) {
			add_filter( 'option_' . static::option_name( $path ), [ self::class, 'guard_stored_value' ], 10, 2 );
		}
	}

	/**
	 * Forget the cached settings when a settings option changes.
	 *
	 * @param string $option Option name.
	 * @return void
	 */
	public static function on_option_change( $option ) {
		if ( null !== static::path_of( (string) $option ) ) {
			static::flush_cache();
		}
	}

	/**
	 * Forget the cached settings of the current site.
	 *
	 * @return void
	 */
	public static function flush_cache() {
		unset( self::$cache[ get_current_blog_id() ] );
	}

	/**
	 * Replace a corrupt stored value by the default (filter option_{name}).
	 *
	 * A valid value is returned untouched.
	 *
	 * @param mixed  $value  Stored value.
	 * @param string $option Option name.
	 * @return mixed
	 */
	public static function guard_stored_value( $value, $option = '' ) {
		$path = static::path_of( (string) $option );
		if ( null === $path ) {
			return $value;
		}
		$default = static::default_of( $path );
		return null === static::coerce( $path, $value, $default ) ? $default : $value;
	}

	/**
	 * The C-5 defaults.
	 *
	 * @param string|null $installed_version swiftforms_installed.version, or null when it is not known. A default that
	 *                                       applies to new installs only (C-5.3) takes its new value when this is at
	 *                                       least the release that introduced it; the absence of a settings option
	 *                                       means nothing about install age.
	 * @return array<string, mixed>
	 */
	public static function defaults( $installed_version = null ) {
		$defaults = static::base_defaults();

		foreach ( static::new_install_defaults() as $path => $rule ) {
			if ( null !== $installed_version && version_compare( (string) $installed_version, $rule['since'], '>=' ) ) {
				list( $section, $key )        = explode( '.', $path, 2 );
				$defaults[ $section ][ $key ] = $rule['value'];
			}
		}

		return $defaults;
	}

	/**
	 * The defaults every site has, new or old.
	 *
	 * @return array<string, mixed>
	 */
	protected static function base_defaults() {
		return [
			'_v'       => self::SCHEMA_VERSION,
			'general'  => [
				'store_entries'           => true,
				'store_ip'                => false,
				'store_user_agent'        => false,
				'retention_days'          => 0,
				'load_styles'             => true,
				// The confirmation block in the editor uses its own text (R01 2.1); this is the fallback when a form has none.
				'default_success_message' => __( 'Thank you. Your message has been sent.', 'swiftforms' ),
			],
			'email'    => [
				'from_name'               => '',
				'from_email'              => '',
				'notification_to'         => '',
				'html_template'           => true,
				'logo_id'                 => 0,
				'accent_color'            => '',
				'footer_text'             => '',
				'visitor_mail_daily_cap'  => 100,
				'visitor_mail_hourly_cap' => 200,
			],
			'spam'     => [
				'honeypot'              => true,
				'min_seconds'           => 3,
				'js_token'              => true,
				'rate_limit_per_minute' => 5,
				'blocked_words'         => '',
				'spam_action'           => 'store',
			],
			'privacy'  => [
				'exporter' => true,
				'eraser'   => true,
			],
			'advanced' => [
				'delete_on_uninstall' => false,
				'debug_log'           => false,
			],
		];
	}

	/**
	 * Defaults that differ for new installs (C-5.3). None exist in 1.0.
	 *
	 * Each entry is "section.key" => { since: release that introduced the new default, value: the new default }.
	 * The key itself must be in base_defaults() with the value existing sites keep.
	 *
	 * @return array<string, array{since:string,value:mixed}>
	 */
	protected static function new_install_defaults() {
		return [];
	}

	/**
	 * Every known "section.key".
	 *
	 * @return array<int, string>
	 */
	public static function paths() {
		$paths = [];
		foreach ( self::KEYS as $section => $keys ) {
			foreach ( $keys as $key ) {
				$paths[] = $section . '.' . $key;
			}
		}
		return $paths;
	}

	/**
	 * The option that holds a setting.
	 *
	 * @param string $path "section.key", for example "spam.honeypot".
	 * @return string For example "swiftforms_spam_honeypot".
	 */
	public static function option_name( $path ) {
		return self::PREFIX . str_replace( '.', '_', (string) $path );
	}

	/**
	 * The setting an option holds.
	 *
	 * @param string $option Option name.
	 * @return string|null "section.key", or null when the option is not a known setting.
	 */
	public static function path_of( $option ) {
		if ( 0 !== strpos( $option, self::PREFIX ) ) {
			return null;
		}
		$rest = substr( $option, strlen( self::PREFIX ) );
		foreach ( self::KEYS as $section => $keys ) {
			if ( 0 === strpos( $rest, $section . '_' ) && in_array( substr( $rest, strlen( $section ) + 1 ), $keys, true ) ) {
				return $section . '.' . substr( $rest, strlen( $section ) + 1 );
			}
		}
		return null;
	}

	/**
	 * The current default of one setting (the new-install default when the install marker says so).
	 *
	 * @param string $path "section.key".
	 * @return mixed Null for an unknown path.
	 */
	public static function default_of( $path ) {
		$parts = explode( '.', (string) $path, 2 );
		if ( 2 !== count( $parts ) ) {
			return null;
		}
		$defaults = static::defaults( Install_Marker::installed_version() );
		return $defaults[ $parts[0] ][ $parts[1] ] ?? null;
	}

	/**
	 * The settings of this site: stored values over the defaults, normalised. Cached for the request.
	 *
	 * @return array<string, mixed>
	 */
	public static function get() {
		$blog_id = get_current_blog_id();
		if ( ! isset( self::$cache[ $blog_id ] ) ) {
			$defaults = static::defaults( Install_Marker::installed_version() );
			$out      = [ '_v' => $defaults['_v'] ];
			foreach ( $defaults as $section => $keys ) {
				if ( '_v' === $section ) {
					continue;
				}
				$out[ $section ] = [];
				foreach ( $keys as $key => $default ) {
					$stored                  = get_option( static::option_name( $section . '.' . $key ), null );
					$value                   = null === $stored ? null : static::coerce( $section . '.' . $key, $stored, $default );
					$out[ $section ][ $key ] = null === $value ? $default : $value;
				}
			}
			self::$cache[ $blog_id ] = $out;
		}
		return self::$cache[ $blog_id ];
	}

	/**
	 * One setting by "section.key".
	 *
	 * @param string $path    Dotted path, for example "spam.honeypot".
	 * @param mixed  $default Returned when the path does not exist.
	 * @return mixed
	 */
	public static function get_value( $path, $default = null ) {
		$parts = explode( '.', (string) $path, 2 );
		if ( 2 !== count( $parts ) ) {
			return $default;
		}
		$settings = self::get();
		if ( isset( $settings[ $parts[0] ] ) && is_array( $settings[ $parts[0] ] ) && array_key_exists( $parts[1], $settings[ $parts[0] ] ) ) {
			return $settings[ $parts[0] ][ $parts[1] ];
		}
		return $default;
	}

	/**
	 * Every settings option that exists on this site, exactly as stored (not guarded), by option name.
	 *
	 * Used by the backups. Reads the table, so an option the guard would replace is returned as it is stored.
	 *
	 * @return array<string, mixed>
	 */
	public static function stored_options() {
		global $wpdb;

		$likes = [];
		foreach ( array_keys( self::KEYS ) as $section ) {
			$likes[] = $wpdb->esc_like( self::PREFIX . $section . '_' ) . '%';
		}

		$where = implode( ' OR ', array_fill( 0, count( $likes ), 'option_name LIKE %s' ) );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT option_name, option_value FROM {$wpdb->options} WHERE {$where} ORDER BY option_name", $likes ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Fixed column and table names, LIKE patterns prepared.

		$found = [];
		foreach ( (array) $rows as $row ) {
			$found[ $row['option_name'] ] = maybe_unserialize( $row['option_value'] );
		}
		return $found;
	}

	/**
	 * Whether any settings option exists on this site.
	 *
	 * @return bool
	 */
	public static function has_stored() {
		return [] !== static::stored_options();
	}

	/**
	 * The typed value of a stored (or submitted) value, or null when it is not valid for the key.
	 *
	 * The options table returns strings, so a boolean is "1" or "0" (or a real boolean), an integer is a whole number
	 * (an int or a string of digits) from 0, and a string stays a string. A valid value is never altered beyond the type.
	 *
	 * @param string $path    "section.key".
	 * @param mixed  $value   Candidate.
	 * @param mixed  $default The key's default, which decides the type when RULES has no entry.
	 * @return mixed Null when invalid (no setting is ever null).
	 */
	public static function coerce( $path, $value, $default = null ) {
		$default = null === $default ? static::default_of( $path ) : $default;
		$rule    = self::RULES[ $path ] ?? null;

		if ( 'email' === $rule ) {
			return is_string( $value ) && sanitize_email( $value ) === $value ? $value : null;
		}
		if ( 'emails' === $rule ) {
			if ( ! is_string( $value ) ) {
				return null;
			}
			if ( '' === $value ) {
				return $value;
			}
			foreach ( explode( ',', $value ) as $address ) {
				$address = trim( $address );
				if ( '' === $address || sanitize_email( $address ) !== $address ) {
					return null;
				}
			}
			return $value;
		}
		if ( 'color' === $rule ) {
			return is_string( $value ) && ( '' === $value || sanitize_hex_color( $value ) === $value ) ? $value : null;
		}
		if ( null !== $rule && 0 === strpos( $rule, 'enum:' ) ) {
			return in_array( $value, explode( ',', substr( $rule, 5 ) ), true ) ? $value : null;
		}

		if ( is_bool( $default ) ) {
			if ( is_bool( $value ) ) {
				return $value;
			}
			if ( '1' === $value ) {
				return true;
			}
			return '0' === $value ? false : null;
		}
		if ( is_int( $default ) ) {
			if ( is_int( $value ) ) {
				return $value >= 0 && $value <= self::MAX_INT ? $value : null;
			}
			if ( is_string( $value ) && 1 === preg_match( '/^[0-9]{1,10}$/', $value ) && (int) $value <= self::MAX_INT ) {
				return (int) $value;
			}
			return null;
		}
		return is_string( $value ) ? $value : null;
	}
}
