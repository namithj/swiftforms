<?php
/**
 * Tests for SwiftForms\Settings\Schema.
 *
 * @package SwiftForms
 */

declare(strict_types=1);

namespace SwiftForms\Tests\Settings;

use SwiftForms\Settings\Schema;
use SwiftForms\Tests\TestCase;

/**
 * The before-save helpers both settings screens share. Tested once here
 * rather than once per screen, which is the point of them living in one
 * place.
 */
final class SchemaTest extends TestCase {

	public function test_preserve_blank_secret_skips_saving_on_blank_input(): void {
		$this->assertNull( Schema::preserve_blank_secret( '' ) );
		$this->assertNull( Schema::preserve_blank_secret( '   ' ) );
	}

	public function test_preserve_blank_secret_passes_through_non_blank_input(): void {
		$this->assertSame( 'new-secret', Schema::preserve_blank_secret( 'new-secret' ) );
	}

	public function test_sanitize_email_list_keeps_only_valid_addresses(): void {
		$this->assertSame(
			'a@example.com, b@example.com',
			Schema::sanitize_email_list( 'a@example.com, not-an-email, b@example.com' )
		);
	}

	public function test_flatten_skips_container_and_display_only_fields(): void {
		$flat = Schema::flatten(
			array(
				'general' => array(
					'label'  => 'General',
					'fields' => array(
						array(
							'name' => 'kept',
							'type' => 'text',
						),
						Schema::heading( 'skipped_heading', 'A heading' ),
					),
				),
			)
		);

		$this->assertSame( array( 'kept' ), array_keys( $flat ) );
	}

	public function test_metabox_wraps_tabs_in_one_container(): void {
		$fields = Schema::metabox( 'smartlogix_swiftforms_x', 'swf-x', 'X', array( 'general' => array( 'label' => 'General' ) ) );

		$this->assertSame( 'metabox', $fields[0]['type'] );
		$this->assertSame( 'swf-x', $fields[0]['metabox_id'] );
		$this->assertSame( 'tabs', $fields[0]['fields'][0]['type'] );
		$this->assertSame( 'general', $fields[0]['fields'][0]['tabs'][0]['id'] );
	}
}
