<?php
/**
 * Tests for SwiftForms\Schema_Resolver, Form_Schema and Schema_Store.
 *
 * @package SwiftForms
 */

require_once dirname( __DIR__ ) . '/includes/class-sf-resolver-test-case.php';

use SwiftForms\Database;
use SwiftForms\Form_Schema;
use SwiftForms\Schema_Resolver;
use SwiftForms\Schema_Store;

/**
 * Class SchemaResolverTest
 *
 * @group resolver
 */
class SchemaResolverTest extends SF_Resolver_Test_Case {

	/**
	 * Build a schema straight from markup (no source, no index).
	 *
	 * @param string $inner Form inner markup.
	 * @param array  $attrs Form attributes.
	 * @return Form_Schema
	 */
	private function build( $inner, array $attrs = [] ) {
		$blocks = parse_blocks( $this->form_markup( self::ID_A, $inner, $attrs ) );
		return Schema_Resolver::build( $blocks[0], self::ID_A, self::ID_A, 'post:1' );
	}

	/**
	 * Fields come out in document order, with defaults, from any depth and unknown wrappers.
	 *
	 * @return void
	 */
	public function test_fields_in_document_order_and_depth() {
		$schema = $this->build(
			$this->field_markup( 'text', [ 'name' => 'first' ] )
			. '<!-- wp:group --><div>'
			. $this->field_markup( 'email', [ 'name' => 'second' ] )
			. '<!-- wp:acme/future-wrapper --><!-- wp:columns --><!-- wp:column -->'
			. $this->field_markup( 'textarea', [ 'name' => 'third' ] )
			. '<!-- /wp:column --><!-- /wp:columns --><!-- /wp:acme/future-wrapper -->'
			. '</div><!-- /wp:group -->'
			. '<!-- wp:swiftforms/submit /-->'
			. $this->field_markup(
				'hidden',
				[
					'name'  => 'ref',
					'value' => '{post:id}',
				]
			)
		);

		$this->assertSame( [ 'first', 'second', 'third', 'ref' ], wp_list_pluck( $schema->fields, 'name' ) );
		$this->assertSame( [ 'text', 'email', 'textarea', 'hidden' ], wp_list_pluck( $schema->fields, 'type' ) );
		$this->assertFalse( $schema->fields[0]['required'] );
		$this->assertSame( '100', $schema->fields[0]['width'] );
		$this->assertSame( 4, $schema->fields[2]['rows'] );
		$this->assertSame( '{post:id}', $schema->fields[3]['value'], 'A hidden field keeps its value attribute (C-3.10).' );
		$this->assertSame( $schema->fields[1], $schema->field( 'second' ) );
		$this->assertNull( $schema->field( 'nope' ) );
	}

	/**
	 * Unknown attributes are ignored and wrong types fall back to defaults.
	 *
	 * @return void
	 */
	public function test_unknown_attributes_ignored() {
		$schema = $this->build(
			$this->field_markup(
				'text',
				[
					'name'      => 'who',
					'futureKey' => [ 'x' ],
					'maxLength' => 'lots',
					'required'  => 'yes',
				]
			)
		);
		$field  = $schema->fields[0];
		$this->assertArrayNotHasKey( 'futureKey', $field );
		$this->assertSame( 0, $field['maxLength'] );
		$this->assertFalse( $field['required'] );
		$this->assertSame( array_merge( [ 'type' ], array_keys( SwiftForms\Field_Types::attributes( 'text' ) ) ), array_keys( $field ) );
	}

	/**
	 * Duplicate names are renamed in document order; the first keeps its name (C-3.9).
	 *
	 * @return void
	 */
	public function test_duplicate_names_are_renamed() {
		$schema = $this->build(
			$this->field_markup( 'text', [ 'name' => 'email' ] )
			. $this->field_markup( 'email', [ 'name' => 'email' ] )
			. $this->field_markup( 'text', [ 'name' => 'email' ] )
			. $this->field_markup( 'text', [ 'name' => 'email-2' ] )
		);

		$this->assertSame( [ 'email', 'email-2', 'email-3', 'email-2-2' ], wp_list_pluck( $schema->fields, 'name' ) );
		$codes = wp_list_pluck( $schema->notices, 'code' );
		$this->assertSame( [ 'name_duplicate', 'name_duplicate', 'name_duplicate' ], $codes );
		$this->assertSame( 1, $schema->notices[0]['index'] );
		$this->assertSame( 'email-2', $schema->notices[0]['to'] );
	}

