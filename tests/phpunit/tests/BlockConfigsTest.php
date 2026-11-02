<?php
/**
 * Tests for the block registration (SwiftForms\Block_Configs through the block builder).
 *
 * @package SwiftForms
 */

use SwiftForms\Block_Configs;
use SwiftForms\Field_Types;
use SwiftForms\Plugin;

/**
 * Class BlockConfigsTest
 *
 * @group blocks
 */
class BlockConfigsTest extends WP_UnitTestCase {

	/**
	 * Every block name of C-2.1.
	 *
	 * @return string[]
	 */
	private function block_names() {
		$names = [ 'swiftforms/form', 'swiftforms/submit', 'swiftforms/confirmation' ];
		foreach ( Field_Types::types() as $type ) {
			$names[] = 'swiftforms/field-' . $type;
		}
		return $names;
	}

	/**
	 * Registered block type.
	 *
	 * @param string $name Block name.
	 * @return WP_Block_Type
	 */
	private function type( $name ) {
		$type = WP_Block_Type_Registry::get_instance()->get_registered( $name );
		$this->assertInstanceOf( WP_Block_Type::class, $type, $name . ' is registered.' );
		return $type;
	}

	/**
	 * Builder definition of a block, compiled without registering it.
	 *
	 * @param string $name Block name.
	 * @return object
	 */
	private function definition( $name ) {
		$class = '\SwiftForms\Vendor\PedalCMS\CassetteCMFBlocks\Core\Block_Definition';
		foreach ( Block_Configs::all()['blocks'] as $block ) {
			if ( $block['id'] === $name ) {
				return new $class( $block );
			}
		}
		$this->fail( 'No config for ' . $name );
	}

	/**
	 * Fifteen blocks, all dynamic, all with a render callback.
	 *
	 * @return void
	 */
	public function test_every_block_is_registered() {
		$this->assertCount( 15, $this->block_names() );
		foreach ( $this->block_names() as $name ) {
			$type = $this->type( $name );
			$this->assertTrue( $type->is_dynamic(), $name . ' is dynamic.' );
			$this->assertFalse( $type->supports['html'], $name . ' has no "Edit as HTML".' );
			$this->assertSame( 3, $type->api_version );
		}
		$configured = array_column( Block_Configs::all()['blocks'], 'id' );
		$this->assertSame( $this->block_names(), $configured, 'The configs list exactly these blocks, form first.' );
	}

	/**
	 * Every attribute of Field_Types exists on the registered block with the same type, default and enum (C-2.3 to C-2.5).
	 *
	 * @return void
	 */
	public function test_attributes_equal_field_types() {
		$expected = [
			'swiftforms/form'         => Field_Types::form_attributes(),
			'swiftforms/submit'       => Field_Types::submit_attributes(),
			'swiftforms/confirmation' => Field_Types::confirmation_attributes(),
		];
		foreach ( Field_Types::types() as $type ) {
			$expected[ Field_Types::block_name( $type ) ] = Field_Types::attributes( $type );
		}

		foreach ( $expected as $name => $attributes ) {
			$registered = $this->type( $name )->attributes;
			// WordPress adds lock and metadata, and the attributes of the block supports (class, colours, font size, anchor, style).
			$wordpress = [ 'lock', 'metadata', 'className', 'style', 'backgroundColor', 'textColor', 'fontSize', 'anchor', 'borderColor', 'gradient' ];
			$own       = array_diff_key( $registered, array_flip( $wordpress ) );
			$this->assertEqualsCanonicalizing( array_keys( $attributes ), array_keys( $own ), $name . ': exactly the attributes of Field_Types, none missing, none extra.' );

			foreach ( $attributes as $attribute => $definition ) {
				$this->assertSame( $definition['type'], $registered[ $attribute ]['type'], "$name.$attribute type" );
				$this->assertSame( $definition['default'], $registered[ $attribute ]['default'], "$name.$attribute default" );
				if ( isset( $definition['enum'] ) ) {
					$this->assertSame( $definition['enum'], $registered[ $attribute ]['enum'], "$name.$attribute enum" );
				} else {
					$this->assertArrayNotHasKey( 'enum', $registered[ $attribute ], "$name.$attribute has no enum" );
				}
			}
		}
	}

