<?php
/**
 * Typed accessor over the per-form settings Cassette-CMF meta box.
 *
 * @package SwiftForms
 */

declare(strict_types=1);

namespace SwiftForms\Settings;

use Pedalcms\CassetteCmf\CassetteCmf;

/**
 * Every field in the Form Settings meta box is stored by Cassette-CMF as
 * its own `_smartlogix_swiftforms_setting_{key}` post meta (see FormSettingsMetabox). This is
 * a thin read-only facade over that so the rest of the plugin (Pipeline,
 * Notifier, Webhooks, SpamGuard, Privacy, FormRenderer, …) keeps calling
 * `FormSettings::get( $form_id )` and getting back one flat, typed array —
 * unchanged from before this moved off a single `_smartlogix_swiftforms_settings` blob.
 */
final class FormSettings {

	/**
	 * Returns every default value, unprefixed key => default.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return FormSettingsMetabox::defaults();
	}

	/**
	 * Returns the resolved settings for a form: every known key, read from
	 * its own post meta, falling back to that field's default, and coerced
	 * to the type the rest of the plugin already expects (real bool for a
	 * checkbox, int/float for a number — Cassette-CMF itself stores both as
	 * plain postmeta strings).
	 *
	 * @param int $form_id Form post id.
	 * @return array<string, mixed>
	 */
	public static function get( int $form_id ): array {
		$values = array();

		foreach ( self::defaults() as $key => $default ) {
			$raw            = CassetteCmf::get_post_field( FormSettingsMetabox::meta_key( $key ), $form_id, $default );
			$values[ $key ] = self::coerce( FormSettingsMetabox::field_type( $key ), $raw );
		}

		$values['redirectUrl'] = wp_validate_redirect( (string) $values['redirectUrl'], '' );
		if ( defined( 'SMARTLOGIX_SWIFTFORMS_WEBHOOK_SECRET' ) ) {
			$values['webhookSecret'] = (string) SMARTLOGIX_SWIFTFORMS_WEBHOOK_SECRET;
		}

		return $values;
	}

	/**
	 * Whether entries should be saved for a given form, resolving the
	 * per-form tri-state against the global default.
	 *
	 * @param int $form_id Form post id.
	 */
	public static function should_save_entries( int $form_id ): bool {
		$mode = self::get( $form_id )['saveEntries'];

		if ( 'enabled' === $mode ) {
			return true;
		}

		if ( 'disabled' === $mode ) {
			return false;
		}

		return (bool) GlobalSettings::instance()->get( 'saveEntriesDefault', true );
	}

	/**
	 * Coerces a raw stored/default value to the type its Cassette-CMF field
	 * type implies. Cassette-CMF's own checkbox/number fields store plain
	 * strings ('1'/'0', "15") in post meta; every other caller in this
	 * plugin expects real bool/int/float, same as before this moved off a
	 * single JSON blob.
	 *
	 * @param mixed $value Raw stored (or default) value.
	 * @return mixed
	 */
	private static function coerce( string $cassette_type, $value ) {
		switch ( $cassette_type ) {
			case 'checkbox':
				return '1' === (string) $value;

			case 'number':
				if ( ! is_numeric( $value ) ) {
					return 0;
				}
				return str_contains( (string) $value, '.' ) ? (float) $value : (int) $value;

			default:
				return $value;
		}
	}
}
