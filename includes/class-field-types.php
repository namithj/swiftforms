<?php
/**
 * Field type registry.
 *
 * @package SwiftForms
 */

namespace SwiftForms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Field_Types
 *
 * The one canonical list of the twelve field types of C-2.1 and of every
 * block attribute with its type and default (C-2.3, C-2.4, C-2.5). The block
 * configs (P2) are built from it and the schema resolver applies its defaults,
 * so the editor, the renderer and the validator can never disagree.
 *
 * An attribute definition is an array: "type" (string, boolean, integer,
 * array or object, as in block.json), "default", and for closed lists "enum".
 * Attributes are never renamed or removed and enum values are never removed
 * (C-2.2): add to this file, never change what is here.
 *
 * Numeric limits (min, max, step) are strings so that "1.5" and "" (not set)
 * both fit; the resolver also accepts a JSON number and casts it.
 */
class Field_Types {

	/**
	 * Block names.
	 */
	const FORM_BLOCK         = 'swiftforms/form';
	const SUBMIT_BLOCK       = 'swiftforms/submit';
	const CONFIRMATION_BLOCK = 'swiftforms/confirmation';

	/**
	 * Prefix of the field block names ("swiftforms/field-text").
	 */
	const FIELD_BLOCK_PREFIX = 'swiftforms/field-';

	/**
	 * A field name (C-2.4). No dots: dots are reserved for sub-values (C-4.3).
	 */
	const NAME_PATTERN = '/^[a-z][a-z0-9_-]{0,63}$/D';

	/**
	 * The twelve v1.0 field types, in the order the inserter shows them.
	 */
	const TYPES = [ 'text', 'email', 'textarea', 'number', 'url', 'tel', 'date', 'select', 'radio', 'checkboxes', 'consent', 'hidden' ];

	/**
	 * Widths a field can take, as strings (C-2.4).
	 */
	const WIDTHS = [ '100', '75', '66', '50', '33', '25' ];

	/**
	 * Per type: render family, HTML input type (input family only) and the type-specific attributes.
	 *
	 * Families: input (a single <input>), textarea, select, choice (radio group or
	 * checkbox group), consent (one checkbox) and hidden.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function definitions() {
		$length = [
			'type'    => 'integer',
			'default' => 0,
		];
		$text   = [
			'type'    => 'string',
			'default' => '',
		];
		$count  = [
			'type'    => 'integer',
			'default' => 0,
		];
		$layout = [
			'type'    => 'string',
			'default' => 'stack',
			'enum'    => [ 'stack', 'inline' ],
		];
		$option = [
			'type'    => 'array',
			'default' => [],
		];

		return [
			'text'       => [
				'family'     => 'input',
				'html_type'  => 'text',
				'multi'      => false,
				'attributes' => [
					'minLength'      => $length,
					'maxLength'      => $length,
					'pattern'        => $text,
					'patternMessage' => $text,
				],
			],
			'email'      => [
				'family'     => 'input',
				'html_type'  => 'email',
				'multi'      => false,
				'attributes' => [],
			],
			'textarea'   => [
				'family'     => 'textarea',
				'html_type'  => '',
				'multi'      => false,
				'attributes' => [
					'rows'      => [
						'type'    => 'integer',
						'default' => 4,
					],
					'maxLength' => $length,
				],
			],
			'number'     => [
				'family'     => 'input',
				'html_type'  => 'number',
				'multi'      => false,
				'attributes' => [
					'min'  => $text,
					'max'  => $text,
					'step' => $text,
				],
			],
			'url'        => [
				'family'     => 'input',
				'html_type'  => 'url',
				'multi'      => false,
				'attributes' => [],
			],
			'tel'        => [
				'family'     => 'input',
				'html_type'  => 'tel',
				'multi'      => false,
				'attributes' => [],
			],
			'date'       => [
				'family'     => 'input',
				'html_type'  => 'date',
				'multi'      => false,
				'attributes' => [
					'min' => $text,
					'max' => $text,
				],
			],
			'select'     => [
				'family'     => 'select',
				'html_type'  => '',
				'multi'      => false, // Multi-value only when the "multiple" attribute is on; see is_multi().
				'attributes' => [
					'options'           => $option,
					'multiple'          => [
						'type'    => 'boolean',
						'default' => false,
					],
					'placeholderOption' => $text,
				],
			],
			'radio'      => [
				'family'     => 'choice',
				'html_type'  => 'radio',
				'multi'      => false,
				'attributes' => [
					'options' => $option,
					'layout'  => $layout,
				],
			],
			'checkboxes' => [
				'family'     => 'choice',
				'html_type'  => 'checkbox',
				'multi'      => true,
				'attributes' => [
					'options'    => $option,
					'layout'     => $layout,
					'minChoices' => $count,
					'maxChoices' => $count,
				],
			],
			'consent'    => [
				'family'     => 'consent',
				'html_type'  => 'checkbox',
				'multi'      => false,
				'attributes' => [
					'text' => $text,
				],
			],
			'hidden'     => [
				'family'     => 'hidden',
				'html_type'  => 'hidden',
				'multi'      => false,
				'attributes' => [
					'value' => $text,
				],
			],
		];
	}

	/**
	 * Attributes every field has (C-2.4), in a fixed order.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function common_attributes() {
		$text = [
			'type'    => 'string',
			'default' => '',
		];

		return [
			'name'         => $text,
			'label'        => $text,
			'help'         => $text,
			'placeholder'  => $text,
			'required'     => [
				'type'    => 'boolean',
				'default' => false,
			],
			'defaultValue' => $text,
			'width'        => [
				'type'    => 'string',
				'default' => '100',
				'enum'    => self::WIDTHS,
			],
			'autocomplete' => $text,
		];
	}

	/**
	 * The field type names.
	 *
	 * @return string[]
	 */
	public static function types() {
		return self::TYPES;
	}