	/**
	 * Fields only live in a form, the confirmation is a direct child of one.
	 *
	 * @return void
	 */
	public function test_ancestors_and_parents() {
		foreach ( Field_Types::types() as $type ) {
			$this->assertSame( [ 'swiftforms/form' ], $this->type( 'swiftforms/field-' . $type )->ancestor, $type );
		}
		$this->assertSame( [ 'swiftforms/form' ], $this->type( 'swiftforms/submit' )->ancestor );
		$this->assertSame( [ 'swiftforms/form' ], $this->type( 'swiftforms/confirmation' )->parent );
		$this->assertNull( $this->type( 'swiftforms/form' )->ancestor );
	}

	/**
	 * Block context (BB-1): the form provides three values, every field reads them.
	 *
	 * @return void
	 */
	public function test_context() {
		$this->assertSame(
			[
				'swiftforms/formId'            => 'formId',
				'swiftforms/labelPosition'     => 'labelPosition',
				'swiftforms/requiredIndicator' => 'requiredIndicator',
			],
			$this->type( 'swiftforms/form' )->provides_context
		);
		foreach ( Field_Types::types() as $type ) {
			$uses = $this->type( 'swiftforms/field-' . $type )->uses_context;
			sort( $uses );
			$this->assertSame( [ 'swiftforms/formId', 'swiftforms/labelPosition', 'swiftforms/requiredIndicator' ], $uses, $type );
			$this->assertArrayNotHasKey( 'formId', $this->type( 'swiftforms/field-' . $type )->attributes, 'A context value is not stored as an attribute.' );
		}
	}

	/**
	 * Block supports of the form (C-2.3) and the interactivity support (ADR-002).
	 *
	 * @return void
	 */
	public function test_form_supports() {
		$supports = $this->type( 'swiftforms/form' )->supports;
		foreach ( Field_Types::form_supports() as $support ) {
			$this->assertArrayHasKey( $support, $supports, $support );
			$this->assertNotEmpty( $supports[ $support ], $support );
		}
		$this->assertTrue( $supports['interactivity'] );
		$this->assertNotEmpty( $this->type( 'swiftforms/field-text' )->supports['color'] );
	}

	/**
	 * Assets (ADR-002): view and editor handles only, never `style` (a classic theme would load it on every page).
	 *
	 * @return void
	 */
	public function test_asset_handles() {
		foreach ( $this->block_names() as $name ) {
			$this->assertSame( [], $this->type( $name )->style_handles, $name . ' has no style handle.' );
			$this->assertSame( [], $this->type( $name )->script_handles, $name . ' has no script handle.' );
			$this->assertSame( [], $this->type( $name )->view_script_handles, $name . ' has no view script.' );
		}

		$form = $this->type( 'swiftforms/form' );
		$this->assertSame( [ 'swiftforms-form' ], $form->view_style_handles );
		$this->assertSame( [ 'swiftforms-form', 'swiftforms-editor' ], $form->editor_style_handles );
		$this->assertSame( [ 'swiftforms-editor' ], $form->editor_script_handles );
		$this->assertSame( [ '@swiftforms/view' ], $form->view_script_module_ids );
	}

