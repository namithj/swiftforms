<?php
/**
 * The only door to the block builder.
 *
 * @package SwiftForms
 */

namespace SwiftForms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Builder_Adapter
 *
 * The only class that references the Strauss-prefixed block builder, and the
 * place that holds every fallback for a framework request that has not landed
 * yet. A late framework fix replaces a fallback here without touching stored
 * data.
 *
 * Fallbacks in force (see .ai/handover.md):
 * - BB-17 (tagged builder release): composer pins dev-main at a commit.
 * - BB-18 (close the cassette-cmf/field disclosure): filter_bindings_source_value().
 * - BB-16/CMF-8 (PHP 7.4 in the frameworks): none needed here; the plugin header
 *   declares PHP 8.2 instead. On PHP below 8.2 the builder registers nothing.
 * - Builder assets URL: no filter. The prefixed copy's default, realpath based,
 *   resolution already points at vendor-prefixed/, and the filter name is
 *   unprefixed and global, so setting it would hijack any other plugin's copy.
 */
class Builder_Adapter {

	/**
	 * Name of the block bindings source the builder registers.
	 */
	const BINDINGS_SOURCE = 'cassette-cmf/field';

	/**
	 * Fields that blocks may read through the bindings source.
	 *
	 * Empty: SwiftForms never binds anything through cassette-cmf/field, so
	 * everything is refused. R01 adds names here only if a block really needs it.
	 */
	const BINDINGS_ALLOWLIST = [];

	/**
	 * Single instance.
	 *
	 * @var Builder_Adapter|null
	 */
	private static $instance = null;

	/**
	 * Get the single instance.
	 *
	 * @return Builder_Adapter
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Attach the adapter's hooks.
	 *
	 * @return void
	 */
	public function register_hooks() {
		// PHP_INT_MAX so that no other callback can undo the refusal.
		add_filter( 'block_bindings_source_value', [ $this, 'filter_bindings_source_value' ], PHP_INT_MAX, 5 );
	}

	/**
	 * BB-18 fallback: make cassette-cmf/field resolve nothing.
	 *
	 * The builder's binding source reads any option, post meta or term meta by
	 * name, so a contributor could bind a paragraph to a secret and read it in
	 * a preview. Returning null makes core keep the block's own static content.
	 * Remove this when BB-18 lands in the builder (the leak test then still
	 * guards it).
	 *
	 * @param mixed     $value          Value resolved by the source.
	 * @param string    $name           Source name.
	 * @param mixed     $source_args    The binding's arguments.
	 * @param \WP_Block $block_instance Block being rendered.
	 * @param string    $attribute_name Bound attribute.
	 * @return mixed
	 */
	public function filter_bindings_source_value( $value, $name, $source_args = [], $block_instance = null, $attribute_name = '' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Fixed hook signature.
		if ( self::BINDINGS_SOURCE !== $name ) {
			return $value;
		}

		$field = ( is_array( $source_args ) && isset( $source_args['field'] ) && is_string( $source_args['field'] ) ) ? $source_args['field'] : '';
		// @phpstan-ignore function.impossibleType (the allowlist is empty until R01 adds names)
		if ( '' !== $field && in_array( $field, self::BINDINGS_ALLOWLIST, true ) ) {
			return $value;
		}

		return null;
	}

	/**
	 * Register blocks through the block builder.
	 *
	 * @param array<string, mixed> $config Builder configuration ('blocks' => [...]).
	 * @return void
	 */
	public function register_blocks( array $config ) {
		if ( empty( $config['blocks'] ) ) {
			return;
		}

		$class = '\\SwiftForms\\Vendor\\PedalCMS\\CassetteCMFBlocks\\CassetteCMFBlocks';
		if ( ! class_exists( $class ) ) {
			return;
		}

		$class::register_from_array( $config );
	}
}
