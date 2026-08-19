<?php
/**
 * Tests for SwiftForms\Fields\FormSchema.
 *
 * @package SwiftForms
 */

declare(strict_types=1);

namespace SwiftForms\Tests\Fields;

use SwiftForms\Fields\FormSchema;
use SwiftForms\Tests\TestCase;

final class FormSchemaTest extends TestCase {

	public function test_collects_fields_in_document_order(): void {
		$content = '<!-- wp:swf/field-text {"slug":"name","label":"Name"} /-->'
			. '<!-- wp:swf/field-email {"slug":"email","label":"Email"} /-->';

		$form_id = $this->create_form( $content );

		$schema = FormSchema::for_form( $form_id );

		$this->assertSame( array( 'name', 'email' ), array_keys( $schema ) );
		$this->assertSame( 'text', $schema['name']['type'] );
		$this->assertSame( 'email', $schema['email']['type'] );
	}

	public function test_collects_fields_nested_inside_a_step(): void {
		$content = '<!-- wp:swf/step {"title":"Step 1"} -->'
			. '<!-- wp:swf/field-text {"slug":"name","label":"Name"} /-->'
			. '<!-- /wp:swf/step -->';

		$form_id = $this->create_form( $content );

		$schema = FormSchema::for_form( $form_id );

		$this->assertArrayHasKey( 'name', $schema );
	}

	public function test_collects_fields_nested_inside_groups_and_columns(): void {
		$content = '<!-- wp:core/columns -->'
			. '<!-- wp:core/column -->'
			. '<!-- wp:swf/field-text {"slug":"name","label":"Name"} /-->'
			. '<!-- /wp:core/column -->'
			. '<!-- /wp:core/columns -->';

		$form_id = $this->create_form( $content );

		$schema = FormSchema::for_form( $form_id );

		$this->assertArrayHasKey( 'name', $schema );
	}

	public function test_duplicate_slug_last_one_wins(): void {
		$content = '<!-- wp:swf/field-text {"slug":"x","label":"First"} /-->'
			. '<!-- wp:swf/field-email {"slug":"x","label":"Second"} /-->';

		$form_id = $this->create_form( $content );

		$schema = FormSchema::for_form( $form_id );

		$this->assertCount( 1, $schema );
		$this->assertSame( 'email', $schema['x']['type'] );
	}

	public function test_returns_empty_array_for_unknown_form(): void {
		$this->assertSame( array(), FormSchema::for_form( 999999 ) );
	}
}
