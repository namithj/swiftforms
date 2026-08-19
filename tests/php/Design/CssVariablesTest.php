<?php
/**
 * Tests for SwiftForms\Design\CssVariables.
 *
 * @package SwiftForms
 */

declare(strict_types=1);

namespace SwiftForms\Tests\Design;

use SwiftForms\Design\CssVariables;
use SwiftForms\Settings\FormSettingsMetabox;
use SwiftForms\Tests\TestCase;

final class CssVariablesTest extends TestCase {

	public function test_get_defaults_to_empty_overrides_for_a_fresh_form(): void {
		$form_id = $this->create_form();

		$design = CssVariables::get( $form_id );

		$this->assertSame( '', $design['skin'] );
		$this->assertSame( '', $design['accent'] );
		$this->assertSame( '', $design['gap'] );
	}

	public function test_get_reads_back_a_saved_override(): void {
		$form_id = $this->create_form();

		update_post_meta( $form_id, FormSettingsMetabox::design_meta_key( 'accent' ), '#123456' );

		$this->assertSame( '#123456', CssVariables::get( $form_id )['accent'] );
	}

	public function test_form_inline_style_only_includes_overridden_vars(): void {
		$css_variables = new CssVariables();

		$style = $css_variables->form_inline_style(
			array_merge( CssVariables::defaults(), array( 'accent' => '#123456' ) )
		);

		$this->assertStringContainsString( '--swf-accent: #123456', $style );
		$this->assertStringNotContainsString( '--swf-field-bg', $style );
	}

	public function test_form_inline_style_is_empty_when_nothing_overridden(): void {
		$css_variables = new CssVariables();

		$this->assertSame( '', $css_variables->form_inline_style( CssVariables::defaults() ) );
	}
}
