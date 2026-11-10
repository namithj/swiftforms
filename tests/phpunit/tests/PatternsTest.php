<?php
/**
 * Tests for the six form patterns.
 *
 * @package SwiftForms
 */

use SwiftForms\Block_Configs;
use SwiftForms\Pattern_Configs;

/**
 * Class PatternsTest
 *
 * @group blocks
 */
class PatternsTest extends WP_UnitTestCase {

	/**
	 * Slugs of the six patterns.
	 *
	 * @return string[]
	 */
	private function slugs() {
		return [
			'swiftforms/contact',
			'swiftforms/quote-request',
			'swiftforms/callback-request',
			'swiftforms/newsletter-signup',
			'swiftforms/event-rsvp',
			'swiftforms/feedback',
		];
	}

	/**
	 * All blocks in a list of parsed blocks, flattened.
	 *
	 * @param array $blocks Parsed blocks.
	 * @return array[]
	 */
	private function flatten( array $blocks ) {
		$flat = [];
		foreach ( $blocks as $block ) {
			if ( null === $block['blockName'] ) {
				continue;
			}
			$flat[] = $block;
			$flat   = array_merge( $flat, $this->flatten( $block['innerBlocks'] ) );
		}
		return $flat;
	}

	/**
	 * The configuration holds the six patterns in the Forms category.
	 *
	 * @return void
	 */
	public function test_six_patterns_in_the_forms_category() {
		$patterns = Pattern_Configs::all();
		$this->assertSame( $this->slugs(), array_column( $patterns, 'slug' ) );

		foreach ( $patterns as $pattern ) {
			$this->assertSame( [ 'swiftforms' ], $pattern['categories'], $pattern['slug'] );
			$this->assertNotSame( '', $pattern['title'] );
			$this->assertNotSame( '', $pattern['description'] );
		}
		$this->assertSame( $patterns, Block_Configs::all()['patterns'] );
	}

	/**
	 * They are registered with WordPress, with the category.
	 *
	 * @return void
	 */
	public function test_patterns_are_registered() {
		$registry = WP_Block_Patterns_Registry::get_instance();
		foreach ( $this->slugs() as $slug ) {
			$this->assertTrue( $registry->is_registered( $slug ), $slug . ' is registered.' );
			$this->assertSame( [ 'swiftforms' ], $registry->get_registered( $slug )['categories'] );
		}
		$this->assertTrue( WP_Block_Pattern_Categories_Registry::get_instance()->is_registered( 'swiftforms' ) );
		$this->assertSame( 'Forms', WP_Block_Pattern_Categories_Registry::get_instance()->get_registered( 'swiftforms' )['label'] );
	}

	/**
	 * No formId (the editor gives each inserted copy one), v1 blocks only, every block registered, valid attributes.
	 *
	 * @return void
	 */
	public function test_pattern_content() {
		$allowed = array_merge( Block_Configs::form_children(), [ 'swiftforms/form' ] );

		foreach ( Pattern_Configs::all() as $pattern ) {
			$slug = $pattern['slug'];
			$this->assertStringNotContainsString( 'formId', $pattern['content'], $slug . ' has no formId.' );

			$blocks = $this->flatten( parse_blocks( $pattern['content'] ) );
			$forms  = array_filter(
				$blocks,
				static function ( $block ) {
					return 'swiftforms/form' === $block['blockName'];
				}
			);
			$this->assertCount( 1, $forms, $slug . ' has one form.' );

			$names = [];
			foreach ( $blocks as $block ) {
				$name = $block['blockName'];
				$this->assertTrue( WP_Block_Type_Registry::get_instance()->is_registered( $name ), "$slug uses $name, which is registered." );
				if ( 0 === strpos( $name, 'swiftforms/field-' ) ) {
					$names[] = $block['attrs']['name'] ?? '';
				}
				if ( 0 !== strpos( $name, 'swiftforms/' ) ) {
					$this->assertContains( $name, array_merge( $allowed, [ 'core/heading' ] ), "$slug uses $name." );
				}
			}
			$this->assertNotContains( '', $names, $slug . ': every field has a name.' );
			$this->assertSame( $names, array_values( array_unique( $names ) ), $slug . ': field names are unique.' );
			foreach ( $names as $field_name ) {
				$this->assertTrue( \SwiftForms\Field_Types::is_valid_name( $field_name ), $slug . ' ' . $field_name );
			}
			$this->assertContains( 'swiftforms/submit', array_column( $blocks, 'blockName' ), $slug );
			$this->assertContains( 'swiftforms/confirmation', array_column( $blocks, 'blockName' ), $slug );
		}
	}

	/**
	 * Every field attribute in a pattern is one the block declares, with a valid value.
	 *
	 * @return void
	 */
	public function test_pattern_attributes_are_valid() {
		foreach ( Pattern_Configs::all() as $pattern ) {
			foreach ( $this->flatten( parse_blocks( $pattern['content'] ) ) as $block ) {
				$type = WP_Block_Type_Registry::get_instance()->get_registered( $block['blockName'] );
				foreach ( $block['attrs'] as $attribute => $value ) {
					$this->assertArrayHasKey( $attribute, $type->attributes, $pattern['slug'] . ' ' . $block['blockName'] . ' ' . $attribute );
					$result = rest_validate_value_from_schema( $value, $type->attributes[ $attribute ], $attribute );
					$this->assertTrue( true === $result, $pattern['slug'] . ' ' . $block['blockName'] . '.' . $attribute . ' is valid.' );
				}
			}
		}
	}

	/**
	 * Give every form block a formId, as the editor does on insert.
	 *
	 * @param array $blocks Parsed blocks, changed in place.
	 * @return void
	 */
	private function give_form_id( array &$blocks ) {
		foreach ( $blocks as &$block ) {
			if ( 'swiftforms/form' === $block['blockName'] ) {
				$block['attrs']['formId'] = '33333333-3333-4333-8333-333333333333';
			}
			$this->give_form_id( $block['innerBlocks'] );
		}
	}

	/**
	 * A pattern renders as a working form once it has a formId.
	 *
	 * @return void
	 */
	public function test_patterns_render() {
		foreach ( Pattern_Configs::all() as $pattern ) {
			$blocks = parse_blocks( $pattern['content'] );
			$this->give_form_id( $blocks );
			$content = serialize_blocks( $blocks );
			$html    = do_blocks( $content );

			$this->assertStringContainsString( '<form', $html, $pattern['slug'] );
			$this->assertStringContainsString( '<button type="submit"', $html, $pattern['slug'] );
			$this->assertStringNotContainsString( '<!-- wp:', $html, $pattern['slug'] );
		}
	}
}
