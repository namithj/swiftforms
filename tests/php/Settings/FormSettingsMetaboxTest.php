<?php
/**
 * Tests for SwiftForms\Settings\FormSettingsMetabox.
 *
 * @package SwiftForms
 */

declare(strict_types=1);

namespace SwiftForms\Tests\Settings;

use Pedalcms\CassetteCmf\Field\Field_Factory;
use SwiftForms\Settings\FormSettingsMetabox;
use SwiftForms\Tests\TestCase;

final class FormSettingsMetaboxTest extends TestCase {

	private FormSettingsMetabox $metabox;

	public function set_up(): void {
		parent::set_up();

		// The plugin is already booted (see tests/bootstrap.php), so this is
		// the real, already-registered instance — the one Cassette-CMF's
		// `cassette_cmf_before_save_field_*` filters are actually wired to.
		$this->metabox = swf()->container()->get( 'form_settings_metabox' );
	}

	public function test_meta_key_prefixes_the_logical_key(): void {
		$this->assertSame( '_swf_setting_submitLabel', FormSettingsMetabox::meta_key( 'submitLabel' ) );
	}

	public function test_defaults_include_every_known_field(): void {
		$defaults = FormSettingsMetabox::defaults();

		$this->assertSame( 'default', $defaults['saveEntries'] );
		$this->assertSame( 0, $defaults['retentionDays'] );
		$this->assertSame( '0', $defaults['enableCaptcha'] );
	}

	/**
	 * The "design" tab's fields (Design\DesignSystem::inject_form_design_tab())
	 * share the same meta box and the same `swf_form_settings_schema`
	 * filter, but are Design\CssVariables' concern, under a different meta
	 * prefix — they must not leak into FormSettings::get()'s output.
	 */
	public function test_defaults_excludes_the_design_tabs_fields(): void {
		$this->assertArrayNotHasKey( 'accent', FormSettingsMetabox::defaults() );
		$this->assertArrayNotHasKey( 'skin', FormSettingsMetabox::defaults() );
	}

	public function test_design_meta_key_uses_its_own_prefix(): void {
		$this->assertSame( '_swf_design_accent', FormSettingsMetabox::design_meta_key( 'accent' ) );
	}

	public function test_field_type_reports_the_declared_cassette_cmf_type(): void {
		$this->assertSame( 'checkbox', FormSettingsMetabox::field_type( 'enableCaptcha' ) );
		$this->assertSame( 'number', FormSettingsMetabox::field_type( 'retentionDays' ) );
		$this->assertSame( 'select', FormSettingsMetabox::field_type( 'saveEntries' ) );
		$this->assertSame( 'textarea', FormSettingsMetabox::field_type( 'successMessage' ) );
	}

	/**
	 * The regression this class exists to prevent: Cassette-CMF's own
	 * textarea field strips line breaks (it inherits sanitize_text_field()
	 * from Abstract_Field), which would mangle multi-line email templates.
	 */
	public function test_textarea_type_resolves_to_the_newline_preserving_override(): void {
		$field = Field_Factory::create(
			array(
				'name' => 'x',
				'type' => 'textarea',
			)
		);

		$this->assertInstanceOf( \SwiftForms\Settings\CassetteCmfTextareaField::class, $field );
		$this->assertSame( "Line one\nLine two", $field->sanitize( "Line one\nLine two" ) );
	}
}
