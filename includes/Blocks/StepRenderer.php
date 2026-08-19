<?php
/**
 * Server-side render for the `swf/step` block.
 *
 * @package SwiftForms
 */

declare(strict_types=1);

namespace SwiftForms\Blocks;

use WP_Block;

/**
 * Wraps one step's already-rendered inner field blocks in a `<fieldset>`.
 * Step position/count is computed client-side from DOM order (view.js), so
 * this stays a plain, context-free wrapper.
 */
final class StepRenderer {

	/**
	 * Block render callback.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	public function render( array $attributes, string $content, WP_Block $block ): string {
		unset( $block );

		$title = (string) ( $attributes['title'] ?? '' );

		$wrapper_attrs = get_block_wrapper_attributes(
			array(
				'class'           => 'swf-step',
				'data-swf-step'   => '',
				'data-step-title' => $title,
			)
		);

		$legend = $title ? sprintf( '<legend class="swf-step__legend">%s</legend>', esc_html( $title ) ) : '';

		return "<fieldset {$wrapper_attrs}>{$legend}{$content}</fieldset>";
	}
}
