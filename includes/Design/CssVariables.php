<?php
/**
 * CSS custom property generation for the design system.
 *
 * @package SwiftForms
 */

declare(strict_types=1);

namespace SwiftForms\Design;

use Pedalcms\CassetteCmf\CassetteCmf;
use SwiftForms\Settings\FormSettingsMetabox;
use SwiftForms\Settings\GlobalSettings;

/**
 * Turns a design settings array (per-form overrides — see get() — or the
 * global `design` section of the Settings screen) into `--swf-*` custom
 * property CSS. Every property here corresponds 1:1 to a variable defined
 * in src/css/variables.scss.
 */
final class CssVariables {

	private const SKINS = array( 'default', 'minimal', 'outlined', 'filled', 'rounded', 'dark' );

	/** Values used verbatim; radius/gap are unit-suffixed separately below. */
	private const VARS = array(
		'accent'  => '--swf-accent',
		'fieldBg' => '--swf-field-bg',
	);

	private const DENSITY_PADDING = array(
		'compact' => array(
			'y' => '0.4em',
			'x' => '0.6em',
		),
		'default' => array(
			'y' => '0.55em',
			'x' => '0.75em',
		),
		'relaxed' => array(
			'y' => '0.8em',
			'x' => '1em',
		),
	);

	/**
	 * Default per-form design values (an empty override set — the site's
	 * skin/vars apply until a form overrides them).
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'skin'          => '',
			'accent'        => '',
			'fieldBg'       => '',
			'radius'        => '',
			'gap'           => '',
			'density'       => '',
			'labelPosition' => '',
		);
	}

	/**
	 * Global design defaults, used as the site-wide baseline every form
	 * inherits unless it overrides a value.
	 *
	 * @return array<string, mixed>
	 */
	public static function global_defaults(): array {
		return array(
			'skin'          => 'default',
			'accent'        => '#2563eb',
			'fieldBg'       => '#ffffff',
			'radius'        => '6',
			'gap'           => '1.25',
			'density'       => 'default',
			'labelPosition' => 'top',
		);
	}

	/**
	 * Resolves one form's design overrides: every field the "Design" tab of
	 * the Settings meta box exposes (Design\DesignSystem::inject_form_design_tab()),
	 * read back from its own `_smartlogix_swiftforms_design_{key}` post meta, plus `gap` —
	 * not exposed as an editable field, so it always stays at its default.
	 *
	 * @param int $form_id Form post id.
	 * @return array<string, mixed>
	 */
	public static function get( int $form_id ): array {
		$design = array();

		foreach ( self::defaults() as $key => $default ) {
			$design[ $key ] = 'gap' === $key
				? $default
				: CassetteCmf::get_post_field( FormSettingsMetabox::design_meta_key( $key ), $form_id, $default );
		}

		return $design;
	}

	/**
	 * Resolves the one skin class a rendered form receives. A block style is
	 * the most specific choice, followed by the per-form override and site default.
	 */
	public static function resolve_skin( string $block_classes, string $form_skin, string $site_skin ): string {
		$classes = preg_split( '/\\s+/', $block_classes );
		foreach ( false === $classes ? array() : $classes as $class ) {
			if ( str_starts_with( $class, 'is-style-' ) && in_array( substr( $class, 9 ), self::SKINS, true ) ) {
				return substr( $class, 9 );
			}
		}

		return in_array( $form_skin, self::SKINS, true ) ? $form_skin : ( in_array( $site_skin, self::SKINS, true ) ? $site_skin : 'default' );
	}

	/**
	 * Renders `.swf-form { --swf-x: y; }` for the site-wide design defaults,
	 * layered under skins and per-form overrides via source order.
	 */
	public function global_css(): string {
		$settings = GlobalSettings::instance();
		$defaults = self::global_defaults();

		$design = array(
			'skin'          => (string) $settings->get( 'designSkin', $defaults['skin'] ),
			'accent'        => (string) $settings->get( 'designAccent', $defaults['accent'] ),
			'fieldBg'       => (string) $settings->get( 'designFieldBg', $defaults['fieldBg'] ),
			'radius'        => (string) $settings->get( 'designRadius', $defaults['radius'] ),
			'gap'           => $defaults['gap'],
			'density'       => $defaults['density'],
			'labelPosition' => (string) $settings->get( 'designLabelPosition', $defaults['labelPosition'] ),
		);

		return $this->rule_set( '.swf-form', $design );
	}

	/**
	 * Renders the inline `style` attribute value for one form's wrapper,
	 * containing only the variables that form actually overrides.
	 *
	 * @param array<string, mixed> $design Sanitized per-form design array.
	 */
	public function form_inline_style( array $design ): string {
		$overrides = array_filter(
			$design,
			static fn( $value, $key ) => 'skin' !== $key && '' !== (string) $value,
			ARRAY_FILTER_USE_BOTH
		);

		if ( ! $overrides ) {
			return '';
		}

		return $this->declarations( $overrides );
	}

	/**
	 * Builds a `selector { …declarations… }` rule from a design array.
	 *
	 * @param string                $selector CSS selector.
	 * @param array<string, mixed>  $design   Design array.
	 */
	private function rule_set( string $selector, array $design ): string {
		$declarations = $this->declarations( $design );

		return $declarations ? sprintf( '%s { %s }', $selector, $declarations ) : '';
	}

	/**
	 * Turns a design array into `--swf-x: y;` declarations.
	 *
	 * @param array<string, mixed> $design Design array.
	 */
	private function declarations( array $design ): string {
		$declarations = array();

		foreach ( self::VARS as $key => $var ) {
			if ( ! empty( $design[ $key ] ) ) {
				$declarations[] = sprintf( '%s: %s;', $var, esc_html( (string) $design[ $key ] ) );
			}
		}

		if ( ! empty( $design['radius'] ) ) {
			$declarations[] = sprintf( '--swf-radius: %spx;', (float) $design['radius'] );
		}

		if ( ! empty( $design['gap'] ) ) {
			$declarations[] = sprintf( '--swf-gap: %srem;', (float) $design['gap'] );
		}

		if ( ! empty( $design['density'] ) && isset( self::DENSITY_PADDING[ $design['density'] ] ) ) {
			$padding        = self::DENSITY_PADDING[ $design['density'] ];
			$declarations[] = sprintf( '--swf-field-padding-y: %s;', $padding['y'] );
			$declarations[] = sprintf( '--swf-field-padding-x: %s;', $padding['x'] );
		}

		return implode( ' ', $declarations );
	}
}
