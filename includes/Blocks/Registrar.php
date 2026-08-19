<?php
/**
 * Block registration.
 *
 * @package SwiftForms
 */

declare(strict_types=1);

namespace SwiftForms\Blocks;

use SwiftForms\Fields\FieldRegistry;
use SwiftForms\Fields\Renderer;
use SwiftForms\PostTypes;
use SwiftForms\Registrable;
use WP_Block_Type_Registry;

/**
 * Registers `swf/form`, `swf/step`, and one `swf/field-{type}` block per
 * FieldRegistry entry. Field blocks are all dynamic — their attribute
 * schema is injected here (via `block_type_metadata`) from FieldRegistry,
 * the single source of truth also consumed by the JS field-block factory.
 */
final class Registrar implements Registrable {

	private const FIELD_PREFIX = 'swf/field-';

	public function __construct( private FieldRegistry $field_registry ) {
	}

	public function register(): void {
		add_filter( 'block_categories_all', array( $this, 'register_categories' ) );
		add_filter( 'block_type_metadata', array( $this, 'inject_field_attributes' ) );
		add_filter( 'allowed_block_types_all', array( $this, 'restrict_editor_blocks' ), 10, 2 );

		// Priority 20: after FieldRegistry has loaded (priority 5) and
		// PostTypes has registered the CPTs (default priority 10).
		add_action( 'init', array( $this, 'register_blocks' ), 20 );
	}

	/**
	 * Adds the SwiftForms block categories.
	 *
	 * @param array<int, array<string, mixed>> $categories Existing categories.
	 * @return array<int, array<string, mixed>>
	 */
	public function register_categories( array $categories ): array {
		return array_merge(
			array(
				array(
					'slug'  => 'swf',
					'title' => __( 'SwiftForms', 'swiftforms' ),
				),
				array(
					'slug'  => 'swf-fields',
					'title' => __( 'Form Fields', 'swiftforms' ),
				),
			),
			$categories
		);
	}

	/**
	 * Merges a field block's shared + type-specific attribute schema into
	 * its block.json metadata before registration.
	 *
	 * @param array<string, mixed> $metadata Parsed block.json.
	 * @return array<string, mixed>
	 */
	public function inject_field_attributes( array $metadata ): array {
		$name = (string) ( $metadata['name'] ?? '' );

		if ( ! str_starts_with( $name, self::FIELD_PREFIX ) ) {
			return $metadata;
		}

		$type       = substr( $name, strlen( self::FIELD_PREFIX ) );
		$field_type = $this->field_registry->get( $type );

		if ( ! $field_type ) {
			return $metadata;
		}

		$metadata['attributes'] = array_merge( $metadata['attributes'] ?? array(), $field_type->full_attribute_schema() );

		return $metadata;
	}

	/**
	 * Restricts which blocks are insertable: inside a `swf_form` post, only
	 * field blocks, the step block, and a handful of layout blocks; every
	 * other editor sees the field/step blocks removed (only the `swf/form`
	 * embed remains available there).
	 *
	 * @param array<int, string>|bool $allowed_blocks Allowed block names, or true for "all".
	 * @param \WP_Block_Editor_Context $context        Editor context.
	 * @return array<int, string>|bool
	 */
	public function restrict_editor_blocks( $allowed_blocks, $context ) {
		$post_type = $context->post->post_type ?? '';

		if ( PostTypes::FORM_POST_TYPE === $post_type ) {
			$field_blocks = array_map(
				static fn( string $type ) => self::FIELD_PREFIX . $type,
				array_keys( $this->field_registry->all() )
			);

			return array_merge(
				$field_blocks,
				array(
					'swf/step',
					'core/paragraph',
					'core/heading',
					'core/group',
					'core/columns',
					'core/column',
					'core/buttons',
					'core/button',
					'core/separator',
					'core/spacer',
					'core/list',
					'core/list-item',
				)
			);
		}

		$excluded = static fn( string $name ) => str_starts_with( $name, self::FIELD_PREFIX ) || 'swf/step' === $name;

		if ( true === $allowed_blocks ) {
			$all = array_keys( WP_Block_Type_Registry::get_instance()->get_all_registered() );

			return array_values( array_filter( $all, static fn( $name ) => ! $excluded( $name ) ) );
		}

		if ( is_array( $allowed_blocks ) ) {
			return array_values( array_filter( $allowed_blocks, static fn( $name ) => ! $excluded( $name ) ) );
		}

		return $allowed_blocks;
	}

	/**
	 * Registers `swf/form`, `swf/step`, and every `swf/field-{type}` block
	 * from their compiled `build/blocks/**` directories.
	 */
	public function register_blocks(): void {
		$build_path = SWF_PLUGIN_PATH . 'build/blocks/';

		if ( ! is_dir( $build_path ) ) {
			return; // Assets not built yet (fresh checkout before `npm run build`).
		}

		$field_renderer = new Renderer( $this->field_registry );

		$form_dir = $build_path . 'form';
		if ( file_exists( $form_dir . '/block.json' ) ) {
			register_block_type_from_metadata(
				$form_dir,
				array( 'render_callback' => array( new FormRenderer(), 'render' ) )
			);
			wp_set_script_translations( 'swf-form-view-script', 'swiftforms' );
		}

		$step_dir = $build_path . 'step';
		if ( file_exists( $step_dir . '/block.json' ) ) {
			register_block_type_from_metadata(
				$step_dir,
				array( 'render_callback' => array( new StepRenderer(), 'render' ) )
			);
		}

		$field_config = (string) wp_json_encode( $this->field_registry->to_js_config() );

		foreach ( array_keys( $this->field_registry->all() ) as $type ) {
			$dir = $build_path . 'fields/' . $type;

			if ( ! file_exists( $dir . '/block.json' ) ) {
				continue;
			}

			$block_type = register_block_type_from_metadata(
				$dir,
				array(
					'render_callback' => static fn( array $attributes ) => $field_renderer->render( $type, $attributes ),
				)
			);

			foreach ( (array) ( $block_type->editor_script_handles ?? array() ) as $handle ) {
				wp_add_inline_script( $handle, "window.swfFieldConfig = {$field_config};", 'before' );
			}
		}
	}
}
