<?php
/**
 * Tests for SwiftForms\Fields\Renderer.
 *
 * @package SwiftForms
 */

declare(strict_types=1);

namespace SwiftForms\Tests\Fields;

use SwiftForms\Fields\FieldRegistry;
use SwiftForms\Fields\Renderer;
use SwiftForms\Tests\TestCase;

final class RendererTest extends TestCase {

	private Renderer $renderer;

	public function set_up(): void {
		parent::set_up();

		$registry = new FieldRegistry();
		$registry->load_types();

		$this->renderer = new Renderer( $registry );
	}

	public function test_textarea_field_reports_its_own_type_not_text(): void {
		// A regression guard for the exact bug class the MVP shipped with
		// (textarea saved data-field-type="text"): every field type is
		// rendered by the same Renderer keyed by its real type, so this
		// can't drift.
		$html = $this->renderer->render(
			'textarea',
			array(
				'slug'  => 'message',
				'label' => 'Message',
			)
		);

		$this->assertStringContainsString( 'data-field-type="textarea"', $html );
		$this->assertStringContainsString( '<textarea', $html );
	}

	public function test_required_field_renders_required_indicator_and_attribute(): void {
		$html = $this->renderer->render(
			'text',
			array(
				'slug'     => 'name',
				'label'    => 'Name',
				'required' => true,
			)
		);

		$this->assertStringContainsString( 'data-field-required="1"', $html );
		$this->assertStringContainsString( 'required', $html );
		$this->assertStringContainsString( 'swf-field__required', $html );
	}

	public function test_select_field_renders_options_from_label_value_pairs(): void {
		$html = $this->renderer->render(
			'select',
			array(
				'slug'    => 'plan',
				'label'   => 'Plan',
				'options' => "Free Plan|free\nPro Plan|pro",
			)
		);

		$this->assertStringContainsString( '<option value="free">Free Plan</option>', $html );
		$this->assertStringContainsString( '<option value="pro">Pro Plan</option>', $html );
	}

	public function test_hidden_field_renders_bare_input_with_no_wrapper_chrome(): void {
		$html = $this->renderer->render(
			'hidden',
			array(
				'slug'  => 'source',
				'value' => 'newsletter',
			)
		);

		$this->assertStringContainsString( 'type="hidden"', $html );
		$this->assertStringContainsString( 'value="newsletter"', $html );
		$this->assertStringNotContainsString( 'swf-field__label', $html );
	}

	public function test_conditions_attribute_is_emitted_only_when_enabled_with_groups(): void {
		$html_disabled = $this->renderer->render(
			'text',
			array(
				'slug'       => 'a',
				'label'      => 'A',
				'conditions' => array(
					'enabled' => false,
					'action'  => 'show',
					'groups'  => array(),
				),
			)
		);

		$html_enabled = $this->renderer->render(
			'text',
			array(
				'slug'       => 'b',
				'label'      => 'B',
				'conditions' => array(
					'enabled' => true,
					'action'  => 'show',
					'groups'  => array(
						array(
							array(
								'field'    => 'a',
								'operator' => 'equals',
								'value'    => 'x',
							),
						),
					),
				),
			)
		);

		$this->assertStringNotContainsString( 'data-sf-conditions', $html_disabled );
		$this->assertStringContainsString( 'data-sf-conditions', $html_enabled );
	}

	public function test_field_html_type_filter_can_modify_output(): void {
		add_filter(
			'swf_field_html_text',
			static fn( string $html ) => $html . '<!-- injected -->'
		);

		$html = $this->renderer->render(
			'text',
			array(
				'slug'  => 'name',
				'label' => 'Name',
			)
		);

		$this->assertStringContainsString( '<!-- injected -->', $html );

		remove_all_filters( 'swf_field_html_text' );
	}

	public function test_unknown_type_renders_nothing(): void {
		$this->assertSame( '', $this->renderer->render( 'not-a-real-type', array() ) );
	}
}
