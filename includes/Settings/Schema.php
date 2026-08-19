<?php
/**
 * Shared Cassette-CMF schema helpers.
 *
 * @package SwiftForms
 */

declare(strict_types=1);

namespace SwiftForms\Settings;

/**
 * The global Settings page and the per-form Settings meta box are the same
 * shape — one metabox wrapping one tabs container wrapping a flat field list
 * per tab — and need the same handful of helpers over it. They live here once
 * instead of twice, so the two screens can't drift apart.
 */
final class Schema {

	/** Container/display field types that never store a value of their own. */
	private const NON_STORING_TYPES = array( 'custom_html', 'tabs', 'metabox', 'group', 'repeater' );

	/**
	 * Wraps a tab map in the metabox + tabs container Cassette-CMF expects.
	 *
	 * @param string                              $name  Metabox field name.
	 * @param string                              $id    Metabox DOM/registration id.
	 * @param string                              $title Metabox title.
	 * @param array<string, array<string, mixed>> $tabs  Tab id => { label, fields[] }.
	 * @param array<string, mixed>                $extra Extra metabox keys (e.g. context, priority).
	 * @return array<int, array<string, mixed>>
	 */
	public static function metabox( string $name, string $id, string $title, array $tabs, array $extra = array() ): array {
		return array(
			array_merge(
				array(
					'name'          => $name,
					'type'          => 'metabox',
					'metabox_id'    => $id,
					'metabox_title' => $title,
					'fields'        => array(
						array(
							'name' => $name . '_tabs',
							'type' => 'tabs',
							'tabs' => array_values(
								array_map(
									static fn ( string $tab_id, array $tab ): array => array(
										'id'     => $tab_id,
										'label'  => (string) ( $tab['label'] ?? $tab_id ),
										'fields' => (array) ( $tab['fields'] ?? array() ),
									),
									array_keys( $tabs ),
									$tabs
								)
							),
						),
					),
				),
				$extra
			),
		);
	}

	/**
	 * Every leaf (value-storing) field across every tab, keyed by field name.
	 *
	 * @param array<string, array<string, mixed>> $tabs Tab id => { label, fields[] }.
	 * @return array<string, array<string, mixed>>
	 */
	public static function flatten( array $tabs ): array {
		$fields = array();

		foreach ( $tabs as $tab ) {
			foreach ( (array) ( $tab['fields'] ?? array() ) as $field ) {
				$type = (string) ( $field['type'] ?? 'text' );

				if ( empty( $field['name'] ) || in_array( $type, self::NON_STORING_TYPES, true ) ) {
					continue;
				}

				$fields[ $field['name'] ] = $field;
			}
		}

		return $fields;
	}

	/**
	 * A plain heading, rendered as raw HTML, used to visually group fields
	 * within a tab (Cassette-CMF has no lightweight "section" concept).
	 *
	 * @param string $name  Unique field name.
	 * @param string $label Heading text.
	 * @return array<string, mixed>
	 */
	public static function heading( string $name, string $label ): array {
		return array(
			'name'     => $name,
			'type'     => 'custom_html',
			'label'    => '',
			'content'  => '<h3>' . esc_html( $label ) . '</h3>',
			'raw_html' => true,
		);
	}

	/**
	 * Before-save filter for secret fields: Cassette-CMF's password field
	 * never pre-fills, so a blank submission means "leave the stored value
	 * alone", not "clear it". Returning null tells Cassette-CMF to skip
	 * saving this field.
	 *
	 * @param mixed $value Submitted value.
	 * @return mixed|null
	 */
	public static function preserve_blank_secret( $value ) {
		return '' === trim( (string) $value ) ? null : $value;
	}

	/**
	 * Before-save filter for recipient lists: normalizes a comma-separated
	 * list down to just the valid email addresses.
	 *
	 * @param mixed $value Submitted value.
	 */
	public static function sanitize_email_list( $value ): string {
		$emails = array_filter( array_map( 'trim', explode( ',', (string) $value ) ) );

		return implode( ', ', array_filter( $emails, 'is_email' ) );
	}
}
