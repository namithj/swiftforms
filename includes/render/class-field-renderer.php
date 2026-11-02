<?php
/**
 * Field block renderer.
 *
 * @package SwiftForms
 */

namespace SwiftForms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Field_Renderer
 *
 * One renderer for the twelve field blocks, one method per input family
 * (Field_Types::family()): input, textarea, select, choice, consent and hidden.
 *
 * Rules (R01 2.1, 2.7; contract C-7):
 * - Classes swiftforms-field, swiftforms-field--{type}, swiftforms-field--required,
 *   swiftforms-w-{n}, swiftforms-label, swiftforms-help, swiftforms-error.
 * - IDs sf-{first 8 of the effective formId}-{name}-{instance} (Form_Context::element_id()),
 *   input names sf[{name}] or sf[{name}][].
 * - Radio and checkboxes are a fieldset with a legend. Help and the (always present) error
 *   element are tied to the control with aria-describedby. A required control has the native
 *   "required" and aria-required="true"; a checkbox group has neither (every box would be
 *   required) and says it in the legend. A field with an error in Form_State has
 *   aria-invalid="true" and the message in its error element.
 * - Values from Form_State override defaultValue. A multi-value default (checkboxes,
 *   multiple select) is a list of option values, one per line, because values may contain
 *   commas and pipes; a single value on one line also works.
 * - Everything that comes from block attributes is author text and is escaped. The consent
 *   text is the one exception: it passes through wp_kses() with links, emphasis and line
 *   breaks allowed.
 * - A field block outside a form renders nothing.
 */
class Field_Renderer {

	/**
	 * Render callback of the field blocks.
	 *
	 * @param array<string, mixed> $attributes Attributes prepared by the block builder (not used: the stored ones are read, see below).
	 * @param string               $content    Inner content (none).
	 * @param \WP_Block            $block      The block instance.
	 * @return string
	 */
	public static function render_block( $attributes, $content, $block ) {
		unset( $attributes, $content ); // The schema holds the attributes at their resolved value; the builder's sanitised copy may differ (text sanitisers trim, strip tags and collapse whitespace).

		$type    = Field_Types::type_from_block_name( $block->name );
		$context = Form_Renderer::current_context();
		if ( null === $type || null === $context ) {
			return '';
		}

		$stored = isset( $block->parsed_block['attrs'] ) && is_array( $block->parsed_block['attrs'] ) ? $block->parsed_block['attrs'] : [];
		return self::render( $context->next_field( $type, $stored ), $context );
	}

	/**
	 * Render one field.
	 *
	 * @param array<string, mixed> $field   Schema field (Form_Schema::$fields entry).
	 * @param Form_Context         $context The form being rendered.
	 * @return string
	 */
	public static function render( array $field, Form_Context $context ) {
		switch ( Field_Types::family( $field['type'] ) ) { // phpcs:ignore PHPCompatibility.Extensions.RemovedExtensions.famRemoved -- False positive: our own Field_Types::family().
			case 'input':
				return self::render_input( $field, $context );
			case 'textarea':
				return self::render_textarea( $field, $context );
			case 'select':
				return self::render_select( $field, $context );
			case 'choice':
				return self::render_choice( $field, $context );
			case 'consent':
				return self::render_consent( $field, $context );
			case 'hidden':
				return self::render_hidden( $field, $context );
		}
		return '';
	}

	// ---------------------------------------------------------------------
	// Families.
	// ---------------------------------------------------------------------

