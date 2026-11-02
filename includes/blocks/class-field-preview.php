<?php
/**
 * Editor canvas previews of the field blocks.
 *
 * @package SwiftForms
 */

namespace SwiftForms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Field_Preview
 *
 * The declarative markup trees the block builder renders client-side as each field
 * block's canvas: the same elements and classes as the front end (Field_Renderer), with
 * real inputs that are disabled and no network request. Labels, help, the required
 * indicator and options follow the attributes, and the label position and indicator
 * follow the form (the "formLabelPosition" and "formRequiredIndicator" context fields of
 * every field block). The theme styles it, because the form stylesheet is loaded in the
 * editor too.
 *
 * Differences from the front end are only those a static preview needs: no ids, no
 * "for" links, no error element, and a single marker instead of a hidden input.
 */
class Field_Preview {

	/**
	 * The markup tree of a field type.
	 *
	 * @param string $type Field type.
	 * @return array<string, mixed>
	 */
	public static function markup( $type ) {
		switch ( Field_Types::family( $type ) ) { // phpcs:ignore PHPCompatibility.Extensions.RemovedExtensions.famRemoved -- False positive: our own Field_Types::family().
			case 'textarea':
				return self::wrap( $type, 'div', self::label_nodes( 'label' ), [ self::control( 'textarea', 'swiftforms-textarea', self::textarea_attrs() ) ] );
			case 'select':
				return self::wrap( $type, 'div', self::label_nodes( 'label' ), [ self::select_node() ] );
			case 'choice':
				return self::wrap( $type, 'fieldset', self::label_nodes( 'legend' ), [ self::choices_node( Field_Types::html_type( $type ) ) ] );
			case 'consent':
				return self::wrap( $type, 'div', [ self::consent_node() ], [] );
			case 'hidden':
				return [
					'tag'      => 'div',
					'class'    => 'swiftforms-field swiftforms-field--hidden-preview',
					'children' => [
						[
							'tag'  => 'span',
							'text' => __( 'Hidden field:', 'swiftforms' ) . ' {{ attributes.name | default:' . __( 'unnamed', 'swiftforms' ) . ' }} = {{ attributes.value }}',
						],
					],
				];
		}

		$attrs = [
			'type'        => Field_Types::html_type( $type ),
			'disabled'    => 'disabled',
			'placeholder' => '{{ attributes.placeholder }}',
			'value'       => '{{ attributes.defaultValue }}',
			'aria-label'  => '{{ attributes.label }}',
		];
		return self::wrap( $type, 'div', self::label_nodes( 'label' ), [ self::control( 'input', 'swiftforms-input', $attrs ) ] );
	}

	/**
	 * The field wrapper: label part, the control, then help.
	 *
	 * @param string                           $type     Field type.
	 * @param string                           $tag      Root tag.
	 * @param array<int, array<string, mixed>> $before   Nodes before the control.
	 * @param array<int, array<string, mixed>> $controls Control nodes.
	 * @return array<string, mixed>
	 */
	private static function wrap( $type, $tag, array $before, array $controls ) {
		$help = [
			'tag'   => 'p',
			'class' => 'swiftforms-help',
			'text'  => '{{ attributes.help }}',
			'when'  => Block_Configs::when_all( [ Block_Configs::rule( 'help', 'not_empty' ) ] ),
		];

		return [
			'tag'      => $tag,
			'class'    => 'swiftforms-field swiftforms-field--' . $type . ' swiftforms-w-{{ attributes.width }}',
			'attrs'    => [
				'data-required' => '{{ attributes.required }}',
			],
			'children' => array_merge( $before, $controls, [ $help ] ),
		];
	}

	/**
	 * The label, once visible and once visually hidden, depending on the form's label position.
	 *
	 * @param string $tag label or legend.
	 * @return array<int, array<string, mixed>>
	 */
	private static function label_nodes( $tag ) {
		$inner = array_merge(
			[
				[
					'tag'  => 'span',
					'text' => '{{ attributes.label | default:' . __( 'Field label', 'swiftforms' ) . ' }}',
				],
			],
			self::indicator_nodes()
		);

		return [
			[
				'tag'      => $tag,
				'class'    => 'swiftforms-label',
				'when'     => Block_Configs::when_all( [ Block_Configs::rule( 'formLabelPosition', '!=', 'hidden' ) ] ),
				'children' => $inner,
			],
			[
				'tag'      => $tag,
				'class'    => 'swiftforms-label swiftforms-sr-only',
				'when'     => Block_Configs::when_all( [ Block_Configs::rule( 'formLabelPosition', '==', 'hidden' ) ] ),
				'children' => $inner,
			],
		];
	}

