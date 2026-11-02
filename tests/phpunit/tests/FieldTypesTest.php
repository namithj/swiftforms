<?php // phpcs:disable PHPCompatibility.Extensions.RemovedExtensions.famRemoved -- False positive: Field_Types::family() is not the FAM extension.
/**
 * Tests for SwiftForms\Field_Types, checked against the contract text.
 *
 * @package SwiftForms
 */

use SwiftForms\Field_Types;

/**
 * Class FieldTypesTest
 *
 * @group resolver
 */
class FieldTypesTest extends WP_UnitTestCase {

	/**
	 * Contract C-2 text, or null when the .ai folder is not shipped with the checkout.
	 *
	 * @return string
	 */
	private function contract() {
		$file = dirname( __DIR__, 3 ) . '/.ai/compat/data-contract.md';
		if ( ! is_readable( $file ) ) {
			$this->markTestSkipped( 'The contract file is not available.' );
		}
		$text  = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$start = strpos( $text, '## C-2 Blocks' );
		$end   = strpos( $text, '## C-3 Submissions' );
		return substr( $text, $start, $end - $start );
	}

	/**
	 * The twelve types are the ones C-2.1 names, in a stable order.
	 *
	 * @return void
	 */
	public function test_types_match_c_2_1() {
		$contract = $this->contract();
		$this->assertSame( 1, preg_match( '/`swiftforms\/field-\{([a-z,]+)\}`/', $contract, $m ) );
		$expected = explode( ',', $m[1] );

		$types = Field_Types::types();
		$this->assertCount( 12, $types );
		$this->assertSame( [], array_diff( $expected, $types ) );
		$this->assertSame( [], array_diff( $types, $expected ) );

		foreach ( $types as $type ) {
			$this->assertTrue( Field_Types::is_type( $type ) );
			$this->assertSame( 'swiftforms/field-' . $type, Field_Types::block_name( $type ) );
			$this->assertSame( $type, Field_Types::type_from_block_name( 'swiftforms/field-' . $type ) );
		}
		$this->assertFalse( Field_Types::is_type( 'password' ) );
		$this->assertNull( Field_Types::type_from_block_name( 'swiftforms/field-password' ) );
		$this->assertNull( Field_Types::type_from_block_name( 'swiftforms/form' ) );
		$this->assertNull( Field_Types::type_from_block_name( null ) );
		$this->assertSame( '', Field_Types::block_name( 'nope' ) );
	}

	/**
	 * Form attributes are exactly the C-2.3 list, with the contract's defaults.
	 *
	 * @return void
	 */
	public function test_form_attributes_match_c_2_3() {
		$contract = $this->contract();
		$start    = strpos( $contract, '3. `swiftforms/form` attributes' );
		$end      = strpos( $contract, 'block supports', $start );
		$names    = [];
		preg_match_all( '/^\s+- (?:Since [0-9.]+: )?`([A-Za-z]+)`:/m', substr( $contract, $start, strpos( $contract, '4. Common field attributes' ) - $start ), $m );
		$names = $m[1];
		$this->assertContains( 'formId', $names );
		$this->assertContains( 'autoresponder', $names );
		$this->assertGreaterThan( $start, $end );

		$attributes = Field_Types::form_attributes();
		$this->assertSame( [], array_diff( $names, array_keys( $attributes ) ) );
		$this->assertSame( [], array_diff( array_keys( $attributes ), $names ) );

		$defaults = Field_Types::form_defaults();
		$this->assertSame( 'message', $defaults['confirmationType'] );
		$this->assertSame( 0, $defaults['redirectPageId'] );
		$this->assertTrue( $defaults['useDefaultNotification'] );
		$this->assertSame( [], $defaults['notifications'] );
		$this->assertSame( 'default', $defaults['storeEntries'] );
		$this->assertSame( 'default', $defaults['spamProtection'] );
		$this->assertSame( 'top', $defaults['labelPosition'] );
		$this->assertSame( 'asterisk', $defaults['requiredIndicator'] );
		$this->assertTrue( $defaults['ajax'] );
		$this->assertSame(
			[
				'enabled' => false,
				'toField' => '',
				'subject' => '',
				'body'    => '',
				'format'  => 'html',
			],
			$defaults['autoresponder']
		);
		$this->assertSame( [ 'message', 'redirect' ], $attributes['confirmationType']['enum'] );
		$this->assertSame( [ 'default', 'yes', 'no' ], $attributes['storeEntries']['enum'] );
		$this->assertSame( [ 'default', 'off' ], $attributes['spamProtection']['enum'] );
		$this->assertSame( [ 'top', 'inline', 'hidden' ], $attributes['labelPosition']['enum'] );
		$this->assertSame( [ 'asterisk', 'text', 'none' ], $attributes['requiredIndicator']['enum'] );
		$this->assertSame( [ 'color', 'spacing', 'typography', 'border', 'className', 'anchor' ], Field_Types::form_supports() );
	}