	/**
	 * Whether a string is one of the twelve field types.
	 *
	 * @param mixed $type Candidate.
	 * @return bool
	 */
	public static function is_type( $type ) {
		return is_string( $type ) && in_array( $type, self::TYPES, true );
	}

	/**
	 * Block name of a field type.
	 *
	 * @param string $type Field type, for example "text".
	 * @return string "swiftforms/field-text", or an empty string for an unknown type.
	 */
	public static function block_name( $type ) {
		return self::is_type( $type ) ? self::FIELD_BLOCK_PREFIX . $type : '';
	}

	/**
	 * Field type of a block name.
	 *
	 * @param mixed $block_name For example "swiftforms/field-text".
	 * @return string|null Null for anything that is not one of the twelve field blocks.
	 */
	public static function type_from_block_name( $block_name ) {
		if ( ! is_string( $block_name ) || 0 !== strpos( $block_name, self::FIELD_BLOCK_PREFIX ) ) {
			return null;
		}
		$type = substr( $block_name, strlen( self::FIELD_BLOCK_PREFIX ) );
		return self::is_type( $type ) ? $type : null;
	}

	/**
	 * Render family of a type: input, textarea, select, choice, consent or hidden.
	 *
	 * @param string $type Field type.
	 * @return string Empty for an unknown type.
	 */
	public static function family( $type ) {
		$defs = self::definitions();
		return isset( $defs[ $type ] ) ? $defs[ $type ]['family'] : '';
	}

	/**
	 * HTML input type: text, email, number, url, tel, date for the input family; radio, checkbox or hidden for the others.
	 *
	 * @param string $type Field type.
	 * @return string Empty for textarea, select and unknown types.
	 */
	public static function html_type( $type ) {
		$defs = self::definitions();
		return isset( $defs[ $type ] ) ? $defs[ $type ]['html_type'] : '';
	}

