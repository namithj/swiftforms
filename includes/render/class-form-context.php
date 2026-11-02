<?php
/**
 * The form being rendered.
 *
 * @package SwiftForms
 */

namespace SwiftForms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Form_Context
 *
 * Everything the field, submit and confirmation renderers need to know about the
 * form around them. Form_Renderer pushes one per form block before its inner
 * blocks render (inner blocks render before the form's own callback runs) and
 * pops it afterwards.
 *
 * The n-th field block that renders takes fields[ n ] of the schema built from the
 * same form block (C-3.9), so the rendered names are the names the resolver and the
 * submission handler use.
 */
class Form_Context {

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
	 * Source reference of the form (may be empty when it cannot be told).
	 *
	 * @var string
	 */
	public $source = '';

	/**
	 * Instance of this form on the page, 1-based.
	 *
	 * @var int
	 */
	public $instance = 1;

	/**
	 * Schema built from the form block.
	 *
	 * @var Form_Schema
	 */
	public $schema;

	/**
	 * The parsed form block.
	 *
	 * @var array<string, mixed>
	 */
	public $parsed_block = [];

	/**
	 * Whether the form block's own callback already used this context.
	 *
	 * @var bool
	 */
	public $used = false;

	/**
	 * Field blocks rendered so far.
	 *
	 * @var int
	 */
	private $cursor = 0;

	/**
	 * Build a context.
	 *
	 * @param string               $form_id   Effective formId.
	 * @param string               $stored_id formId as stored.
	 * @param string               $source    Source reference.
	 * @param int                  $instance  Instance on the page.
	 * @param Form_Schema          $schema    Schema of the form.
	 * @param array<string, mixed> $parsed    Parsed form block.
	 */
	public function __construct( $form_id, $stored_id, $source, $instance, Form_Schema $schema, array $parsed ) {
		$this->form_id      = (string) $form_id;
		$this->stored_id    = (string) $stored_id;
		$this->source       = (string) $source;
		$this->instance     = max( 1, (int) $instance );
		$this->schema       = $schema;
		$this->parsed_block = $parsed;
	}

	/**
	 * Resolved form attribute.
	 *
	 * @param string $name    Attribute name.
	 * @param mixed  $default Fallback.
	 * @return mixed
	 */
	public function attribute( $name, $default = null ) {
		return array_key_exists( $name, $this->schema->attributes ) ? $this->schema->attributes[ $name ] : $default;
	}

	/**
	 * Label position: top, inline or hidden.
	 *
	 * @return string
	 */
	public function label_position() {
		return (string) $this->attribute( 'labelPosition', 'top' );
	}

	/**
	 * Required indicator: asterisk, text or none.
	 *
	 * @return string
	 */
	public function required_indicator() {
		return (string) $this->attribute( 'requiredIndicator', 'asterisk' );
	}

	/**
	 * The schema field for the next field block that renders.
	 *
	 * Falls back to resolving the block's own attributes when the schema has no
	 * matching field (a field block that rendered outside the walk the resolver does).
	 *
	 * @param string               $type   Field type of the block that is rendering.
	 * @param array<string, mixed> $stored Stored attributes of that block.
	 * @return array<string, mixed> Field array: "type", every attribute at its resolved value, final "name".
	 */
	public function next_field( $type, array $stored ) {
		$index = $this->cursor;
		++$this->cursor;

		if ( isset( $this->schema->fields[ $index ] ) && ( $this->schema->fields[ $index ]['type'] ?? '' ) === $type ) {
			return $this->schema->fields[ $index ];
		}

		$field = array_merge( [ 'type' => $type ], Field_Types::resolve_field( $type, $stored ) );
		if ( isset( $field['options'] ) && is_array( $field['options'] ) ) {
			$field['options'] = Schema_Resolver::normalise_options( $field['options'] );
		}
		if ( ! Field_Types::is_valid_name( $field['name'] ) ) {
			$field['name'] = Schema_Resolver::derive_name( $field['name'], $type );
		}
		return $field;
	}

	/**
	 * Element ID (C-7.3): sf-{first 8 of the effective formId}-{name}-{instance}, plus an optional suffix.
	 *
	 * @param string $name   Field name.
	 * @param string $suffix Optional suffix such as "help", "error" or an option number.
	 * @return string
	 */
	public function element_id( $name, $suffix = '' ) {
		$id = 'sf-' . substr( $this->form_id, 0, 8 ) . '-' . $name . '-' . $this->instance;
		return '' === $suffix ? $id : $id . '-' . $suffix;
	}

	/**
	 * Form_State of this form instance.
	 *
	 * @return array<string, mixed>
	 */
	public function state() {
		return Form_State::get( $this->form_id, $this->instance );
	}
}