	/**
	 * The required indicator, following the form's setting.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function indicator_nodes() {
		return [
			[
				'tag'   => 'span',
				'class' => 'swiftforms-required',
				'attrs' => [
					'aria-hidden' => 'true',
				],
				'text'  => '*',
				'when'  => Block_Configs::when_all(
					[
						Block_Configs::rule( 'required', '==', true ),
						Block_Configs::rule( 'formRequiredIndicator', '==', 'asterisk' ),
					]
				),
			],
			[
				'tag'   => 'span',
				'class' => 'swiftforms-required',
				'text'  => ' ' . __( '(required)', 'swiftforms' ),
				'when'  => Block_Configs::when_all(
					[
						Block_Configs::rule( 'required', '==', true ),
						Block_Configs::rule( 'formRequiredIndicator', '==', 'text' ),
					]
				),
			],
		];
	}

	/**
	 * A disabled control element.
	 *
	 * @param string               $tag   input or textarea.
	 * @param string               $class CSS class.
	 * @param array<string, mixed> $attrs Attributes.
	 * @return array<string, mixed>
	 */
	private static function control( $tag, $class, array $attrs ) {
		return [
			'tag'   => $tag,
			'class' => $class,
			'attrs' => $attrs,
		];
	}

	/**
	 * Attributes of the textarea preview.
	 *
	 * @return array<string, string>
	 */
	private static function textarea_attrs() {
		return [
			'rows'        => '{{ attributes.rows }}',
			'disabled'    => 'disabled',
			'placeholder' => '{{ attributes.placeholder }}',
			'value'       => '{{ attributes.defaultValue }}',
			'aria-label'  => '{{ attributes.label }}',
		];
	}

	/**
	 * The select preview with its options.
	 *
	 * @return array<string, mixed>
	 */
	private static function select_node() {
		return [
			'tag'      => 'select',
			'class'    => 'swiftforms-select',
			'attrs'    => [
				'disabled'   => 'disabled',
				'aria-label' => '{{ attributes.label }}',
			],
			'children' => [
				[
					'tag'  => 'option',
					'text' => '{{ attributes.placeholderOption }}',
					'when' => Block_Configs::when_all( [ Block_Configs::rule( 'placeholderOption', 'not_empty' ) ] ),
				],
				[
					'tag'    => 'option',
					'attrs'  => [
						'value' => '{{ option.value }}',
					],
					'text'   => '{{ option.label }}',
					'repeat' => [
						'over' => 'attributes.options',
						'as'   => 'option',
					],
				],
			],
		];
	}

	/**
	 * The radio or checkbox list of a choice field.
	 *
	 * @param string $input_type radio or checkbox.
	 * @return array<string, mixed>
	 */
	private static function choices_node( $input_type ) {
		return [
			'tag'      => 'div',
			'class'    => 'swiftforms-choices swiftforms-choices--{{ attributes.layout }}',
			'children' => [
				[
					'tag'      => 'label',
					'class'    => 'swiftforms-choice',
					'repeat'   => [
						'over' => 'attributes.options',
						'as'   => 'option',
					],
					'children' => [
						[
							'tag'   => 'input',
							'attrs' => [
								'type'     => $input_type,
								'disabled' => 'disabled',
								'value'    => '{{ option.value }}',
							],
						],
						[
							'tag'  => 'span',
							'text' => '{{ option.label }}',
						],
					],
				],
			],
		];
	}

	/**
	 * The consent checkbox with its text (the editor shows the author's own markup unfiltered, as it does for any rich text).
	 *
	 * @return array<string, mixed>
	 */
	private static function consent_node() {
		return [
			'tag'      => 'label',
			'class'    => 'swiftforms-choice swiftforms-label swiftforms-consent',
			'children' => [
				[
					'tag'   => 'input',
					'attrs' => [
						'type'     => 'checkbox',
						'disabled' => 'disabled',
					],
				],
				[
					'tag'      => 'span',
					'children' => array_merge(
						[
							[
								'tag'  => 'span',
								'html' => '{{ attributes.text | default:' . __( 'Consent text', 'swiftforms' ) . ' }}',
							],
						],
						self::indicator_nodes()
					),
				],
			],
		];
	}
}
