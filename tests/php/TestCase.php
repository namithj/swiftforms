<?php
/**
 * Shared base test case.
 *
 * @package SwiftForms
 */

declare(strict_types=1);

namespace SwiftForms\Tests;

use WP_UnitTestCase;

/**
 * Common helpers used across the SwiftForms test suite.
 */
abstract class TestCase extends WP_UnitTestCase {

	/**
	 * WP_UnitTestCase::set_up() calls unregister_all_meta_keys() before
	 * every test to keep tests isolated, which also wipes the `_smartlogix_swiftforms_*`
	 * meta our plugin registers once on `init` (fired only once for the
	 * whole PHPUnit process). Re-running just the (idempotent) post
	 * type/meta registration here, rather than re-firing all of `init`,
	 * restores it without re-triggering core's own init-time side effects
	 * (e.g. duplicate block-type registration notices).
	 */
	public function set_up(): void {
		parent::set_up();

		( new \SwiftForms\PostTypes() )->register_post_types();
	}

	/**
	 * Reads and decodes a JSON fixture from tests/fixtures/.
	 *
	 * @param string $name Fixture filename, e.g. "conditions.json".
	 * @return array<string, mixed>
	 */
	protected function fixture( string $name ): array {
		$path = dirname( __DIR__ ) . '/fixtures/' . $name;

		return json_decode( (string) file_get_contents( $path ), true );
	}

	/**
	 * Creates a `smartlogix_swf_form` post with the given block content and settings.
	 * Settings overrides are written directly to their `_smartlogix_swiftforms_setting_*` post
	 * meta (bypassing Cassette-CMF's own save_post flow, which needs a real
	 * $_POST/nonce/current-user — see PostTypesTest for a test that exercises
	 * that flow directly), so tests can seed a form's settings in one call.
	 *
	 * @param string               $content  Serialized block markup.
	 * @param array<string, mixed> $settings Optional per-field overrides, unprefixed key => value.
	 */
	protected function create_form( string $content = '', array $settings = array() ): int {
		$retention_key             = \SwiftForms\Settings\FormSettingsMetabox::meta_key( 'retentionConfirmed' );
		$previous_retention_choice = $_POST[ $retention_key ] ?? null;
		$_POST[ $retention_key ]   = '1';
		$form_id                   = self::factory()->post->create(
			array(
				'post_type'    => \SwiftForms\PostTypes::FORM_POST_TYPE,
				'post_title'   => 'Test Form',
				'post_status'  => 'publish',
				'post_content' => $content,
			)
		);
		if ( null === $previous_retention_choice ) {
			unset( $_POST[ $retention_key ] );
		} else {
			$_POST[ $retention_key ] = $previous_retention_choice;
		}
		update_post_meta( $form_id, $retention_key, '1' );

		foreach ( $settings as $key => $value ) {
			if ( is_bool( $value ) ) {
				$value = $value ? '1' : '0';
			}

			update_post_meta( $form_id, \SwiftForms\Settings\FormSettingsMetabox::meta_key( $key ), $value );
		}

		return $form_id;
	}
}