	/**
	 * A renamed name still fits in 64 characters.
	 *
	 * @return void
	 */
	public function test_long_duplicate_stays_within_the_limit() {
		$name   = str_repeat( 'a', 64 );
		$schema = $this->build( $this->field_markup( 'text', [ 'name' => $name ] ) . $this->field_markup( 'text', [ 'name' => $name ] ) );

		$this->assertSame( $name, $schema->fields[0]['name'] );
		$this->assertSame( str_repeat( 'a', 62 ) . '-2', $schema->fields[1]['name'] );
		foreach ( $schema->fields as $field ) {
			$this->assertTrue( SwiftForms\Field_Types::is_valid_name( $field['name'] ) );
		}
	}

	/**
	 * Invalid names are derived, never skipped: one schema field per field block.
	 *
	 * @return void
	 */
	public function test_invalid_names_are_derived() {
		$schema = $this->build(
			$this->field_markup( 'text', [ 'name' => 'Your Name' ] )
			. $this->field_markup( 'email' )
			. $this->field_markup( 'text', [ 'name' => '1st choice' ] )
			. $this->field_markup( 'text', [ 'name' => 'a.b' ] )
			. $this->field_markup( 'url', [ 'name' => '***' ] )
			. $this->field_markup( 'text', [ 'name' => 'Ünïcode' ] )
			. $this->field_markup( 'text', [ 'name' => str_repeat( 'x', 80 ) ] )
			. $this->field_markup( 'text', [ 'name' => 'your-name' ] )
		);

		$names = wp_list_pluck( $schema->fields, 'name' );
		$this->assertCount( 8, $names );
		$this->assertSame(
			[ 'your-name', 'email', 'f-1st-choice', 'a-b', 'url', 'n-code', str_repeat( 'x', 64 ), 'your-name-2' ],
			$names
		);
		foreach ( $names as $name ) {
			$this->assertTrue( SwiftForms\Field_Types::is_valid_name( $name ), $name );
		}
		$this->assertSame( 'name_derived', $schema->notices[0]['code'] );
		$this->assertSame( 'Your Name', $schema->notices[0]['from'] );
		$this->assertSame( 'your-name', $schema->notices[0]['to'] );
		$this->assertSame( 'a-b', Schema_Resolver::derive_name( 'a.b', 'text' ) );
		$this->assertSame( 'consent', Schema_Resolver::derive_name( '', 'consent' ) );
	}

	/**
	 * Choice options are cleaned to { value, label } strings.
	 *
	 * @return void
	 */
	public function test_options_are_normalised() {
		$schema = $this->build(
			$this->field_markup(
				'select',
				[
					'name'    => 'topic',
					'options' => [
						[
							'value' => 'a',
							'label' => 'Alpha',
						],
						[ 'label' => 'Only label' ],
						[ 'value' => 'only-value' ],
						[
							'value' => 5,
							'label' => 'Five',
						],
						'plain',
						[],
						[
							'value' => '',
							'label' => '',
						],
						42,
						[
							'value' => [ 'x' ],
							'label' => 'Bad value',
						],
					],
				]
			)
		);

		$this->assertSame(
			[
				[
					'value' => 'a',
					'label' => 'Alpha',
				],
				[
					'value' => 'Only label',
					'label' => 'Only label',
				],
				[
					'value' => 'only-value',
					'label' => 'only-value',
				],
				[
					'value' => '5',
					'label' => 'Five',
				],
				[
					'value' => 'plain',
					'label' => 'plain',
				],
				[
					'value' => '42',
					'label' => '42',
				],
				[
					'value' => 'Bad value',
					'label' => 'Bad value',
				],
			],
			$schema->fields[0]['options']
		);
	}

	/**
	 * Form attributes get their defaults and the confirmation's inner blocks ride along.
	 *
	 * @return void
	 */
	public function test_form_attributes_and_confirmation() {
		$schema = $this->build(
			$this->field_markup( 'text', [ 'name' => 'a' ] )
			. '<!-- wp:swiftforms/confirmation --><!-- wp:paragraph --><p>Thanks</p><!-- /wp:paragraph --><!-- /wp:swiftforms/confirmation -->',
			[
				'title' => 'Contact',
				'ajax'  => false,
			]
		);

		$this->assertSame( 'Contact', $schema->title() );
		$this->assertFalse( $schema->attributes['ajax'] );
		$this->assertSame( 'top', $schema->attributes['labelPosition'] );
		$this->assertSame( 'html', $schema->attributes['autoresponder']['format'] );
		$this->assertCount( 1, $schema->confirmation_blocks );
		$this->assertSame( 'core/paragraph', $schema->confirmation_blocks[0]['blockName'] );
		$this->assertCount( 1, $schema->fields, 'Blocks inside the confirmation are not fields.' );
		$this->assertSame( self::ID_A, $schema->stored_id );
		$this->assertSame( 'post:1', $schema->source );
	}