	/**
	 * Whether a field of this type stores several values (one row per value, C-4.3).
	 *
	 * Checkboxes always do; a select does when its "multiple" attribute is on.
	 *
	 * @param string               $type       Field type.
	 * @param array<string, mixed> $attributes Resolved attributes (only "multiple" is read).
	 * @return bool
	 */
	public static function is_multi( $type, array $attributes = [] ) {
		$defs = self::definitions();
		if ( ! isset( $defs[ $type ] ) ) {
			return false;
		}
		if ( 'select' === $type ) {
			return true === ( $attributes['multiple'] ?? false );
		}
		return $defs[ $type ]['multi'];
	}

	/**
	 * Every attribute of a field type: the common ones first, then the type-specific ones.
	 *
	 * @param string $type Field type.
	 * @return array<string, array<string, mixed>> Empty for an unknown type.
	 */
	public static function attributes( $type ) {
		$defs = self::definitions();
		if ( ! isset( $defs[ $type ] ) ) {
			return [];
		}

		$attributes = array_merge( self::common_attributes(), $defs[ $type ]['attributes'] );
		if ( 'consent' === $type ) {
			$attributes['required']['default'] = true; // C-2.4: a consent box is required unless the author says otherwise.
		}
		return $attributes;
	}

	/**
	 * Default value of every attribute of a field type.
	 *
	 * @param string $type Field type.
	 * @return array<string, mixed>
	 */
	public static function defaults( $type ) {
		return self::defaults_of( self::attributes( $type ) );
	}

	/**
	 * Attributes of the form block (C-2.3). "autoresponder" is reserved for 1.1.0; it exists from 1.0 so no stored form ever lacks it.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function form_attributes() {
		return [
			'formId'                 => [
				'type'    => 'string',
				'default' => '',
			],
			'title'                  => [
				'type'    => 'string',
				'default' => '',
			],
			'confirmationType'       => [
				'type'    => 'string',
				'default' => 'message',
				'enum'    => [ 'message', 'redirect' ],
			],
			'redirectPageId'         => [
				'type'    => 'integer',
				'default' => 0,
			],
			'redirectUrl'            => [
				'type'    => 'string',
				'default' => '',
			],
			'useDefaultNotification' => [
				'type'    => 'boolean',
				'default' => true,
			],
			'notifications'          => [
				'type'    => 'array',
				'default' => [],
			],
			'storeEntries'           => [
				'type'    => 'string',
				'default' => 'default',
				'enum'    => [ 'default', 'yes', 'no' ],
			],
			'spamProtection'         => [
				'type'    => 'string',
				'default' => 'default',
				'enum'    => [ 'default', 'off' ],
			],
			'labelPosition'          => [
				'type'    => 'string',
				'default' => 'top',
				'enum'    => [ 'top', 'inline', 'hidden' ],
			],
			'requiredIndicator'      => [
				'type'    => 'string',
				'default' => 'asterisk',
				'enum'    => [ 'asterisk', 'text', 'none' ],
			],
			'ajax'                   => [
				'type'    => 'boolean',
				'default' => true,
			],
			'autoresponder'          => [
				'type'    => 'object',
				'default' => [
					'enabled' => false,
					'toField' => '',
					'subject' => '',
					'body'    => '',
					'format'  => 'html',
				],
			],
		];
	}

	/**
	 * Default value of every form attribute.
	 *
	 * @return array<string, mixed>
	 */
	public static function form_defaults() {
		return self::defaults_of( self::form_attributes() );
	}

	/**
	 * Fields of one notification (C-2.3 "notifications"), with the default of each.
	 *
	 * @return array<string, mixed>
	 */
	public static function notification_defaults() {
		return [
			'enabled' => true,
			'to'      => '',
			'cc'      => '',
			'bcc'     => '',
			'subject' => '',
			'replyTo' => '',
			'body'    => '',
			'format'  => 'html',
		];
	}

	/**
	 * Block supports of the form block (C-2.3).
	 *
	 * @return string[]
	 */
	public static function form_supports() {
		return [ 'color', 'spacing', 'typography', 'border', 'className', 'anchor' ];
	}