	/**
	 * Text, email, number, url, tel and date.
	 *
	 * @param array<string, mixed> $field   Field.
	 * @param Form_Context         $context Form.
	 * @return string
	 */
	private static function render_input( array $field, Form_Context $context ) {
		$name     = $field['name'];
		$type     = $field['type'];
		$id       = $context->element_id( $name );
		$state    = $context->state();
		$required = true === $field['required'];
		$value    = self::single_value( $state, $name, $field['defaultValue'] );

		$attrs = [
			'class'            => 'swiftforms-input',
			'type'             => Field_Types::html_type( $type ),
			'id'               => $id,
			'name'             => 'sf[' . $name . ']',
			'value'            => '' === $value ? null : $value,
			'placeholder'      => self::filled( $field['placeholder'] ),
			'minlength'        => self::positive( $field['minLength'] ?? 0 ),
			'maxlength'        => self::positive( $field['maxLength'] ?? 0 ),
			'pattern'          => self::filled( $field['pattern'] ?? '' ),
			'title'            => self::filled( $field['pattern'] ?? '' ) && self::filled( $field['patternMessage'] ?? '' ) ? $field['patternMessage'] : null,
			'min'              => self::filled( $field['min'] ?? '' ),
			'max'              => self::filled( $field['max'] ?? '' ),
			'step'             => self::filled( $field['step'] ?? '' ),
			'autocomplete'     => self::filled( $field['autocomplete'] ),
			'required'         => $required ? true : null,
			'aria-required'    => $required ? 'true' : null,
			'aria-invalid'     => self::error( $state, $name ) ? 'true' : null,
			'aria-describedby' => self::described_by( $field, $context ),
		];

		return self::wrapper_open( $field, $context, 'div' )
			. self::label( $field, $context, $id )
			. '<input' . Html::attrs( $attrs ) . '>'
			. self::help( $field, $context )
			. self::error_element( $field, $context )
			. '</div>';
	}

	/**
	 * Textarea.
	 *
	 * @param array<string, mixed> $field   Field.
	 * @param Form_Context         $context Form.
	 * @return string
	 */
	private static function render_textarea( array $field, Form_Context $context ) {
		$name     = $field['name'];
		$id       = $context->element_id( $name );
		$state    = $context->state();
		$required = true === $field['required'];
		$value    = self::single_value( $state, $name, $field['defaultValue'] );

		$attrs = [
			'class'            => 'swiftforms-textarea',
			'id'               => $id,
			'name'             => 'sf[' . $name . ']',
			'rows'             => max( 1, (int) $field['rows'] ),
			'placeholder'      => self::filled( $field['placeholder'] ),
			'maxlength'        => self::positive( $field['maxLength'] ),
			'autocomplete'     => self::filled( $field['autocomplete'] ),
			'required'         => $required ? true : null,
			'aria-required'    => $required ? 'true' : null,
			'aria-invalid'     => self::error( $state, $name ) ? 'true' : null,
			'aria-describedby' => self::described_by( $field, $context ),
		];

		// A parser drops one newline right after <textarea>; keep a value that starts with one.
		$lead = ( '' !== $value && ( "\n" === $value[0] || "\r" === $value[0] ) ) ? "\n" : '';

		return self::wrapper_open( $field, $context, 'div' )
			. self::label( $field, $context, $id )
			. '<textarea' . Html::attrs( $attrs ) . '>' . $lead . esc_textarea( $value ) . '</textarea>'
			. self::help( $field, $context )
			. self::error_element( $field, $context )
			. '</div>';
	}

	/**
	 * Select, single or multiple.
	 *
	 * @param array<string, mixed> $field   Field.
	 * @param Form_Context         $context Form.
	 * @return string
	 */
	private static function render_select( array $field, Form_Context $context ) {
		$name     = $field['name'];
		$id       = $context->element_id( $name );
		$state    = $context->state();
		$required = true === $field['required'];
		$multiple = true === $field['multiple'];
		$selected = self::selected_values( $state, $name, $field['defaultValue'], $multiple );

		$attrs = [
			'class'            => 'swiftforms-select',
			'id'               => $id,
			'name'             => 'sf[' . $name . ']' . ( $multiple ? '[]' : '' ),
			'multiple'         => $multiple ? true : null,
			'autocomplete'     => self::filled( $field['autocomplete'] ),
			'required'         => $required ? true : null,
			'aria-required'    => $required ? 'true' : null,
			'aria-invalid'     => self::error( $state, $name ) ? 'true' : null,
			'aria-describedby' => self::described_by( $field, $context ),
		];

		$options = '';
		if ( '' !== $field['placeholderOption'] && ! $multiple ) {
			$options .= '<option value="">' . esc_html( $field['placeholderOption'] ) . '</option>';
		}
		foreach ( $field['options'] as $option ) {
			$options .= '<option' . Html::attrs(
				[
					'value'    => $option['value'],
					'selected' => in_array( $option['value'], $selected, true ) ? true : null,
				]
			) . '>' . esc_html( $option['label'] ) . '</option>';
		}

		return self::wrapper_open( $field, $context, 'div' )
			. self::label( $field, $context, $id )
			. '<select' . Html::attrs( $attrs ) . '>' . $options . '</select>'
			. self::help( $field, $context )
			. self::error_element( $field, $context )
			. '</div>';
	}