	/**
	 * A nested form is its own form: its fields are not the outer form's.
	 *
	 * @return void
	 */
	public function test_nested_form_fields_are_not_collected() {
		$schema = $this->build(
			$this->field_markup( 'text', [ 'name' => 'outer' ] )
			. $this->form_markup( self::ID_B, $this->field_markup( 'text', [ 'name' => 'inner' ] ) )
		);
		$this->assertSame( [ 'outer' ], wp_list_pluck( $schema->fields, 'name' ) );
	}

	/**
	 * The hash ignores attribute order in the stored markup and anything outside the field list; any field change moves it.
	 *
	 * @return void
	 */
	public function test_hash_stability() {
		$a = $this->build(
			$this->field_markup(
				'text',
				[
					'name'     => 'who',
					'label'    => 'Who',
					'required' => true,
				]
			) . $this->field_markup(
				'email',
				[ 'name' => 'mail' ]
			)
		);
		$b = $this->build(
			$this->field_markup(
				'text',
				[
					'required' => true,
					'label'    => 'Who',
					'name'     => 'who',
				]
			) . $this->field_markup(
				'email',
				[
					'name'      => 'mail',
					'futureKey' => 1,
				]
			)
		);
		$this->assertSame( $a->hash, $b->hash, 'Attribute order and unknown attributes do not change the hash.' );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $a->hash );

		$c = $this->build(
			$this->field_markup(
				'text',
				[
					'name'     => 'who',
					'label'    => 'Who',
					'required' => true,
				]
			) . $this->field_markup( 'email', [ 'name' => 'mail' ] ),
			[
				'title'         => 'Other',
				'ajax'          => false,
				'labelPosition' => 'inline',
			]
		);
		$this->assertSame( $a->hash, $c->hash, 'Form attributes are not part of the hash.' );

		$changes = [
			'label'       => [
				'name'     => 'who',
				'label'    => 'Who?',
				'required' => true,
			],
			'required'    => [
				'name'     => 'who',
				'label'    => 'Who',
				'required' => false,
			],
			'width'       => [
				'name'     => 'who',
				'label'    => 'Who',
				'required' => true,
				'width'    => '50',
			],
			'placeholder' => [
				'name'        => 'who',
				'label'       => 'Who',
				'required'    => true,
				'placeholder' => 'p',
			],
			'pattern'     => [
				'name'     => 'who',
				'label'    => 'Who',
				'required' => true,
				'pattern'  => '^a$',
			],
		];
		foreach ( $changes as $what => $attrs ) {
			$d = $this->build( $this->field_markup( 'text', $attrs ) . $this->field_markup( 'email', [ 'name' => 'mail' ] ) );
			$this->assertNotSame( $a->hash, $d->hash, $what );
		}

		$reordered = $this->build(
			$this->field_markup( 'email', [ 'name' => 'mail' ] ) . $this->field_markup(
				'text',
				[
					'name'     => 'who',
					'label'    => 'Who',
					'required' => true,
				]
			)
		);
		$this->assertNotSame( $a->hash, $reordered->hash, 'Order matters.' );

		$more = $this->build(
			$this->field_markup(
				'text',
				[
					'name'     => 'who',
					'label'    => 'Who',
					'required' => true,
				]
			) . $this->field_markup(
				'email',
				[ 'name' => 'mail' ]
			) . $this->field_markup(
				'tel',
				[ 'name' => 'phone' ]
			)
		);
		$this->assertNotSame( $a->hash, $more->hash );