	/**
	 * Insert template, allowed children and categories.
	 *
	 * @return void
	 */
	public function test_form_template_and_children() {
		$template = Block_Configs::form_template();
		$this->assertSame(
			[ 'swiftforms/field-text', 'swiftforms/field-email', 'swiftforms/field-textarea', 'swiftforms/submit', 'swiftforms/confirmation' ],
			array_column( $template, 0 )
		);
		$this->assertSame(
			[
				'name'         => 'name',
				'label'        => 'Name',
				'autocomplete' => 'name',
			],
			$template[0][1]
		);
		$this->assertSame(
			[
				'name'         => 'email',
				'label'        => 'Email',
				'required'     => true,
				'autocomplete' => 'email',
			],
			$template[1][1]
		);
		$this->assertSame(
			[
				'name'     => 'message',
				'label'    => 'Message',
				'required' => true,
			],
			$template[2][1]
		);
		$this->assertSame( 'core/paragraph', $template[4][2][0][0] );
		$this->assertSame( "Thanks! We'll be in touch.", $template[4][2][0][1]['content'] );

		$allowed = Block_Configs::form_children();
		foreach ( Field_Types::types() as $type ) {
			$this->assertContains( 'swiftforms/field-' . $type, $allowed );
		}
		foreach ( [ 'swiftforms/submit', 'swiftforms/confirmation', 'core/columns', 'core/column', 'core/group', 'core/paragraph', 'core/heading', 'core/separator', 'core/spacer', 'core/image', 'core/list' ] as $block ) {
			$this->assertContains( $block, $allowed );
		}
		$this->assertCount( 23, $allowed );

		$config = Block_Configs::all();
		$this->assertSame( 'swiftforms', $config['block_categories'][0]['slug'] );
		$this->assertSame( 'swiftforms', $config['pattern_categories'][0]['slug'] );
		foreach ( $config['blocks'] as $block ) {
			$this->assertSame( 'swiftforms', $block['args']['category'], $block['id'] );
			$this->assertSame( 'dynamic', $block['render']['mode'] );
			$this->assertTrue( is_callable( $block['render']['callback'] ), $block['id'] );
		}
	}

	/**
	 * The internal filter wraps the configuration from the outside.
	 *
	 * @return void
	 */
	public function test_internal_filter_is_the_outermost_wrapper() {
		add_filter(
			'swiftforms_block_configs',
			static function ( $config ) {
				$config['marker'] = count( $config['blocks'] );
				return $config;
			}
		);
		$this->assertSame( 15, Plugin::instance()->block_configs()['marker'] );
	}

	/**
	 * Sample values for every attribute of a block, all different from the default and valid for the schema.
	 *
	 * @param array<string, array<string, mixed>> $attributes Definitions.
	 * @return array<string, mixed>
	 */
	private function sample_values( array $attributes ) {
		$values = [];
		foreach ( $attributes as $name => $definition ) {
			switch ( $definition['type'] ) {
				case 'string':
					$values[ $name ] = isset( $definition['enum'] ) ? end( $definition['enum'] ) : 'a,b|c \\d{3} \\\\ {field:x} ü';
					break;
				case 'boolean':
					$values[ $name ] = ! $definition['default'];
					break;
				case 'integer':
					$values[ $name ] = 7;
					break;
				case 'array':
					$values[ $name ] = 'notifications' === $name
						? [
							[
								'enabled' => true,
								'to'      => 'a@example.org, b@example.org',
								'cc'      => '',
								'bcc'     => '',
								'subject' => 'Hi {field:name}',
								'replyTo' => '{field:email}',
								'body'    => "Line 1\n\nLine 2",
								'format'  => 'plain',
							],
						]
						: [
							[
								'value' => 'a,b|c',
								'label' => 'A, B | C',
							],
							[
								'value' => '\\d+',
								'label' => '  spaced  ',
							],
						];
					break;
				case 'object':
					$values[ $name ] = [
						'enabled' => true,
						'toField' => 'email',
						'subject' => 'S',
						'body'    => 'B',
						'format'  => 'plain',
					];
					break;
			}
		}
		return $values;
	}