	/**
	 * Radio buttons and checkboxes: a fieldset with a legend.
	 *
	 * @param array<string, mixed> $field   Field.
	 * @param Form_Context         $context Form.
	 * @return string
	 */
	private static function render_choice( array $field, Form_Context $context ) {
		$name     = $field['name'];
		$type     = $field['type'];
		$state    = $context->state();
		$required = true === $field['required'];
		$multi    = Field_Types::is_multi( $type, $field );
		$selected = self::selected_values( $state, $name, $field['defaultValue'], $multi );
		$has_err  = (bool) self::error( $state, $name );

		$items = '';
		$n     = 0;
		foreach ( $field['options'] as $option ) {
			++$n;
			$attrs  = [
				'type'          => Field_Types::html_type( $type ),
				'id'            => $context->element_id( $name, (string) $n ),
				'name'          => 'sf[' . $name . ']' . ( $multi ? '[]' : '' ),
				'value'         => $option['value'],
				'checked'       => in_array( $option['value'], $selected, true ) ? true : null,
				'required'      => ( $required && ! $multi ) ? true : null,
				'aria-required' => ( $required && ! $multi ) ? 'true' : null,
				'aria-invalid'  => $has_err ? 'true' : null,
			];
			$items .= '<label class="swiftforms-choice"><input' . Html::attrs( $attrs ) . '><span>' . esc_html( $option['label'] ) . '</span></label>';
		}

		$extra = [
			'aria-describedby' => self::described_by( $field, $context ),
		];
		if ( $multi ) {
			// For the view module's group check (the server rule is Validator::rule_choice_count()).
			$extra['data-sf-min-choices'] = self::positive( $field['minChoices'] ?? 0 );
			$extra['data-sf-max-choices'] = self::positive( $field['maxChoices'] ?? 0 );
			$extra                        = array_filter(
				$extra,
				static function ( $value ) {
					return null !== $value;
				}
			);
		}
		if ( 'radio' === $type ) {
			$extra['role'] = 'radiogroup';
		}

		return self::wrapper_open( $field, $context, 'fieldset', $extra )
			. '<legend class="' . esc_attr( self::label_class( $context ) ) . '">' . self::label_text( $field ) . self::indicator( $field, $context ) . '</legend>'
			. '<div class="swiftforms-choices swiftforms-choices--' . esc_attr( 'inline' === $field['layout'] ? 'inline' : 'stack' ) . '">' . $items . '</div>'
			. self::help( $field, $context )
			. self::error_element( $field, $context )
			. '</fieldset>';
	}

	/**
	 * Consent: one checkbox whose label is the consent text.
	 *
	 * The label position never hides a consent text: it is what the visitor agrees to.
	 *
	 * @param array<string, mixed> $field   Field.
	 * @param Form_Context         $context Form.
	 * @return string
	 */
	private static function render_consent( array $field, Form_Context $context ) {
		$name     = $field['name'];
		$id       = $context->element_id( $name );
		$state    = $context->state();
		$required = true === $field['required'];
		$checked  = isset( $state['values'][ $name ] ) && is_string( $state['values'][ $name ] ) && '' !== $state['values'][ $name ];

		$attrs = [
			'type'             => 'checkbox',
			'id'               => $id,
			'name'             => 'sf[' . $name . ']',
			'value'            => '1',
			'checked'          => $checked ? true : null,
			'required'         => $required ? true : null,
			'aria-required'    => $required ? 'true' : null,
			'aria-invalid'     => self::error( $state, $name ) ? 'true' : null,
			'aria-describedby' => self::described_by( $field, $context ),
		];

		$text = '' !== trim( $field['text'] ) ? wp_kses( $field['text'], self::consent_html() ) : self::label_text( $field );

		return self::wrapper_open( $field, $context, 'div' )
			. '<label class="swiftforms-choice swiftforms-label swiftforms-consent" for="' . esc_attr( $id ) . '"><input' . Html::attrs( $attrs ) . '><span>' . $text . self::indicator( $field, $context ) . '</span></label>'
			. self::help( $field, $context )
			. self::error_element( $field, $context )
			. '</div>';
	}

