<?php
/**
 * Tests for the form template block patterns.
 */

declare(strict_types=1);

class SwiftForms_Templates_Test extends WP_UnitTestCase {
	public function test_register_adds_pattern_category(): void {
		( new SwiftForms_Templates() )->register();

		$this->assertTrue( WP_Block_Pattern_Categories_Registry::get_instance()->is_registered( SwiftForms_Templates::CATEGORY ) );
	}

	public function test_register_registers_four_patterns_scoped_to_forms(): void {
		( new SwiftForms_Templates() )->register();

		$registry = WP_Block_Patterns_Registry::get_instance();

		foreach ( array( 'contact-form', 'quote-request', 'feedback-survey', 'event-registration' ) as $slug ) {
			$this->assertTrue( $registry->is_registered( 'swiftforms/' . $slug ), $slug . ' must be registered' );

			$pattern = $registry->get_registered( 'swiftforms/' . $slug );
			$this->assertSame( array( SwiftForms_CPTs::FORM_POST_TYPE ), $pattern['postTypes'] );
		}
	}

	public function test_template_content_parses_into_form_builder_blocks(): void {
		$allowed = array(
			'swiftforms/checkbox-field',
			'swiftforms/date-field',
			'swiftforms/email-field',
			'swiftforms/file-field',
			'swiftforms/hidden-field',
			'swiftforms/number-field',
			'swiftforms/radio-field',
			'swiftforms/select-field',
			'swiftforms/step',
			'swiftforms/tel-field',
			'swiftforms/text-field',
			'swiftforms/textarea-field',
			'swiftforms/url-field',
		);

		$check = static function ( array $blocks, string $slug ) use ( &$check, $allowed ): void {
			foreach ( $blocks as $block ) {
				$name = (string) ( $block['blockName'] ?? '' );

				if ( '' === $name ) {
					continue; // Whitespace-only freeform chunks between blocks.
				}

				self::assertContains( $name, $allowed, "$slug uses unexpected block $name" );

				if ( ! empty( $block['innerBlocks'] ) ) {
					$check( $block['innerBlocks'], $slug );
				}
			}
		};

		$templates = ( new SwiftForms_Templates() )->get_templates();
		$this->assertCount( 4, $templates );

		foreach ( $templates as $slug => $template ) {
			$check( parse_blocks( $template['content'] ), $slug );
		}
	}

	public function test_event_registration_template_produces_valid_schema(): void {
		$templates = ( new SwiftForms_Templates() )->get_templates();

		$form_id = self::factory()->post->create(
			array(
				'post_content' => wp_slash( $templates['event-registration']['content'] ),
				'post_type'    => SwiftForms_CPTs::FORM_POST_TYPE,
			)
		);

		$schema = SwiftForms_CPTs::get_form_field_schema( $form_id );

		$this->assertSame( array( 'full_name', 'email', 'attendance_date', 'meal', 'dietary_notes' ), array_keys( $schema ) );
		$this->assertSame( 'radio', $schema['meal']['type'] );
		$this->assertNotEmpty( $schema['dietary_notes']['conditions'], 'The conditional showcase field must carry sanitized conditions.' );
	}
}