	/**
	 * WP_Block_Type::prepare_attributes_for_render() keeps every stored attribute of every block (C-2.6, S12).
	 *
	 * @return void
	 */
	public function test_prepare_attributes_keeps_every_attribute() {
		$expected = [
			'swiftforms/form'   => Field_Types::form_attributes(),
			'swiftforms/submit' => Field_Types::submit_attributes(),
		];
		foreach ( Field_Types::types() as $type ) {
			$expected[ Field_Types::block_name( $type ) ] = Field_Types::attributes( $type );
		}

		foreach ( $expected as $name => $attributes ) {
			$input    = $this->sample_values( $attributes );
			$prepared = $this->type( $name )->prepare_attributes_for_render( $input );
			foreach ( $input as $attribute => $value ) {
				$this->assertArrayHasKey( $attribute, $prepared, "$name.$attribute is kept." );
				$this->assertSame( $value, $prepared[ $attribute ], "$name.$attribute is unchanged." );
			}
		}

		// A stored form with a formId and nothing else keeps it and gets every default.
		$prepared = $this->type( 'swiftforms/form' )->prepare_attributes_for_render( [ 'formId' => '11111111-1111-4111-8111-111111111111' ] );
		$this->assertEquals( array_merge( Field_Types::form_defaults(), [ 'formId' => '11111111-1111-4111-8111-111111111111' ] ), array_intersect_key( $prepared, Field_Types::form_defaults() ) );
	}

	/**
	 * The builder's own sanitising pass (Block_Definition::sanitize_attributes) keeps the stored values that matter
	 * and is lossy for others. SwiftForms does not use it: the resolver and the renderers read the stored attributes.
	 *
	 * @return void
	 */
	public function test_builder_sanitiser_is_not_used_for_values_that_matter() {
		$kept = $this->definition( 'swiftforms/field-text' )->sanitize_attributes(
			[
				'pattern'        => '^\\d{3}\\\\w+$',
				'patternMessage' => 'Use 3 digits, then letters | digits',
				'placeholder'    => '{field:x} ü 日本語',
				'label'          => 'Naïve, café | bar',
			]
		);
		$this->assertSame( '^\\d{3}\\\\w+$', $kept['pattern'], 'Backslashes survive.' );
		$this->assertSame( 'Use 3 digits, then letters | digits', $kept['patternMessage'] );
		$this->assertSame( '{field:x} ü 日本語', $kept['placeholder'] );
		$this->assertSame( 'Naïve, café | bar', $kept['label'] );

		$options = $this->definition( 'swiftforms/field-select' )->sanitize_attributes(
			[
				'options' => [
					[
						'value' => 'a,b|c',
						'label' => 'A, B | C',
					],
				],
			]
		)['options'];
		$this->assertSame(
			[
				[
					'value' => 'a,b|c',
					'label' => 'A, B | C',
				],
			],
			$options,
			'Commas and pipes in option values survive.'
		);

		// What the builder's pass does change, which is why nothing of SwiftForms reads it.
		$lossy = $this->definition( 'swiftforms/field-text' )->sanitize_attributes(
			[
				'pattern' => '%41',
				'label'   => "two  spaces\nand <b>tags</b>",
			]
		);
		$this->assertSame( '', $lossy['pattern'], 'A %41 octet is stripped by sanitize_text_field.' );
		$this->assertSame( 'two spaces and tags', $lossy['label'] );

		$form = $this->definition( 'swiftforms/form' )->sanitize_attributes(
			[
				'notifications' => [
					[
						'enabled' => true,
						'to'      => 'a@example.org',
						'format'  => 'html',
					],
				],
			]
		);
		$this->assertArrayNotHasKey( 'enabled', $form['notifications'][0], 'The builder drops the enabled flag of a notification row.' );
	}

	/**
	 * The renderers show the stored value, not the builder's sanitised copy.
	 *
	 * @return void
	 */
	public function test_renderers_read_stored_attributes() {
		$html = do_blocks(
			'<!-- wp:swiftforms/form {"formId":"11111111-1111-4111-8111-111111111111"} -->'
			. '<!-- wp:swiftforms/field-text {"name":"x","pattern":"%41\\\\d","placeholder":"two  spaces"} /-->'
			. '<!-- /wp:swiftforms/form -->'
		);
		$this->assertStringContainsString( 'pattern="%41\\d"', $html );
		$this->assertStringContainsString( 'placeholder="two  spaces"', $html );
	}
}