	/**
	 * Hidden field: its resolved value, never a posted one (C-3.10).
	 *
	 * @param array<string, mixed> $field   Field.
	 * @param Form_Context         $context Form.
	 * @return string
	 */
	private static function render_hidden( array $field, Form_Context $context ) {
		$attrs = [
			'type'  => 'hidden',
			'class' => 'swiftforms-field swiftforms-field--hidden',
			'id'    => $context->element_id( $field['name'] ),
			'name'  => 'sf[' . $field['name'] . ']',
			'value' => Hidden_Value::resolve( $field['value'], $context->source, get_current_user_id() ),
		];
		return '<input' . Html::attrs( $attrs ) . '>';
	}

	// ---------------------------------------------------------------------
	// Pieces.
	// ---------------------------------------------------------------------

	/**
	 * Opening tag of the field wrapper, with the block supports (colours, spacing, class, ...).
	 *
	 * @param array<string, mixed> $field   Field.
	 * @param Form_Context         $context Form (unused, kept for symmetry).
	 * @param string               $tag     div or fieldset.
	 * @param array<string, mixed> $extra   Extra attributes.
	 * @return string
	 */
	private static function wrapper_open( array $field, Form_Context $context, $tag, array $extra = [] ) {
		unset( $context );
		$classes = [ 'swiftforms-field', 'swiftforms-field--' . $field['type'] ];
		if ( true === $field['required'] ) {
			$classes[] = 'swiftforms-field--required';
		}
		$classes[] = 'swiftforms-w-' . $field['width'];

		return '<' . $tag . ' ' . get_block_wrapper_attributes( array_merge( [ 'class' => implode( ' ', $classes ) ], $extra ) ) . '>';
	}

	/**
	 * The label element of a single control.
	 *
	 * @param array<string, mixed> $field   Field.
	 * @param Form_Context         $context Form.
	 * @param string               $for_id  ID of the control.
	 * @return string
	 */
	private static function label( array $field, Form_Context $context, $for_id ) {
		return '<label class="' . esc_attr( self::label_class( $context ) ) . '" for="' . esc_attr( $for_id ) . '">'
			. self::label_text( $field ) . self::indicator( $field, $context ) . '</label>';
	}

	/**
	 * CSS classes of a label: visually hidden (still announced) when the form hides labels.
	 *
	 * @param Form_Context $context Form.
	 * @return string
	 */
	private static function label_class( Form_Context $context ) {
		return 'hidden' === $context->label_position() ? 'swiftforms-label swiftforms-sr-only' : 'swiftforms-label';
	}

	/**
	 * Escaped label text; the field name stands in for a missing label.
	 *
	 * @param array<string, mixed> $field Field.
	 * @return string
	 */
	private static function label_text( array $field ) {
		$label = '' !== trim( $field['label'] ) ? $field['label'] : $field['name'];
		return esc_html( $label );
	}

	/**
	 * Required indicator markup.
	 *
	 * @param array<string, mixed> $field   Field.
	 * @param Form_Context         $context Form.
	 * @return string
	 */
	private static function indicator( array $field, Form_Context $context ) {
		if ( true !== $field['required'] ) {
			return '';
		}
		switch ( $context->required_indicator() ) {
			case 'asterisk':
				return '<span class="swiftforms-required" aria-hidden="true">*</span><span class="swiftforms-sr-only"> ' . esc_html__( '(required)', 'swiftforms' ) . '</span>';
			case 'text':
				return '<span class="swiftforms-required"> ' . esc_html__( '(required)', 'swiftforms' ) . '</span>';
		}
		return '';
	}