		$option_a = $this->build(
			$this->field_markup(
				'radio',
				[
					'name'    => 'r',
					'options' => [
						[
							'value' => 'a',
							'label' => 'A',
						],
					],
				]
			)
		);
		$option_b = $this->build(
			$this->field_markup(
				'radio',
				[
					'name'    => 'r',
					'options' => [
						[
							'label' => 'A',
							'value' => 'a',
						],
					],
				]
			)
		);
		$option_c = $this->build(
			$this->field_markup(
				'radio',
				[
					'name'    => 'r',
					'options' => [
						[
							'value' => 'a',
							'label' => 'AA',
						],
					],
				]
			)
		);
		$this->assertSame( $option_a->hash, $option_b->hash, 'Key order inside options does not matter either.' );
		$this->assertNotSame( $option_a->hash, $option_c->hash );
	}

	/**
	 * The canonical JSON sorts keys, keeps lists and leaves slashes and Unicode alone.
	 *
	 * @return void
	 */
	public function test_canonical_json() {
		$this->assertSame(
			'{"a":1,"b":{"c":[3,1,2],"d":"é/x"}}',
			Form_Schema::canonical_json(
				[
					'b' => [
						'd' => 'é/x',
						'c' => [ 3, 1, 2 ],
					],
					'a' => 1,
				]
			)
		);
		$this->assertSame( '[]', Form_Schema::canonical_json( [] ) );
	}

	/**
	 * A snapshot round-trips: same fields, same hash.
	 *
	 * @return void
	 */
	public function test_snapshot_round_trip() {
		$schema = $this->build(
			$this->field_markup(
				'select',
				[
					'name'     => 's',
					'options'  => [
						[
							'value' => 'é',
							'label' => 'É/é',
						],
					],
					'multiple' => true,
				]
			)
			. $this->field_markup(
				'hidden',
				[
					'name'  => 'h',
					'value' => '{user:id}',
				]
			)
			. $this->field_markup(
				'consent',
				[
					'name' => 'c',
					'text' => 'I agree',
				]
			),
			[ 'title' => 'Snap' ]
		);

		$json = wp_json_encode( $schema->to_snapshot() );
		$back = Form_Schema::from_snapshot( $json );
		$this->assertSame( $schema->fields, $back->fields );
		$this->assertSame( $schema->hash, $back->hash );
		$this->assertSame( 'Snap', $back->title() );
		$this->assertSame( self::ID_A, $back->form_id );

		$this->assertNull( Form_Schema::from_snapshot( 'not json' ) );
		$this->assertNull( Form_Schema::from_snapshot( [ 'fields' => 'no' ] ) );
		$this->assertNull( Form_Schema::from_snapshot( [] ) );
	}

	/**
	 * The store keeps the first snapshot of a hash and never changes it.
	 *
	 * @return void
	 */
	public function test_store_inserts_once() {
		global $wpdb;

		$schema = $this->build( $this->field_markup( 'text', [ 'name' => 'who' ] ) );
		$this->assertNull( Schema_Store::get( $schema->hash ) );
		$this->assertFalse( Schema_Store::has( $schema->hash ) );

		$this->assertTrue( Schema_Store::save( $schema ) );
		$this->assertFalse( Schema_Store::save( $schema ), 'The second save inserts nothing.' );

		$other          = clone $schema;
		$other->form_id = self::ID_B;
		$this->assertFalse( Schema_Store::save( $other ), 'The same hash from another form does not replace the first row.' );

		$table = Database::table( 'schemas' );
		$this->assertSame( 1, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM `' . $table . '` WHERE schema_hash = %s', $schema->hash ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$this->assertSame( self::ID_A, $wpdb->get_var( $wpdb->prepare( 'SELECT form_id FROM `' . $table . '` WHERE schema_hash = %s', $schema->hash ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared

		wp_cache_flush();
		$back = Schema_Store::get( $schema->hash );
		$this->assertSame( $schema->fields, $back->fields );
		$this->assertSame( $schema->hash, $back->hash );

		$this->assertNull( Schema_Store::get( 'zz' ) );
		$this->assertNull( Schema_Store::get( str_repeat( 'a', 64 ) ) );
		$this->assertFalse( Schema_Store::is_hash( strtoupper( $schema->hash ) ) );
	}

	/**
	 * Resolution by effective ID for a post, a synced pattern, templates, parts, patterns and widgets.
	 *
	 * @return void
	 */
	public function test_resolve_for_each_source_type() {
		$form = function ( $id, $name ) {
			return $this->form_markup( $id, $this->field_markup( 'text', [ 'name' => $name ] ) );
		};

		$this->make_theme(
			'sf-r1',
			[
				'templates/single.html' => $form( self::ID_A, 'in-file-template' ),
				'parts/footer.html'     => $form( self::ID_B, 'in-file-part' ),
			]
		);
		$page   = $this->make_post( $form( self::ID_C, 'in-page' ) );
		$synced = $this->make_post( $form( '44444444-4444-4444-8444-444444444444', 'in-synced' ), [ 'post_type' => 'wp_block' ] );
		$dbtpl  = $this->make_template_post( 'wp_template', 'sf-r1', 'page', $form( '55555555-5555-4555-8555-555555555555', 'in-db-template' ) );
		$this->register_pattern( 'sf/p', $form( '66666666-6666-4666-8666-666666666666', 'in-pattern' ) );
		$this->place_widget( 9, $form( '77777777-7777-4777-8777-777777777777', 'in-widget' ) );

		$cases = [
			[ self::ID_A, 'template:sf-r1//single', 'in-file-template' ],
			[ self::ID_B, 'template_part:sf-r1//footer', 'in-file-part' ],
			[ self::ID_C, 'post:' . $page, 'in-page' ],
			[ '44444444-4444-4444-8444-444444444444', 'post:' . $synced, 'in-synced' ],
			[ '55555555-5555-4555-8555-555555555555', 'template:sf-r1//page', 'in-db-template' ],
			[ '66666666-6666-4666-8666-666666666666', 'pattern:sf/p', 'in-pattern' ],
			[ '77777777-7777-4777-8777-777777777777', 'widget:block-9', 'in-widget' ],
		];
		foreach ( $cases as $case ) {
			$schema = Schema_Resolver::resolve( $case[0], $case[1] );
			$this->assertNotNull( $schema, $case[1] );
			$this->assertSame( $case[2], $schema->fields[0]['name'], $case[1] );
			$this->assertSame( $case[0], $schema->form_id );
			$this->assertSame( $case[1], $schema->source );
		}
		$this->assertGreaterThan( 0, $dbtpl );
	}

	/**
	 * A request cannot make the resolver return another source's form or an unknown one.
	 *
	 * @return void
	 */
	public function test_resolve_rejects_mismatches() {
		$page  = $this->make_post( $this->form_markup( self::ID_A, $this->field_markup( 'text', [ 'name' => 'a' ] ) ) );
		$other = $this->make_post( 'no form here' );

		$this->assertNotNull( Schema_Resolver::resolve( self::ID_A, 'post:' . $page ) );
		$this->assertNull( Schema_Resolver::resolve( self::ID_A, 'post:' . $other ) );
		$this->assertNull( Schema_Resolver::resolve( self::ID_B, 'post:' . $page ) );
		$this->assertNull( Schema_Resolver::resolve( self::ID_A, 'post:99999' ) );
		$this->assertNull( Schema_Resolver::resolve( 'not-a-uuid', 'post:' . $page ) );
		$this->assertNull( Schema_Resolver::resolve( self::ID_A, 'garbage' ) );
		$this->assertNull( Schema_Resolver::resolve( self::ID_A, 'template:other//single' ) );
	}

	/**
	 * The schema comes from stored content: a copy and a block without an ID resolve by their effective IDs.
	 *
	 * @return void
	 */
	public function test_resolve_by_effective_id() {
		$first  = $this->make_post( $this->form_markup( self::ID_A, $this->field_markup( 'text', [ 'name' => 'orig' ] ) ) );
		$second = $this->make_post( $this->form_markup( self::ID_A, $this->field_markup( 'text', [ 'name' => 'copy' ] ) ) );
		$third  = $this->make_post( $this->form_markup( '', $this->field_markup( 'text', [ 'name' => 'nameless' ] ) ) );

		$copy_id    = SwiftForms\Form_Index::copy_id( self::ID_A, 'post:' . $second );
		$missing_id = SwiftForms\Uuid::v5( 'missing|post:' . $third . '|1' );

		$this->assertSame( 'orig', Schema_Resolver::resolve( self::ID_A, 'post:' . $first )->fields[0]['name'] );
		$this->assertNull( Schema_Resolver::resolve( self::ID_A, 'post:' . $second ), 'The stored ID is not the copy\'s effective ID.' );
		$copy = Schema_Resolver::resolve( $copy_id, 'post:' . $second );
		$this->assertSame( 'copy', $copy->fields[0]['name'] );
		$this->assertSame( self::ID_A, $copy->stored_id );
		$this->assertSame( 'nameless', Schema_Resolver::resolve( $missing_id, 'post:' . $third )->fields[0]['name'] );
		$this->assertSame( '', Schema_Resolver::resolve( $missing_id, 'post:' . $third )->stored_id );
	}
}
