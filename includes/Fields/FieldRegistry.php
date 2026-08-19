<?php
/**
 * Single source of truth for field types.
 *
 * @package SwiftForms
 */

declare(strict_types=1);

namespace SwiftForms\Fields;

use SwiftForms\Registrable;

/**
 * Every field type SwiftForms knows about — built-ins plus anything an
 * addon registers via the `swf_field_types` filter — lives here. Blocks,
 * server-side validation, and the JS field-block factory all read from this
 * one registry, so a new field type is one FieldType definition away from
 * being fully wired everywhere.
 */
final class FieldRegistry implements Registrable {

	/**
	 * @var array<string, FieldType>
	 */
	private array $types = array();

	private bool $loaded = false;

	public function register(): void {
		// Priority 5: builtin types + the swf_field_types filter are ready
		// before Blocks\Registrar reads them on the default init priority.
		add_action( 'init', array( $this, 'load_types' ), 5 );
	}

	/**
	 * Populates the registry. Public (not just hooked) so tests and other
	 * services can force-load it without firing `init`.
	 */
	public function load_types(): void {
		if ( $this->loaded ) {
			return;
		}

		$this->loaded = true;

		foreach ( $this->builtin_types() as $field_type ) {
			$this->register_type( $field_type );
		}

		/**
		 * Filters the map of registered field types.
		 *
		 * @param array<string, FieldType> $types Type key => FieldType.
		 */
		$this->types = (array) apply_filters( 'swf_field_types', $this->types );
	}

	/**
	 * Registers (or overwrites) one field type.
	 */
	public function register_type( FieldType $field_type ): void {
		$this->types[ $field_type->type ] = $field_type;
	}

	/**
	 * Fetches a field type by key.
	 */
	public function get( string $type ): ?FieldType {
		return $this->types[ $type ] ?? null;
	}

	/**
	 * Whether a type is registered.
	 */
	public function has( string $type ): bool {
		return isset( $this->types[ $type ] );
	}

	/**
	 * Every registered field type.
	 *
	 * @return array<string, FieldType>
	 */
	public function all(): array {
		return $this->types;
	}

	/**
	 * Serializes the registry for `window.swfFieldConfig`, consumed by the
	 * JS field-block factory so PHP stays authoritative over the attribute
	 * schema without a separate generation step. Only what the factory
	 * actually reads is exported — the block's icon/title/category come from
	 * its own block.json.
	 *
	 * @return array<string, mixed>
	 */
	public function to_js_config(): array {
		$config = array();

		foreach ( $this->types as $type => $field_type ) {
			$config[ $type ] = array(
				'label'      => $field_type->label,
				'attributes' => $field_type->full_attribute_schema(),
			);
		}

		return $config;
	}

	/**
	 * The 14 built-in field types.
	 *
	 * @return FieldType[]
	 */
	private function builtin_types(): array {
		return array(
			Types\TextField::define(),
			Types\EmailField::define(),
			Types\TextareaField::define(),
			Types\TelField::define(),
			Types\UrlField::define(),
			Types\NumberField::define(),
			Types\DateField::define(),
			Types\SelectField::define(),
			Types\RadioField::define(),
			Types\CheckboxField::define(),
			Types\FileField::define(),
			Types\HiddenField::define(),
			Types\ConsentField::define(),
			Types\RatingField::define(),
		);
	}
}