	/**
	 * Help paragraph.
	 *
	 * @param array<string, mixed> $field   Field.
	 * @param Form_Context         $context Form.
	 * @return string
	 */
	private static function help( array $field, Form_Context $context ) {
		if ( '' === trim( $field['help'] ) ) {
			return '';
		}
		return '<p class="swiftforms-help" id="' . esc_attr( $context->element_id( $field['name'], 'help' ) ) . '">' . esc_html( $field['help'] ) . '</p>';
	}

	/**
	 * Error paragraph. Always present, so client validation (P6) has a place to write to; hidden while empty.
	 *
	 * @param array<string, mixed> $field   Field.
	 * @param Form_Context         $context Form.
	 * @return string
	 */
	private static function error_element( array $field, Form_Context $context ) {
		$message = self::error( $context->state(), $field['name'] );
		return '<p class="swiftforms-error" id="' . esc_attr( $context->element_id( $field['name'], 'error' ) ) . '"' . ( $message ? '' : ' hidden' ) . '>' . esc_html( $message ) . '</p>';
	}

	/**
	 * Value of aria-describedby: help (when there is any) and the error element.
	 *
	 * @param array<string, mixed> $field   Field.
	 * @param Form_Context         $context Form.
	 * @return string
	 */
	private static function described_by( array $field, Form_Context $context ) {
		$ids = [];
		if ( '' !== trim( $field['help'] ) ) {
			$ids[] = $context->element_id( $field['name'], 'help' );
		}
		$ids[] = $context->element_id( $field['name'], 'error' );
		return implode( ' ', $ids );
	}

	// ---------------------------------------------------------------------
	// Values.
	// ---------------------------------------------------------------------

	/**
	 * Error message of a field in the state.
	 *
	 * @param array<string, mixed> $state Form_State.
	 * @param string               $name  Field name.
	 * @return string
	 */
	private static function error( array $state, $name ) {
		return isset( $state['errors'][ $name ] ) && is_string( $state['errors'][ $name ] ) ? $state['errors'][ $name ] : '';
	}

	/**
	 * Value of a single-value field: the submitted one, else the default.
	 *
	 * @param array<string, mixed> $state   Form_State.
	 * @param string               $name    Field name.
	 * @param string               $default defaultValue attribute.
	 * @return string
	 */
	private static function single_value( array $state, $name, $default ) {
		if ( isset( $state['values'][ $name ] ) ) {
			return is_string( $state['values'][ $name ] ) ? $state['values'][ $name ] : '';
		}
		return (string) $default;
	}

	/**
	 * Selected option values of a choice or select field.
	 *
	 * @param array<string, mixed> $state    Form_State.
	 * @param string               $name     Field name.
	 * @param string               $default  defaultValue attribute (one value per line for multi-value fields).
	 * @param bool                 $multi    Whether several values can be selected.
	 * @return string[]
	 */
	private static function selected_values( array $state, $name, $default, $multi ) {
		if ( isset( $state['values'][ $name ] ) ) {
			$posted = $state['values'][ $name ];
			return array_values( array_filter( is_array( $posted ) ? $posted : [ $posted ], 'is_string' ) );
		}
		if ( '' === (string) $default ) {
			return [];
		}
		if ( ! $multi ) {
			return [ (string) $default ];
		}
		$lines = preg_split( '/\r\n|\r|\n/', (string) $default );
		return array_values(
			array_filter(
				false === $lines ? [] : $lines,
				static function ( $line ) {
					return '' !== $line;
				}
			)
		);
	}

	/**
	 * A non-empty string, else null (an attribute left out).
	 *
	 * @param mixed $value Value.
	 * @return string|null
	 */
	private static function filled( $value ) {
		return ( is_string( $value ) && '' !== trim( $value ) ) ? $value : null;
	}

	/**
	 * A positive integer, else null.
	 *
	 * @param mixed $value Value.
	 * @return int|null
	 */
	private static function positive( $value ) {
		return (int) $value > 0 ? (int) $value : null;
	}

	/**
	 * Tags the consent text may contain.
	 *
	 * @return array<string, array<string, bool>>
	 */
	private static function consent_html() {
		return [
			'a'      => [
				'href'   => true,
				'title'  => true,
				'target' => true,
				'rel'    => true,
			],
			'strong' => [],
			'b'      => [],
			'em'     => [],
			'i'      => [],
			'br'     => [],
		];
	}
}
