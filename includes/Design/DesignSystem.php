<?php
/**
 * Global design defaults: schema tab wiring + frontend CSS injection.
 *
 * @package SwiftForms
 */

declare(strict_types=1);

namespace SwiftForms\Design;

use SwiftForms\Registrable;
use SwiftForms\Settings\FormSettingsMetabox;

/**
 * Adds the "Design" section to the global settings schema (site-wide
 * defaults every form inherits unless it overrides them — see
 * CssVariables::global_css()) and injects that CSS whenever the form
 * stylesheet is enqueued. Also fills in the per-form Settings meta box's
 * own "Design" tab, where a form can override any of those site defaults
 * for itself (see CssVariables::form_inline_style()/get()).
 */
final class DesignSystem implements Registrable {

	public function __construct( private CssVariables $css_variables ) {
	}

	public function register(): void {
		add_filter( 'swf_settings_schema', array( $this, 'inject_design_tab' ) );
		add_filter( 'swf_form_settings_schema', array( $this, 'inject_form_design_tab' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_global_css' ), 20 );
	}

	/**
	 * Fills in the otherwise-empty "design" tab with the site-wide design
	 * defaults, in the Cassette-CMF field shape used by
	 * Settings\GlobalSettingsPage (a flat list of field configs per tab).
	 *
	 * @param array<string, array<string, mixed>> $tabs Existing tab definitions.
	 * @return array<string, array<string, mixed>>
	 */
	public function inject_design_tab( array $tabs ): array {
		$defaults = CssVariables::global_defaults();

		$tabs['design']['fields'] = array(
			array(
				'name'    => 'designSkin',
				'type'    => 'select',
				'label'   => __( 'Default skin', 'swiftforms' ),
				'default' => $defaults['skin'],
				'options' => array(
					'default'  => __( 'Default', 'swiftforms' ),
					'minimal'  => __( 'Minimal', 'swiftforms' ),
					'outlined' => __( 'Outlined', 'swiftforms' ),
					'filled'   => __( 'Filled', 'swiftforms' ),
					'rounded'  => __( 'Rounded', 'swiftforms' ),
					'dark'     => __( 'Dark', 'swiftforms' ),
				),
			),
			array(
				'name'    => 'designAccent',
				'type'    => 'color',
				'label'   => __( 'Accent color', 'swiftforms' ),
				'default' => $defaults['accent'],
			),
			array(
				'name'    => 'designFieldBg',
				'type'    => 'color',
				'label'   => __( 'Field background', 'swiftforms' ),
				'default' => $defaults['fieldBg'],
			),
			array(
				'name'    => 'designRadius',
				'type'    => 'number',
				'label'   => __( 'Corner radius (px)', 'swiftforms' ),
				'default' => (int) $defaults['radius'],
				'min'     => 0,
				'max'     => 40,
			),
			array(
				'name'    => 'designLabelPosition',
				'type'    => 'select',
				'label'   => __( 'Label position', 'swiftforms' ),
				'default' => $defaults['labelPosition'],
				'options' => array(
					'top'    => __( 'Top', 'swiftforms' ),
					'left'   => __( 'Left', 'swiftforms' ),
					'hidden' => __( 'Hidden', 'swiftforms' ),
				),
			),
		);

		return $tabs;
	}

	/**
	 * Fills in the otherwise-empty "design" tab on the per-form Settings
	 * meta box with per-form overrides of the site-wide design defaults.
	 * Every field defaults to blank ("use the site default" —
	 * CssVariables::defaults(), not global_defaults()) and, for the three
	 * selects, has an explicit blank option: a plain HTML `<select>` always
	 * submits *some* option, so without one a form would silently pick up
	 * whichever option renders first the moment its settings are next saved.
	 *
	 * @param array<string, array<string, mixed>> $tabs Existing tab definitions.
	 * @return array<string, array<string, mixed>>
	 */
	public function inject_form_design_tab( array $tabs ): array {
		$defaults         = CssVariables::defaults();
		$use_site_default = array( '' => __( 'Use site default', 'swiftforms' ) );

		$tabs['design']['fields'] = array(
			array(
				'name'    => FormSettingsMetabox::design_meta_key( 'skin' ),
				'type'    => 'select',
				'label'   => __( 'Skin', 'swiftforms' ),
				'default' => $defaults['skin'],
				'options' => $use_site_default + array(
					'default'  => __( 'Default', 'swiftforms' ),
					'minimal'  => __( 'Minimal', 'swiftforms' ),
					'outlined' => __( 'Outlined', 'swiftforms' ),
					'filled'   => __( 'Filled', 'swiftforms' ),
					'rounded'  => __( 'Rounded', 'swiftforms' ),
					'dark'     => __( 'Dark', 'swiftforms' ),
				),
			),
			array(
				'name'    => FormSettingsMetabox::design_meta_key( 'accent' ),
				'type'    => 'color',
				'label'   => __( 'Accent color', 'swiftforms' ),
				'default' => $defaults['accent'],
			),
			array(
				'name'    => FormSettingsMetabox::design_meta_key( 'fieldBg' ),
				'type'    => 'color',
				'label'   => __( 'Field background', 'swiftforms' ),
				'default' => $defaults['fieldBg'],
			),
			array(
				'name'        => FormSettingsMetabox::design_meta_key( 'radius' ),
				'type'        => 'number',
				'label'       => __( 'Corner radius (px)', 'swiftforms' ),
				'default'     => $defaults['radius'],
				'min'         => 0,
				'max'         => 40,
				'description' => __( 'Leave blank to use the site default.', 'swiftforms' ),
			),
			array(
				'name'    => FormSettingsMetabox::design_meta_key( 'density' ),
				'type'    => 'select',
				'label'   => __( 'Density', 'swiftforms' ),
				'default' => $defaults['density'],
				'options' => $use_site_default + array(
					'compact' => __( 'Compact', 'swiftforms' ),
					'default' => __( 'Default', 'swiftforms' ),
					'relaxed' => __( 'Relaxed', 'swiftforms' ),
				),
			),
			array(
				'name'    => FormSettingsMetabox::design_meta_key( 'labelPosition' ),
				'type'    => 'select',
				'label'   => __( 'Label position', 'swiftforms' ),
				'default' => $defaults['labelPosition'],
				'options' => $use_site_default + array(
					'top'    => __( 'Top', 'swiftforms' ),
					'left'   => __( 'Left', 'swiftforms' ),
					'hidden' => __( 'Hidden', 'swiftforms' ),
				),
			),
		);

		return $tabs;
	}

	/**
	 * Attaches the site-wide design CSS to the `swf-form-style` handle,
	 * right before it's printed, whenever a form is on the page.
	 */
	public function enqueue_global_css(): void {
		if ( ! wp_style_is( 'swf-form-style', 'enqueued' ) && ! wp_style_is( 'swf-form-style', 'registered' ) ) {
			return;
		}

		wp_add_inline_style( 'swf-form-style', $this->css_variables->global_css() );
	}
}
