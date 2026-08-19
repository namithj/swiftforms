<?php
/**
 * Tests for SwiftForms\Fields\FieldRegistry.
 *
 * @package SwiftForms
 */

declare(strict_types=1);

namespace SwiftForms\Tests\Fields;

use SwiftForms\Fields\FieldRegistry;
use SwiftForms\Fields\FieldType;
use SwiftForms\Tests\TestCase;

final class FieldRegistryTest extends TestCase {

	private const BUILTIN_TYPES = array(
		'text',
		'email',
		'textarea',
		'tel',
		'url',
		'number',
		'date',
		'select',
		'radio',
		'checkbox',
		'file',
		'hidden',
		'consent',
		'rating',
	);

	public function test_all_fourteen_builtin_types_are_registered(): void {
		$registry = new FieldRegistry();
		$registry->load_types();

		foreach ( self::BUILTIN_TYPES as $type ) {
			$this->assertTrue( $registry->has( $type ), "Missing built-in type: {$type}" );
		}

		$this->assertCount( 14, $registry->all() );
	}

	public function test_to_js_config_exposes_full_attribute_schema_per_type(): void {
		$registry = new FieldRegistry();
		$registry->load_types();

		$config = $registry->to_js_config();

		$this->assertArrayHasKey( 'label', $config['text']['attributes'] );
		$this->assertArrayHasKey( 'slug', $config['text']['attributes'] );
		$this->assertArrayHasKey( 'conditions', $config['text']['attributes'] );
		$this->assertArrayHasKey( 'options', $config['select']['attributes'] );
	}

	public function test_swf_field_types_filter_can_add_a_custom_type(): void {
		add_filter(
			'swf_field_types',
			static function ( array $types ): array {
				$types['rainbow'] = new FieldType(
					type: 'rainbow',
					label: 'Rainbow',
					attributes: array(),
					validate: static fn( $value, array $attributes ): ?string => null // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
				);

				return $types;
			}
		);

		$registry = new FieldRegistry();
		$registry->load_types();

		$this->assertTrue( $registry->has( 'rainbow' ) );

		remove_all_filters( 'swf_field_types' );
	}

	public function test_load_types_is_idempotent(): void {
		$registry = new FieldRegistry();
		$registry->load_types();
		$registry->load_types();

		$this->assertCount( 14, $registry->all() );
	}
}