	/**
	 * Common and type-specific attributes are exactly the C-2.4 lists.
	 *
	 * @return void
	 */
	public function test_field_attributes_match_c_2_4() {
		$contract = $this->contract();
		$start    = strpos( $contract, '4. Common field attributes' );
		$end      = strpos( $contract, '5. `swiftforms/submit`' );
		$section  = substr( $contract, $start, $end - $start );

		// Common list: the bullets before "Type-specific attributes".
		$common_text = substr( $section, 0, strpos( $section, 'Type-specific attributes' ) );
		preg_match_all( '/^\s+- ((?:`[A-Za-z]+`(?:, )?)+)/m', $common_text, $lines );
		preg_match_all( '/`([A-Za-z]+)`/', implode( ' ', $lines[1] ), $m );
		$common = $m[1];
		$this->assertCount( 8, $common );
		$this->assertSame( [], array_diff( $common, array_keys( Field_Types::common_attributes() ) ) );
		$this->assertSame( [], array_diff( array_keys( Field_Types::common_attributes() ), $common ) );

		// Type-specific: "- text: `minLength`, `maxLength`".
		$specific_text = substr( $section, strpos( $section, 'Type-specific attributes' ) );
		preg_match_all( '/^\s+- ([a-z ]+(?:and [a-z]+)?): (.+)$/m', $specific_text, $lines, PREG_SET_ORDER );
		$this->assertNotEmpty( $lines );
		$seen = [];
		foreach ( $lines as $line ) {
			preg_match_all( '/`([A-Za-z]+)`/', preg_replace( '/\([^)]*\)/', '', $line[2] ), $names ); // Enum values in parentheses are not attributes.
			$types = preg_split( '/ and /', $line[1] );
			foreach ( $types as $type ) {
				$seen[ $type ] = array_merge( $seen[ $type ] ?? [], $names[1] );
			}
		}
		foreach ( Field_Types::types() as $type ) {
			$wanted = array_merge( array_keys( Field_Types::common_attributes() ), $seen[ $type ] ?? [] );
			$this->assertSame( [], array_diff( $wanted, array_keys( Field_Types::attributes( $type ) ) ), $type );
			$this->assertSame( [], array_diff( array_keys( Field_Types::attributes( $type ) ), $wanted ), $type );
		}
		$this->assertSame( [ 'min', 'max' ], $seen['date'] );
	}

	/**
	 * Defaults of the contract: required false (consent true), width 100, layout stack.
	 *
	 * @return void
	 */
	public function test_field_defaults() {
		foreach ( Field_Types::types() as $type ) {
			$defaults = Field_Types::defaults( $type );
			$this->assertSame( 'consent' === $type, $defaults['required'], $type );
			$this->assertSame( '100', $defaults['width'], $type );
			$this->assertSame( '', $defaults['name'], $type );
			$this->assertSame( '', $defaults['autocomplete'], $type );
		}

		$this->assertSame( [ '100', '75', '66', '50', '33', '25' ], Field_Types::attributes( 'text' )['width']['enum'] );
		$this->assertSame( 'stack', Field_Types::defaults( 'radio' )['layout'] );
		$this->assertSame( 'stack', Field_Types::defaults( 'checkboxes' )['layout'] );
		$this->assertSame( [ 'stack', 'inline' ], Field_Types::attributes( 'radio' )['layout']['enum'] );
		$this->assertSame( [], Field_Types::defaults( 'select' )['options'] );
		$this->assertFalse( Field_Types::defaults( 'select' )['multiple'] );
		$this->assertSame( 4, Field_Types::defaults( 'textarea' )['rows'] );
		$this->assertSame( [], Field_Types::attributes( 'nope' ) );
		$this->assertSame( 'Submit', Field_Types::submit_attributes()['label']['default'] );
		$this->assertSame( [], Field_Types::confirmation_attributes() );
	}

