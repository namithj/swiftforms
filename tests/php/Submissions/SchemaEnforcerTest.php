<?php
/**
 * Tests for SwiftForms\Submissions\SchemaEnforcer.
 *
 * @package SwiftForms
 */

declare(strict_types=1);

namespace SwiftForms\Tests\Submissions;

use SwiftForms\Fields\FieldRegistry;
use SwiftForms\Submissions\SchemaEnforcer;
use SwiftForms\Tests\TestCase;

final class SchemaEnforcerTest extends TestCase {

	private SchemaEnforcer $enforcer;

	public function set_up(): void {
		parent::set_up();

		$registry = new FieldRegistry();
		$registry->load_types();

		$this->enforcer = new SchemaEnforcer( $registry );
	}

	public function test_rejects_unknown_form_id(): void {
		$result = $this->enforcer->enforce(
			array(
				'form_id' => 999999,
				'fields'  => array(),
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_form', $result->get_error_code() );
	}

	public function test_drops_slugs_not_present_in_the_stored_form(): void {
		$form_id = $this->create_form( '<!-- wp:swf/field-text {"slug":"name","label":"Name"} /-->' );

		$result = $this->enforcer->enforce(
			array(
				'form_id' => $form_id,
				'fields'  => array(
					array(
						'slug'  => 'name',
						'value' => 'Alice',
					),
					array(
						'slug'  => 'not_a_real_field_slug',
						'value' => 'malicious',
					),
				),
			)
		);

		$slugs = array_column( $result['fields'], 'slug' );

		$this->assertContains( 'name', $slugs );
		$this->assertNotContains( 'not_a_real_field_slug', $slugs );
	}

	public function test_client_supplied_required_flag_is_ignored_in_favor_of_stored_schema(): void {
		// The form declares `email` as required in its stored blocks; even
		// if a forged request tried to mark it optional, the enforced
		// field always carries the stored (required) attributes.
		$form_id = $this->create_form( '<!-- wp:swf/field-email {"slug":"email","label":"Email","required":true} /-->' );

		$result = $this->enforcer->enforce(
			array(
				'form_id' => $form_id,
				'fields'  => array(
					array(
						'slug'     => 'email',
						'value'    => '',
						'required' => false,
					),
				),
			)
		);

		$this->assertTrue( $result['fields'][0]['attributes']['required'] );
	}

	public function test_hidden_field_uses_its_stored_value(): void {
		$form_id = $this->create_form( '<!-- wp:swf/field-hidden {"slug":"source","value":"newsletter"} /-->' );

		$result = $this->enforcer->enforce(
			array(
				'form_id' => $form_id,
				'fields'  => array(
					array(
						'slug'  => 'source',
						'value' => 'forged-value',
					),
				),
			)
		);

		$this->assertSame( 'newsletter', $result['fields'][0]['value'] );
	}

	public function test_hidden_conditional_field_is_dropped_and_not_required(): void {
		$content = '<!-- wp:swf/field-select {"slug":"country","label":"Country","options":"US|us\nOther|other"} /-->'
			. '<!-- wp:swf/field-text {"slug":"state","label":"State","required":true,"conditions":{"enabled":true,"action":"show","groups":[[{"field":"country","operator":"equals","value":"us"}]]}} /-->';

		$form_id = $this->create_form( $content );

		$result = $this->enforcer->enforce(
			array(
				'form_id' => $form_id,
				'fields'  => array(
					array(
						'slug'  => 'country',
						'value' => 'other',
					),
					array(
						'slug'  => 'state',
						'value' => '',
					),
				),
			)
		);

		$slugs = array_column( $result['fields'], 'slug' );

		$this->assertNotContains( 'state', $slugs );
	}

	public function test_visible_conditional_field_is_still_enforced(): void {
		$content = '<!-- wp:swf/field-select {"slug":"country","label":"Country","options":"US|us\nOther|other"} /-->'
			. '<!-- wp:swf/field-text {"slug":"state","label":"State","required":true,"conditions":{"enabled":true,"action":"show","groups":[[{"field":"country","operator":"equals","value":"us"}]]}} /-->';

		$form_id = $this->create_form( $content );

		$result = $this->enforcer->enforce(
			array(
				'form_id' => $form_id,
				'fields'  => array(
					array(
						'slug'  => 'country',
						'value' => 'us',
					),
					array(
						'slug'  => 'state',
						'value' => 'NY',
					),
				),
			)
		);

		$slugs = array_column( $result['fields'], 'slug' );

		$this->assertContains( 'state', $slugs );
	}

	public function test_omitted_required_field_is_injected_as_empty(): void {
		$form_id = $this->create_form( '<!-- wp:swf/field-text {"slug":"name","label":"Name","required":true} /-->' );

		$result = $this->enforcer->enforce(
			array(
				'form_id' => $form_id,
				'fields'  => array(),
			)
		);

		$this->assertSame( 'name', $result['fields'][0]['slug'] );
		$this->assertSame( '', $result['fields'][0]['value'] );
	}
}
