<?php
/**
 * Server-side render for the `swf/form` embed block.
 *
 * @package SwiftForms
 */

declare(strict_types=1);

namespace SwiftForms\Blocks;

use SwiftForms\Design\CssVariables;
use SwiftForms\PostTypes;
use SwiftForms\Settings\FormSettings;
use SwiftForms\Settings\GlobalSettings;
use SwiftForms\Submissions\Captcha;
use SwiftForms\Submissions\NonceGuard;
use SwiftForms\Submissions\TimeTrap;
use WP_Block;

/**
 * Renders a saved `swf_form` post's field blocks (via `do_blocks()`, so the
 * form post itself is the single source of truth for both editor and
 * frontend) wrapped in the `<form>` element the security pipeline and
 * view.js expect: honeypot, time-trap, optional CAPTCHA/Turnstile, nonce.
 */
final class FormRenderer {

	public function __construct(
		private NonceGuard $nonce_guard = new NonceGuard(),
		private CssVariables $css_variables = new CssVariables()
	) {
	}

	/**
	 * Block render callback.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	public function render( array $attributes, string $content, WP_Block $block ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- required by the block render_callback signature.
		unset( $content );

		$form_id = (int) ( $attributes['formId'] ?? 0 );

		if ( $form_id <= 0 || PostTypes::FORM_POST_TYPE !== get_post_type( $form_id ) ) {
			return current_user_can( 'edit_posts' )
				? '<p>' . esc_html__( 'SwiftForms: no form selected.', 'swiftforms' ) . '</p>'
				: '';
		}

		$form_post = get_post( $form_id );

		if ( ! $form_post || 'trash' === $form_post->post_status ) {
			return '';
		}

		$settings = FormSettings::get( $form_id );
		$design   = CssVariables::get( $form_id );
		$skin     = CssVariables::resolve_skin(
			(string) ( $attributes['className'] ?? '' ),
			(string) $design['skin'],
			(string) GlobalSettings::instance()->get( 'designSkin', 'default' )
		);

		$this->enqueue_frontend_assets( $settings );

		$fields_html = do_blocks( $form_post->post_content );

		$style = $this->css_variables->form_inline_style( $design );

		$wrapper_attrs = array(
			'class'                => 'swf-form is-style-' . $skin,
			'novalidate'           => 'novalidate',
			'data-swf-form'        => '',
			'data-form-id'         => (string) $form_id,
			'data-success-message' => $settings['successMessage'],
			'data-swf-labels'      => '' !== $design['labelPosition'] ? $design['labelPosition'] : 'top',
		);

		if ( $settings['redirectUrl'] ) {
			$wrapper_attrs['data-redirect-url'] = $settings['redirectUrl'];
		}

		if ( $style ) {
			$wrapper_attrs['style'] = $style;
		}

		$html  = '<form ' . get_block_wrapper_attributes( $wrapper_attrs ) . '>';
		$html .= '<div class="swf-form__status" data-swf-status aria-live="polite"></div>';
		$html .= '<div class="swf-form__fields">' . $fields_html . '</div>';
		$html .= $this->honeypot();
		$html .= $this->hidden_field( 'render_ts', TimeTrap::build() );
		$html .= $this->hidden_field( 'nonce', $this->nonce_guard->create() );

		if ( $settings['enableCaptcha'] ) {
			$html .= $this->captcha();
		}

		if ( $settings['enableTurnstile'] ) {
			$html .= $this->turnstile();
		}

		$html .= '<div class="swf-form__actions">';
		$html .= sprintf(
			'<button type="submit" class="swf-form__submit">%s</button>',
			esc_html( $settings['submitLabel'] )
		);
		$html .= '</div>';
		$html .= '</form>';

		return $html;
	}

	/**
	 * Attaches the localized submit config to the form block's auto-enqueued
	 * `viewScript` (WP core enqueues `swf-form-style`/`swf-form-view-script`
	 * itself, driven by block.json, right after this render callback
	 * returns — see WP_Block::render()). Only attached once, even with
	 * multiple forms on one page.
	 *
	 * @param array<string, mixed> $settings Resolved form settings.
	 */
	private function enqueue_frontend_assets( array $settings ): void {
		static $localized = false;

		if ( ! $localized ) {
			$localized = true;

			wp_add_inline_script(
				'swf-form-view-script',
				'window.swfFormSettings = ' . wp_json_encode(
					array(
						'restUrl' => esc_url_raw( rest_url( 'swf/v1/submit' ) ),
						'i18n'    => array(
							'genericError' => __( 'Something went wrong. Please try again.', 'swiftforms' ),
							'required'     => __( 'This field is required.', 'swiftforms' ),
							'next'         => __( 'Next', 'swiftforms' ),
							'previous'     => __( 'Previous', 'swiftforms' ),
							/* translators: 1: current step number, 2: total step count. Substituted in view.js. */
							'stepProgress' => __( 'Step %1$d of %2$d', 'swiftforms' ),
						),
					)
				) . ';',
				'before'
			);
		}

		if ( $settings['enableTurnstile'] && '' !== (string) GlobalSettings::instance()->get( 'turnstileSiteKey', '' ) ) {
			// phpcs:ignore PluginCheck.CodeAnalysis.EnqueuedResourceOffloading.OffloadedContent -- opt-in, documented Cloudflare Turnstile service.
			// phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- external service manages its own versioning.
			wp_enqueue_script( 'swf-turnstile', 'https://challenges.cloudflare.com/turnstile/v0/api.js', array(), null, array( 'strategy' => 'defer' ) );
		}
	}

	/**
	 * The honeypot field: bots fill it in, humans never see it.
	 */
	private function honeypot(): string {
		return '<div class="swf-form__honeypot" aria-hidden="true">'
			. '<input type="text" name="swf_hp" tabindex="-1" autocomplete="off" data-swf-honeypot>'
			. '</div>';
	}

	/**
	 * A hidden `<input>`.
	 */
	private function hidden_field( string $name, string $value ): string {
		return sprintf( '<input type="hidden" name="%1$s" value="%2$s">', esc_attr( $name ), esc_attr( $value ) );
	}

	/**
	 * The math CAPTCHA markup — the answer itself never reaches the browser.
	 */
	private function captcha(): string {
		$challenge = Captcha::build();
		$field_id  = wp_unique_id( 'swf-captcha-answer-' );

		return sprintf(
			'<div class="swf-form__captcha"><label class="swf-field__label" for="%1$s">%2$s</label> '
				. '<input type="text" inputmode="numeric" id="%1$s" name="captcha_answer" class="swf-field__control" required>'
				. '<input type="hidden" name="captcha_token" value="%3$s"></div>',
			esc_attr( $field_id ),
			/* translators: 1: first number, 2: second number. */
			esc_html( sprintf( __( 'What is %1$d + %2$d?', 'swiftforms' ), $challenge['a'], $challenge['b'] ) ),
			esc_attr( $challenge['token'] )
		);
	}

	/**
	 * The Cloudflare Turnstile widget container.
	 */
	private function turnstile(): string {
		$site_key = (string) GlobalSettings::instance()->get( 'turnstileSiteKey', '' );

		if ( '' === $site_key ) {
			return '';
		}

		return sprintf( '<div class="cf-turnstile" data-sitekey="%s"></div>', esc_attr( $site_key ) );
	}
}
