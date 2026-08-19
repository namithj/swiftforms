<?php
/**
 * Hidden field type — carries a fixed or pre-filled value, never rendered visibly.
 *
 * @package SwiftForms
 */

declare(strict_types=1);

namespace SwiftForms\Fields\Types;

use SwiftForms\Fields\FieldType;

final class HiddenField {

	public static function define(): FieldType {
		return new FieldType(
			type: 'hidden',
			label: __( 'Hidden Field', 'swiftforms' ),
			attributes: array(
				'slug'  => array(
					'type'    => 'string',
					'default' => 'hidden_field',
				),
				'value' => array(
					'type'    => 'string',
					'default' => '',
				),
			),
			validate: static fn( $value, array $attributes ): ?string => null // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- required to match FieldType's validate() callable shape.
		);
	}
}
