<?php
/**
 * Placeholder substitution for notification subjects/bodies.
 *
 * @package SwiftForms
 */

declare(strict_types=1);

namespace SwiftForms\Notifications;

/**
 * Supports `{entry_id}`, `{form_id}`, `{form_title}`, `{fields}` (every
 * submitted field as "Label: value" lines), and `{field:slug}` (one field's
 * raw value).
 */
final class TemplateRenderer {

	/**
	 * @param string                                                     $template Raw template text.
	 * @param array{entry_id: int, form_id: int, form_title: string, fields: array<int, array{slug: string, label: string, value: mixed}>} $context Substitution context.
	 */
	public function render( string $template, array $context ): string {
		$replacements = array(
			'{entry_id}'   => (string) $context['entry_id'],
			'{form_id}'    => (string) $context['form_id'],
			'{form_title}' => $context['form_title'],
			'{fields}'     => $this->render_all_fields( $context['fields'] ),
		);

		$rendered = strtr( $template, $replacements );

		return (string) preg_replace_callback(
			'/\{field:([a-z0-9_]+)\}/i',
			static function ( array $matches ) use ( $context ): string {
				foreach ( $context['fields'] as $field ) {
					if ( $field['slug'] === $matches[1] ) {
						return self::stringify( $field['value'] );
					}
				}

				return '';
			},
			$rendered
		);
	}

	/**
	 * @param array<int, array{slug: string, label: string, value: mixed}> $fields Submitted fields.
	 */
	private function render_all_fields( array $fields ): string {
		$lines = array();

		foreach ( $fields as $field ) {
			$value = self::stringify( $field['value'] );

			if ( '' === $value ) {
				continue;
			}

			$lines[] = sprintf( '%s: %s', $field['label'], $value );
		}

		return implode( "\n", $lines );
	}

	/**
	 * Renders a field's value as plain text (files show their filename).
	 */
	private static function stringify( mixed $value ): string {
		if ( is_array( $value ) ) {
			return (string) ( $value['name'] ?? '' );
		}

		return (string) $value;
	}
}
