<?php
/**
 * The 6 preset form skins, as block styles on `smartlogix-swiftforms/form`.
 *
 * @package SwiftForms
 */

declare(strict_types=1);

namespace SwiftForms\Design;

use SwiftForms\Registrable;

/**
 * Each skin is a `.is-style-{name}` ruleset that only overrides CSS custom
 * properties (see src/css/skins/*.scss, all bundled into the form block's
 * single `smartlogix-swiftforms-form-style` stylesheet) — selectable natively via the
 * block's Styles panel, with WordPress's own hover previews.
 */
final class Skins implements Registrable {

	public function register(): void {
		// Priority 21: after Blocks\Registrar has registered `smartlogix-swiftforms/form` (priority 20).
		add_action( 'init', array( $this, 'register_block_styles' ), 21 );
	}

	public function register_block_styles(): void {
		if ( ! \WP_Block_Type_Registry::get_instance()->is_registered( 'smartlogix-swiftforms/form' ) ) {
			return;
		}

		foreach ( $this->skins() as $skin ) {
			register_block_style( 'smartlogix-swiftforms/form', $skin );
		}
	}

	/**
	 * @return array<int, array{name: string, label: string, is_default?: bool}>
	 */
	private function skins(): array {
		return array(
			array(
				'name'       => 'default',
				'label'      => __( 'Default', 'swiftforms' ),
				'is_default' => true,
			),
			array(
				'name'  => 'minimal',
				'label' => __( 'Minimal', 'swiftforms' ),
			),
			array(
				'name'  => 'outlined',
				'label' => __( 'Outlined', 'swiftforms' ),
			),
			array(
				'name'  => 'filled',
				'label' => __( 'Filled', 'swiftforms' ),
			),
			array(
				'name'  => 'rounded',
				'label' => __( 'Rounded', 'swiftforms' ),
			),
			array(
				'name'  => 'dark',
				'label' => __( 'Dark', 'swiftforms' ),
			),
		);
	}
}
