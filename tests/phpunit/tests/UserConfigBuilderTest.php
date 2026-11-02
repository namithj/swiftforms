<?php
require_once dirname( __DIR__, 2 ) . '/user-configs/fixtures/builder.php';
require_once dirname( __DIR__, 2 ) . '/user-configs/fixtures/blocks.php';

/**
 * The block-markup builders of the user configs write what the hand-written fixtures contain.
 */
class SwiftForms_User_Config_Builder_Test extends WP_UnitTestCase {

	public function test_builder_reproduces_the_hand_written_minimal_contact_form() {
		$built = SF_FORM(
			[
				'formId' => '5f020001-0000-4000-8000-000000000001',
				'title'  => 'Contact us',
			],
			[
				SF_FIELD(
					'text',
					[
						'label'        => 'Your name',
						'name'         => 'name',
						'required'     => true,
						'autocomplete' => 'name',
					]
				),
				SF_FIELD(
					'email',
					[
						'label'        => 'Email',
						'name'         => 'email',
						'required'     => true,
						'autocomplete' => 'email',
					]
				),
				SF_FIELD(
					'textarea',
					[
						'label'    => 'How can we help?',
						'name'     => 'message',
						'required' => true,
					]
				),
			],
			'Send message',
			'Thank you. We reply within one working day.'
		);
		$this->assertSame( SF_FX( 'form-minimal-contact' ), $built );
	}

	public function test_attributes_are_escaped_the_way_the_editor_escapes_them() {
		$expected = <<<'HTML'
<!-- wp:swiftforms/field-text {"label":"a \u003cb\u003e \u0026 \u0022q\u0022 \u002d\u002d"} /-->
HTML;
		$this->assertSame( $expected, SF_FIELD( 'text', [ 'label' => 'a <b> & "q" --' ] ) );
	}
}
