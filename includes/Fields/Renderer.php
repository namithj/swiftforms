<?php
/**
 * Canonical server-side field HTML.
 *
 * @package SwiftForms
 */

declare(strict_types=1);

namespace SwiftForms\Fields;

/**
 * Every field block is dynamic (`save: () => null` in JS) — this class is
 * the only place field markup is produced, so `data-field-type` always
 * matches the type key exactly (a whole class of bugs the block-per-field
 * approach is prone to, by construction).
 */
final class Renderer {

	public function __construct( private FieldRegistry $field_registry ) {
	}

	/**
	 * Renders one field's full markup (wrapper, label, input, help, error slot).
	 *
	 * @param string               $type       Field type key.
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	public function render( string $type, array $attributes ): string {
		$field_type = $this->field_registry->get( $type );

		if ( ! $field_type ) {
			return '';
		}

		if ( 'hidden' === $type ) {
			$html = $this->render_hidden( $attributes );
		} else {
			$html = $this->render_wrapped( $type, $field_type, $attributes );
		}

		/**
		 * Filters one field type's rendered HTML.
		 *
		 * @param string               $html       Rendered HTML.
		 * @param array<string, mixed> $attributes Block attributes.
		 * @param string               $block_name Full block name, e.g. `swf/field-text`.
		 */
		return (string) apply_filters( "swf_field_html_{$type}", $html, $attributes, "swf/field-{$type}" );
	}

	/**
	 * Renders the shared wrapper (label/help/error/conditions) around a type-specific input.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	private function render_wrapped( string $type, FieldType $field_type, array $attributes ): string {
		$slug     = sanitize_key( (string) ( $attributes['slug'] ?? '' ) );
		$label    = (string) ( $attributes['label'] ?? '' );
		$help     = (string) ( $attributes['helpText'] ?? '' );
		$required = ! empty( $attributes['required'] );
		$field_id = wp_unique_id( 'swf-field-' . $slug . '-' );

		$conditions_attr = $this->conditions_attribute( $attributes );

		$input = 'checkbox' === $type || 'consent' === $type
			? $this->render_checkbox_like( $type, $attributes, $field_id )
			: $this->render_input( $type, $attributes, $field_id );

		$label_html = '';
		if ( 'checkbox' !== $type && 'consent' !== $type ) {
			$label_html = sprintf(
				'<label class="swf-field__label" for="%1$s">%2$s%3$s</label>',
				esc_attr( $field_id ),
				esc_html( $label ),
				$required ? ' <span class="swf-field__required" aria-hidden="true">*</span>' : ''
			);
		}

		$help_html = $help ? sprintf( '<p class="swf-field__help" id="%1$s-help">%2$s</p>', esc_attr( $field_id ), esc_html( $help ) ) : '';

		return sprintf(
			'<div class="swf-field swf-field--%1$s" data-swf-field data-field-slug="%2$s" data-field-type="%1$s" data-field-required="%3$s"%4$s>%5$s%6$s%7$s<div class="swf-field__error" id="%8$s-error" data-swf-field-error aria-live="polite"></div></div>',
			esc_attr( $type ),
			esc_attr( $slug ),
			$required ? '1' : '0',
			$conditions_attr,
			$label_html,
			$input,
			$help_html,
			esc_attr( $field_id )
		);
	}

	/**
	 * Renders the bare hidden input (no wrapper chrome at all).
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	private function render_hidden( array $attributes ): string {
		$slug  = sanitize_key( (string) ( $attributes['slug'] ?? '' ) );
		$value = (string) ( $attributes['value'] ?? '' );

		return sprintf(
			'<input type="hidden" data-swf-field data-field-slug="%1$s" data-field-type="hidden" name="%1$s" value="%2$s">',
			esc_attr( $slug ),
			esc_attr( $value )
		);
	}

	/**
	 * Renders the input element for a type that isn't checkbox-like.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	private function render_input( string $type, array $attributes, string $field_id ): string {
		$slug         = sanitize_key( (string) ( $attributes['slug'] ?? '' ) );
		$required     = ! empty( $attributes['required'] );
		$placeholder  = (string) ( $attributes['placeholder'] ?? '' );
		$described_by = ! empty( $attributes['helpText'] ) ? sprintf( ' aria-describedby="%s-help"', esc_attr( $field_id ) ) : '';

		switch ( $type ) {
			case 'textarea':
				return sprintf(
					'<textarea id="%1$s" name="%2$s" class="swf-field__control" placeholder="%3$s"%4$s%5$s></textarea>',
					esc_attr( $field_id ),
					esc_attr( $slug ),
					esc_attr( $placeholder ),
					$required ? ' required' : '',
					$described_by
				);

			case 'select':
				$options = '<option value="">' . esc_html__( 'Select…', 'swiftforms' ) . '</option>';
				foreach ( OptionParser::parse( (string) ( $attributes['options'] ?? '' ) ) as $option ) {
					$options .= sprintf( '<option value="%1$s">%2$s</option>', esc_attr( $option['value'] ), esc_html( $option['label'] ) );
				}
				return sprintf(
					'<select id="%1$s" name="%2$s" class="swf-field__control"%3$s%4$s>%5$s</select>',
					esc_attr( $field_id ),
					esc_attr( $slug ),
					$required ? ' required' : '',
					$described_by,
					$options
				);

			case 'radio':
				return $this->render_option_group( 'radio', $slug, $attributes, $field_id );

			case 'rating':
				return $this->render_rating( $slug, $attributes, $field_id );

			case 'file':
				return sprintf(
					'<input type="file" id="%1$s" name="%2$s" class="swf-field__control"%3$s%4$s>',
					esc_attr( $field_id ),
					esc_attr( $slug ),
					$required ? ' required' : '',
					$described_by
				);

			case 'number':
				$min  = (string) ( $attributes['min'] ?? '' );
				$max  = (string) ( $attributes['max'] ?? '' );
				$step = (string) ( $attributes['step'] ?? '' );
				return sprintf(
					'<input type="number" id="%1$s" name="%2$s" class="swf-field__control" placeholder="%3$s"%4$s%5$s%6$s%7$s%8$s>',
					esc_attr( $field_id ),
					esc_attr( $slug ),
					esc_attr( $placeholder ),
					'' !== $min ? sprintf( ' min="%s"', esc_attr( $min ) ) : '',
					'' !== $max ? sprintf( ' max="%s"', esc_attr( $max ) ) : '',
					'' !== $step ? sprintf( ' step="%s"', esc_attr( $step ) ) : '',
					$required ? ' required' : '',
					$described_by
				);

			case 'date':
				$min = (string) ( $attributes['min'] ?? '' );
				$max = (string) ( $attributes['max'] ?? '' );
				return sprintf(
					'<input type="date" id="%1$s" name="%2$s" class="swf-field__control"%3$s%4$s%5$s%6$s>',
					esc_attr( $field_id ),
					esc_attr( $slug ),
					'' !== $min ? sprintf( ' min="%s"', esc_attr( $min ) ) : '',
					'' !== $max ? sprintf( ' max="%s"', esc_attr( $max ) ) : '',
					$required ? ' required' : '',
					$described_by
				);

			case 'email':
			case 'tel':
			case 'url':
			case 'text':
			default:
				$html_type = in_array( $type, array( 'email', 'tel', 'url' ), true ) ? $type : 'text';
				return sprintf(
					'<input type="%1$s" id="%2$s" name="%3$s" class="swf-field__control" placeholder="%4$s"%5$s%6$s>',
					esc_attr( $html_type ),
					esc_attr( $field_id ),
					esc_attr( $slug ),
					esc_attr( $placeholder ),
					$required ? ' required' : '',
					$described_by
				);
		}
	}

	/**
	 * Renders a checkbox or consent field's own label/input pairing
	 * (the visible label sits beside the checkbox, not above it).
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	private function render_checkbox_like( string $type, array $attributes, string $field_id ): string {
		$slug     = sanitize_key( (string) ( $attributes['slug'] ?? '' ) );
		$required = ! empty( $attributes['required'] ) || 'consent' === $type;
		$value    = 'consent' === $type ? 'yes' : (string) ( $attributes['value'] ?? 'yes' );
		$text     = 'consent' === $type
			? (string) ( $attributes['statementText'] ?? '' )
			: (string) ( $attributes['checkboxLabel'] ?? '' );

		return sprintf(
			'<label class="swf-field__checkbox-label" for="%1$s"><input type="checkbox" id="%1$s" name="%2$s" value="%3$s" class="swf-field__control"%4$s> %5$s%6$s</label>',
			esc_attr( $field_id ),
			esc_attr( $slug ),
			esc_attr( $value ),
			$required ? ' required' : '',
			esc_html( $text ),
			$required ? ' <span class="swf-field__required" aria-hidden="true">*</span>' : ''
		);
	}

	/**
	 * Renders a radio (or checkbox-group-styled) option list.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	private function render_option_group( string $input_type, string $slug, array $attributes, string $field_id ): string {
		$html = '<div class="swf-field__options" role="group">';

		foreach ( OptionParser::parse( (string) ( $attributes['options'] ?? '' ) ) as $index => $option ) {
			$option_id = sprintf( '%s-%d', $field_id, $index );
			$html     .= sprintf(
				'<label class="swf-field__option" for="%1$s"><input type="%2$s" id="%1$s" name="%3$s" value="%4$s"> %5$s</label>',
				esc_attr( $option_id ),
				esc_attr( $input_type ),
				esc_attr( $slug ),
				esc_attr( $option['value'] ),
				esc_html( $option['label'] )
			);
		}

		return $html . '</div>';
	}

	/**
	 * Renders a star rating as a horizontal radio group.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	private function render_rating( string $slug, array $attributes, string $field_id ): string {
		$max  = max( 1, (int) ( $attributes['maxRating'] ?? 5 ) );
		$html = '<div class="swf-field__rating" role="radiogroup">';

		for ( $i = 1; $i <= $max; $i++ ) {
			$option_id = sprintf( '%s-%d', $field_id, $i );
			$html     .= sprintf(
				'<label class="swf-field__star" for="%1$s"><input type="radio" id="%1$s" name="%2$s" value="%3$d" class="swf-field__rating-input"><span aria-hidden="true">★</span><span class="screen-reader-text">%4$s</span></label>',
				esc_attr( $option_id ),
				esc_attr( $slug ),
				$i,
				/* translators: %d: star count. */
				esc_html( sprintf( _n( '%d star', '%d stars', $i, 'swiftforms' ), $i ) )
			);
		}

		return $html . '</div>';
	}

	/**
	 * Builds the `data-sf-conditions="…"` attribute when the field has
	 * conditional visibility rules configured.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	private function conditions_attribute( array $attributes ): string {
		$conditions = $attributes['conditions'] ?? null;

		if ( empty( $conditions ) || empty( $conditions['enabled'] ) || empty( $conditions['groups'] ) ) {
			return '';
		}

		return sprintf( ' data-sf-conditions="%s"', esc_attr( wp_json_encode( $conditions ) ) );
	}
}