	/**
	 * Families, HTML types and multi-value rules.
	 *
	 * @return void
	 */
	public function test_families_and_multi() {
		foreach ( [ 'text', 'email', 'number', 'url', 'tel', 'date' ] as $type ) {
			$this->assertSame( 'input', Field_Types::family( $type ) );
			$this->assertSame( $type, Field_Types::html_type( $type ) );
		}
		$this->assertSame( 'textarea', Field_Types::family( 'textarea' ) );
		$this->assertSame( 'select', Field_Types::family( 'select' ) );
		$this->assertSame( 'choice', Field_Types::family( 'radio' ) );
		$this->assertSame( 'choice', Field_Types::family( 'checkboxes' ) );
		$this->assertSame( 'consent', Field_Types::family( 'consent' ) );
		$this->assertSame( 'hidden', Field_Types::family( 'hidden' ) );
		$this->assertSame( '', Field_Types::family( 'nope' ) );

		$this->assertTrue( Field_Types::is_multi( 'checkboxes' ) );
		$this->assertFalse( Field_Types::is_multi( 'radio' ) );
		$this->assertFalse( Field_Types::is_multi( 'select' ) );
		$this->assertTrue( Field_Types::is_multi( 'select', [ 'multiple' => true ] ) );
		$this->assertFalse( Field_Types::is_multi( 'select', [ 'multiple' => 'yes' ] ) );
		$this->assertFalse( Field_Types::is_multi( 'text', [ 'multiple' => true ] ) );
	}

	/**
	 * Names follow C-2.4.
	 *
	 * @return void
	 */
	public function test_name_validation() {
		foreach ( [ 'a', 'email', 'your-name', 'a_b', 'a1', str_repeat( 'a', 64 ) ] as $name ) {
			$this->assertTrue( Field_Types::is_valid_name( $name ), $name );
		}
		foreach ( [ '', '1a', '-a', '_a', 'A', 'a.b', 'a b', 'é', str_repeat( 'a', 65 ), "a\n", null, 5 ] as $name ) {
			$this->assertFalse( Field_Types::is_valid_name( $name ), wp_json_encode( $name ) );
		}
	}

	/**
	 * Stored attributes are merged over the defaults; unknown ones are dropped; wrong types fall back.
	 *
	 * @return void
	 */
	public function test_resolve_applies_defaults_and_ignores_the_unknown() {
		$resolved = Field_Types::resolve_field(
			'text',
			[
				'name'      => 'who',
				'label'     => 'Who',
				'required'  => true,
				'width'     => 50,
				'maxLength' => '40',
				'minLength' => 'ten',
				'pattern'   => [ 'array' ],
				'future'    => 'kept in the source, ignored here',
			]
		);
		$this->assertSame( 'who', $resolved['name'] );
		$this->assertTrue( $resolved['required'] );
		$this->assertSame( '50', $resolved['width'] );
		$this->assertSame( 40, $resolved['maxLength'] );
		$this->assertSame( 0, $resolved['minLength'] );
		$this->assertSame( '', $resolved['pattern'] );
		$this->assertArrayNotHasKey( 'future', $resolved );
		$this->assertSame( array_keys( Field_Types::attributes( 'text' ) ), array_keys( $resolved ) );

		$this->assertSame( '100', Field_Types::resolve_field( 'text', [ 'width' => '60' ] )['width'] );
		$this->assertTrue( Field_Types::resolve_field( 'consent', [] )['required'] );
		$this->assertFalse( Field_Types::resolve_field( 'consent', [ 'required' => false ] )['required'] );
		$this->assertSame( '5', Field_Types::resolve_field( 'number', [ 'min' => 5 ] )['min'] );
		$this->assertSame( 'stack', Field_Types::resolve_field( 'radio', [ 'layout' => 'grid' ] )['layout'] );
		$this->assertSame( Field_Types::defaults( 'url' ), Field_Types::resolve_field( 'url', 'not an array' ) );
	}

	/**
	 * Form attributes: the autoresponder keeps its known keys and the rest is dropped.
	 *
	 * @return void
	 */
	public function test_resolve_form() {
		$resolved = Field_Types::resolve_form(
			[
				'formId'        => 'x',
				'autoresponder' => [
					'enabled' => true,
					'subject' => 'Hi',
					'extra'   => 1,
					'body'    => 5,
				],
				'ajax'          => 'no',
			]
		);
		$this->assertSame(
			[
				'enabled' => true,
				'toField' => '',
				'subject' => 'Hi',
				'body'    => '',
				'format'  => 'html',
			],
			$resolved['autoresponder']
		);
		$this->assertTrue( $resolved['ajax'] );
		$this->assertSame( Field_Types::form_defaults()['labelPosition'], $resolved['labelPosition'] );
	}
}