	/**
	 * Attributes of the submit block (C-2.5). The default is the English word, not a translation: the stored markup must not depend on the locale.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function submit_attributes() {
		return [
			'label' => [
				'type'    => 'string',
				'default' => 'Submit',
			],
		];
	}

	/**
	 * Attributes of the confirmation block: none, its content is its inner blocks (C-2.5).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function confirmation_attributes() {
		return [];
	}

	/**
	 * Whether a string is a valid field name (C-2.4).
	 *
	 * @param mixed $name Candidate.
	 * @return bool
	 */
	public static function is_valid_name( $name ) {
		return is_string( $name ) && 1 === preg_match( self::NAME_PATTERN, $name );
	}

	/**
	 * Resolve stored attributes against a set of definitions.
	 *
	 * Unknown attributes are ignored (C-2.8). A stored value of the wrong type,
	 * or outside an enum, becomes the default, which is what the block API does
	 * when it validates an attribute. Whole numbers and numeric strings are
	 * accepted for integers, and numbers for strings.
	 *
	 * @param array<string, array<string, mixed>> $definitions Attribute definitions.
	 * @param mixed                               $stored      Stored attributes (anything but an array counts as none).
	 * @return array<string, mixed> One entry per definition, in definition order.
	 */
	public static function resolve( array $definitions, $stored ) {
		$stored   = is_array( $stored ) ? $stored : [];
		$resolved = [];
		foreach ( $definitions as $name => $definition ) {
			$resolved[ $name ] = array_key_exists( $name, $stored )
				? self::coerce( $stored[ $name ], $definition )
				: $definition['default'];
		}
		return $resolved;
	}

	/**
	 * Resolve a field's stored attributes with its type's definitions.
	 *
	 * @param string $type   Field type.
	 * @param mixed  $stored Stored attributes.
	 * @return array<string, mixed>
	 */
	public static function resolve_field( $type, $stored ) {
		return self::resolve( self::attributes( $type ), $stored );
	}

	/**
	 * Resolve the form block's stored attributes.
	 *
	 * @param mixed $stored Stored attributes.
	 * @return array<string, mixed>
	 */
	public static function resolve_form( $stored ) {
		return self::resolve( self::form_attributes(), $stored );
	}

	/**
	 * Coerce one stored value to an attribute definition.
	 *
	 * @param mixed                $value      Stored value.
	 * @param array<string, mixed> $definition Attribute definition.
	 * @return mixed The value, or the default when it does not fit.
	 */
	private static function coerce( $value, array $definition ) {
		$default = $definition['default'];

		switch ( $definition['type'] ) {
			case 'string':
				if ( is_int( $value ) || is_float( $value ) ) {
					$value = (string) $value;
				}
				if ( ! is_string( $value ) ) {
					return $default;
				}
				if ( isset( $definition['enum'] ) && ! in_array( $value, $definition['enum'], true ) ) {
					return $default;
				}
				return $value;

			case 'boolean':
				return is_bool( $value ) ? $value : $default;

			case 'integer':
				if ( is_int( $value ) ) {
					return $value;
				}
				if ( ( is_float( $value ) && floor( $value ) === $value ) || ( is_string( $value ) && 1 === preg_match( '/^-?[0-9]{1,15}$/D', $value ) ) ) {
					return (int) $value;
				}
				return $default;

			case 'array':
				return is_array( $value ) ? array_values( $value ) : $default;

			case 'object':
				if ( ! is_array( $value ) ) {
					return $default;
				}
				// Known keys keep their default type; unknown keys are dropped.
				$object = [];
				foreach ( $default as $key => $key_default ) {
					$object[ $key ] = ( array_key_exists( $key, $value ) && gettype( $value[ $key ] ) === gettype( $key_default ) ) ? $value[ $key ] : $key_default;
				}
				return $object;
		}

		return $default;
	}

	/**
	 * Map attribute definitions to their defaults.
	 *
	 * @param array<string, array<string, mixed>> $definitions Attribute definitions.
	 * @return array<string, mixed>
	 */
	private static function defaults_of( array $definitions ) {
		$defaults = [];
		foreach ( $definitions as $name => $definition ) {
			$defaults[ $name ] = $definition['default'];
		}
		return $defaults;
	}
}
