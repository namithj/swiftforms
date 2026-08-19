<?php
/**
 * Typed accessor over the global settings Cassette-CMF page.
 *
 * @package SwiftForms
 */

declare(strict_types=1);

namespace SwiftForms\Settings;

use Pedalcms\CassetteCmf\CassetteCmf;

/**
 * Every field on the global Settings screen is stored by Cassette-CMF as
 * its own `swf_settings_{field}` option (see GlobalSettingsPage). This is a
 * thin read-only facade over that so the rest of the plugin (Mailer,
 * RateLimiter, SpamGuard, CssVariables, …) keeps calling
 * `GlobalSettings::instance()->get( $key, $fallback )` unchanged.
 */
final class GlobalSettings {

	public const PAGE_ID = 'swf_settings';

	private static ?self $instance = null;

	public static function instance(): self {
		if ( ! self::$instance instanceof self ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Returns a single setting value, falling back to $fallback when unset.
	 *
	 * @param string $key      Setting key.
	 * @param mixed  $fallback Fallback if the field has never been saved.
	 * @return mixed
	 */
	public function get( string $key, $fallback = null ) {
		return CassetteCmf::get_settings_field( $key, self::PAGE_ID, $fallback );
	}
}
