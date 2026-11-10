<?php
/**
 * Resolved form schema.
 *
 * @package SwiftForms
 */

namespace SwiftForms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Form_Schema
 *
 * What a form is, resolved from stored content (C-3.2): the fields in document
 * order with every attribute at its resolved value, the form's own attributes
 * and the raw blocks of its confirmation.
 *
 * The hash covers the field list only (not the form attributes, not the
 * confirmation blocks): it changes when, and only when, what a visitor can submit
 * or how it is validated changes. Entries and tokens refer to it (C-3.7, C-3.8,
 * C-4.4). It is the sha256 of the canonical JSON of the field list: object keys
 * sorted, slashes and Unicode left as they are, so attribute order in the stored
 * markup does not matter.
 *
 * One field per field block, in document order, so the n-th field block of a form
 * belongs to fields[ n ]. Each field array holds "type", then every attribute of
 * that type (Field_Types) at its resolved value, with the final "name" (C-3.9).
 */
class Form_Schema {

	/**
	 * Snapshot format version.
	 */
	const SNAPSHOT_VERSION = 1;

	/**
	 * Effective formId.
	 *
	 * @var string
	 */
	public $form_id = '';

	/**
	 * formId as stored in the block (empty when missing).
	 *
	 * @var string
	 */
	public $stored_id = '';

	/**
	 * Source reference the form belongs to.
	 *
	 * @var string
	 */
	public $source = '';

	/**
	 * Form attributes with defaults (C-2.3). Not part of the hash.
	 *
	 * @var array<string, mixed>
	 */
	public $attributes = [];

	/**
	 * Fields in document order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public $fields = [];

	/**
	 * Parsed inner blocks of the confirmation block(s), rendered later. Not part of the hash, not in snapshots.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public $confirmation_blocks = [];

	/**
	 * Field list hash (64 hex characters).
	 *
	 * @var string
	 */
	public $hash = '';

	/**
	 * Things the resolver repaired: list of { index, code, from, to } with code "name_derived" or "name_duplicate". Not part of the hash.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public $notices = [];

	/**
	 * Build a schema. The hash is computed from the fields.
	 *
	 * @param array<string, mixed> $data Keys: form_id, stored_id, source, attributes, fields, confirmation_blocks, notices.
	 */
	public function __construct( array $data = [] ) {
		$this->form_id             = (string) ( $data['form_id'] ?? '' );
		$this->stored_id           = (string) ( $data['stored_id'] ?? '' );
		$this->source              = (string) ( $data['source'] ?? '' );
		$this->attributes          = isset( $data['attributes'] ) && is_array( $data['attributes'] ) ? $data['attributes'] : Field_Types::form_defaults();
		$this->fields              = isset( $data['fields'] ) && is_array( $data['fields'] ) ? array_values( $data['fields'] ) : [];
		$this->confirmation_blocks = isset( $data['confirmation_blocks'] ) && is_array( $data['confirmation_blocks'] ) ? $data['confirmation_blocks'] : [];
		$this->notices             = isset( $data['notices'] ) && is_array( $data['notices'] ) ? $data['notices'] : [];
		$this->hash                = self::hash_fields( $this->fields );
	}

	/**
	 * A field by its (final) name.
	 *
	 * @param string $name Field name.
	 * @return array<string, mixed>|null
	 */
	public function field( $name ) {
		foreach ( $this->fields as $field ) {
			if ( ( $field['name'] ?? null ) === $name ) {
				return $field;
			}
		}
		return null;
	}

	/**
	 * The form's title attribute.
	 *
	 * @return string
	 */
	public function title() {
		return isset( $this->attributes['title'] ) && is_string( $this->attributes['title'] ) ? $this->attributes['title'] : '';
	}

	/**
	 * What is stored forever for a hash (C-4.4): enough to read and validate old entries.
	 *
	 * @return array<string, mixed>
	 */
	public function to_snapshot() {
		return [
			'v'         => self::SNAPSHOT_VERSION,
			'hash'      => $this->hash,
			'form_id'   => $this->form_id,
			'stored_id' => $this->stored_id,
			'source'    => $this->source,
			'title'     => $this->title(),
			'fields'    => $this->fields,
		];
	}

	/**
	 * Rebuild a schema from a snapshot.
	 *
	 * The hash is computed again from the fields. Form attributes other than the title and the
	 * confirmation blocks are not in a snapshot: they come from the current form (C-3.8).
	 *
	 * @param array<string, mixed>|string $snapshot Snapshot array or its JSON.
	 * @return Form_Schema|null Null when the snapshot is unreadable.
	 */
	public static function from_snapshot( $snapshot ) {
		if ( is_string( $snapshot ) ) {
			$snapshot = json_decode( $snapshot, true );
		}
		if ( ! is_array( $snapshot ) || ! isset( $snapshot['fields'] ) || ! is_array( $snapshot['fields'] ) ) {
			return null;
		}

		$attributes          = Field_Types::form_defaults();
		$attributes['title'] = isset( $snapshot['title'] ) && is_string( $snapshot['title'] ) ? $snapshot['title'] : '';

		return new self(
			[
				'form_id'    => $snapshot['form_id'] ?? '',
				'stored_id'  => $snapshot['stored_id'] ?? '',
				'source'     => $snapshot['source'] ?? '',
				'attributes' => $attributes,
				'fields'     => $snapshot['fields'],
			]
		);
	}

	/**
	 * Hash of a field list.
	 *
	 * @param array<int, array<string, mixed>> $fields Field list.
	 * @return string
	 */
	public static function hash_fields( array $fields ) {
		return hash( 'sha256', self::canonical_json( array_values( $fields ) ) );
	}

	/**
	 * Canonical JSON: object keys sorted (byte order) at every depth, lists kept in order, slashes and Unicode unescaped.
	 *
	 * @param mixed $value Any JSON-able value.
	 * @return string
	 */
	public static function canonical_json( $value ) {
		$json = wp_json_encode( self::sort_keys( $value ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return false === $json ? 'null' : $json;
	}

	/**
	 * Sort the keys of every object (non-list array) in a value.
	 *
	 * @param mixed $value Any value.
	 * @return mixed
	 */
	private static function sort_keys( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		foreach ( $value as $key => $item ) {
			$value[ $key ] = self::sort_keys( $item );
		}
		if ( array_keys( $value ) !== range( 0, count( $value ) - 1 ) && [] !== $value ) {
			ksort( $value, SORT_STRING );
		}
		return $value;
	}
}
