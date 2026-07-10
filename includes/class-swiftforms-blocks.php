<?php
/**
 * Block registration for SwiftForms.
 *
 * @package SwiftForms
 */

declare(strict_types=1);

/**
 * Registers Gutenberg blocks and renders forms on the frontend.
 */
class SwiftForms_Blocks {
	/**
	 * Form embed block name.
	 */
	private const FORM_BLOCK_NAME = 'swiftforms/form';

	/**
	 * Field blocks that belong exclusively to the form builder CPT.
	 *
	 * @var string[]
	 */
	private const FIELD_BLOCK_NAMES = array(
		'swiftforms/checkbox-field',
		'swiftforms/date-field',
		'swiftforms/email-field',
		'swiftforms/hidden-field',
		'swiftforms/file-field',
		'swiftforms/number-field',
		'swiftforms/radio-field',
		'swiftforms/select-field',
		'swiftforms/tel-field',
		'swiftforms/text-field',
		'swiftforms/textarea-field',
		'swiftforms/url-field',
	);

	/**
	 * Non-field blocks allowed while composing a form post.
	 *
	 * @var string[]
	 */
	private const FORM_BUILDER_BLOCK_NAMES = array(
		'swiftforms/step',
		'core/buttons',
		'core/column',
		'core/columns',
		'core/group',
		'core/heading',
		'core/list',
		'core/paragraph',
		'core/separator',
		'core/spacer',
	);

	/**
	 * Absolute plugin path.
	 *
	 * @var string
	 */
	private string $plugin_path;

	/**
	 * Registered frontend view script handles.
	 *
	 * @var string[]
	 */
	private array $frontend_script_handles = array();

	/**
	 * Registered frontend style handles.
	 *
	 * @var string[]
	 */
	private array $frontend_style_handles = array();

	/**
	 * Stores the plugin path used to locate block metadata.
	 *
	 * @param string $plugin_path Absolute plugin directory path.
	 */
	public function __construct( string $plugin_path ) {
		$this->plugin_path = rtrim( $plugin_path, '/\\' ) . DIRECTORY_SEPARATOR;
	}

	/**
	 * Registers all supported block types from block.json metadata.
	 */
	public function register_blocks(): void {
		add_filter( 'allowed_block_types_all', array( $this, 'filter_allowed_block_types' ), 10, 2 );

		foreach ( $this->get_block_metadata_paths() as $metadata_path ) {
			if ( ! file_exists( $metadata_path . '/block.json' ) ) {
				continue;
			}

			$metadata   = wp_json_file_decode( $metadata_path . '/block.json', array( 'associative' => true ) );
			$block_name = is_array( $metadata ) && isset( $metadata['name'] ) ? (string) $metadata['name'] : '';

			if ( '' !== $block_name && WP_Block_Type_Registry::get_instance()->is_registered( $block_name ) ) {
				continue;
			}

			$block_args = array();

			if ( self::FORM_BLOCK_NAME === $block_name ) {
				$block_args['render_callback'] = array( $this, 'render_form_block' );
			} elseif ( in_array( $block_name, self::FIELD_BLOCK_NAMES, true ) ) {
				$block_args['render_callback'] = array( $this, 'render_field_block' );
			}

			$block_type = register_block_type_from_metadata( $metadata_path, $block_args );

			if ( $block_type instanceof WP_Block_Type ) {
				$translatable_handles = array_merge(
					is_array( $block_type->editor_script_handles ) ? $block_type->editor_script_handles : array(),
					is_array( $block_type->view_script_handles ) ? $block_type->view_script_handles : array()
				);

				foreach ( $translatable_handles as $handle ) {
					wp_set_script_translations( $handle, 'swiftforms', SWIFTFORMS_PATH . 'languages' );
				}
			}

			if ( $block_type instanceof WP_Block_Type && ! empty( $block_type->view_script_handles ) ) {
				$this->frontend_script_handles = array_values(
					array_unique(
						array_merge( $this->frontend_script_handles, $block_type->view_script_handles )
					)
				);
			}

			if ( $block_type instanceof WP_Block_Type && ! empty( $block_type->style_handles ) ) {
				$this->frontend_style_handles = array_values(
					array_unique(
						array_merge( $this->frontend_style_handles, $block_type->style_handles )
					)
				);
			}
		}

		if ( ! empty( $this->frontend_script_handles ) || ! empty( $this->frontend_style_handles ) ) {
			add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_settings' ) );
		}
	}

	/**
	 * Restricts field blocks to the form builder CPT while keeping the embed block available elsewhere.
	 *
	 * @param bool|string[]           $allowed_block_types Allowed block types for the current editor.
	 * @param WP_Block_Editor_Context $editor_context      Current editor context.
	 *
	 * @return string[]
	 */
	public function filter_allowed_block_types( bool|array $allowed_block_types, WP_Block_Editor_Context $editor_context ): array {
		$available_blocks = $this->resolve_available_block_types( $allowed_block_types );
		$post             = $editor_context->post ?? null;

		if ( $post instanceof WP_Post && SwiftForms_CPTs::FORM_POST_TYPE === $post->post_type ) {
			$allowed = array_merge( self::FIELD_BLOCK_NAMES, self::FORM_BUILDER_BLOCK_NAMES );

			return array_values( array_intersect( $available_blocks, $allowed ) );
		}

		return array_values( array_diff( $available_blocks, self::FIELD_BLOCK_NAMES ) );
	}

	/**
	 * Server-renders the selected saved form inside the embed block.
	 *
	 * @param array<string, mixed> $attributes Embed block attributes.
	 * @param string               $content    Saved block content for legacy fallback.
	 */
	public function render_form_block( array $attributes, string $content = '' ): string {
		$form_id = isset( $attributes['formId'] ) ? (int) $attributes['formId'] : 0;
		if ( $form_id <= 0 ) {
			return $content;
		}

		$form_post = get_post( $form_id );
		if ( ! $form_post instanceof WP_Post || SwiftForms_CPTs::FORM_POST_TYPE !== $form_post->post_type ) {
			return $content;
		}

		$fields_markup      = do_blocks( (string) $form_post->post_content );
		$wrapper_attributes = 'class="wp-block-swiftforms-form swiftforms-form"';
		$form_settings      = SwiftForms_CPTs::get_form_settings( $form_id );
		$description        = isset( $attributes['description'] ) ? (string) $attributes['description'] : '';
		$submit_label       = '' !== trim( (string) $form_settings['submitLabel'] )
			? (string) $form_settings['submitLabel']
			: ( isset( $attributes['submitLabel'] ) ? (string) $attributes['submitLabel'] : 'Send message' );
		$success_message    = '' !== trim( (string) $form_settings['successMessage'] )
			? (string) $form_settings['successMessage']
			: ( isset( $attributes['successMessage'] ) ? (string) $attributes['successMessage'] : 'Form submitted successfully.' );
		$enable_captcha     = ! empty( $form_settings['enableCaptcha'] ) || ! empty( $attributes['enableCaptcha'] );
		$redirect_url       = (string) ( $form_settings['redirectUrl'] ?? '' );
		$global_settings    = SwiftForms_Settings::get_settings();
		$turnstile_site_key = ! empty( $form_settings['enableTurnstile'] ) ? (string) $global_settings['turnstileSiteKey'] : '';
		$render_issued_at   = time();

		if ( '' !== $turnstile_site_key ) {
			wp_enqueue_script(
				'swiftforms-turnstile',
				'https://challenges.cloudflare.com/turnstile/v0/api.js',
				array(),
				null, // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Cloudflare-hosted evergreen script.
				array( 'strategy' => 'defer' )
			);
		}

		ob_start();
		?>
		<form
			<?php echo $wrapper_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			data-enable-captcha="<?php echo esc_attr( $enable_captcha ? '1' : '0' ); ?>"
			data-form-id="<?php echo esc_attr( (string) $form_id ); ?>"
			<?php if ( '' !== $redirect_url ) : ?>
			data-redirect-url="<?php echo esc_url( $redirect_url ); ?>"
			<?php endif; ?>
			data-success-message="<?php echo esc_attr( $success_message ); ?>"
			data-swiftforms-form
			novalidate
		>
			<?php if ( '' !== $description ) : ?>
				<p class="swiftforms-form__description"><?php echo wp_kses_post( $description ); ?></p>
			<?php endif; ?>
			<div class="swiftforms-form__status" data-swiftforms-status aria-live="polite"></div>
			<div class="swiftforms-form__fields"><?php echo $fields_markup; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
			<input
				aria-hidden="true"
				autocomplete="off"
				class="swiftforms-form__honeypot"
				data-swiftforms-honeypot
				name="swiftforms_hp"
				style="display:none"
				tabindex="-1"
				type="text"
			/>
			<input
				data-swiftforms-render-ts
				name="render_ts"
				type="hidden"
				value="<?php echo esc_attr( $render_issued_at . '.' . SwiftForms_Submissions::hash_render_timestamp( $render_issued_at ) ); ?>"
			/>
			<?php if ( $enable_captcha ) : ?>
				<?php $captcha = $this->build_captcha(); ?>
				<div class="swiftforms-form__captcha" data-swiftforms-captcha>
					<label class="swiftforms-form__captcha-label">
						<span class="swiftforms-form__captcha-question"><?php echo esc_html( sprintf( '%d + %d = ?', $captcha['a'], $captcha['b'] ) ); ?></span>
						<input
							class="swiftforms-form__captcha-input"
							data-swiftforms-captcha-answer
							inputmode="numeric"
							name="captcha_answer"
							required
							type="number"
						/>
					</label>
					<input
						data-swiftforms-captcha-token
						name="captcha_token"
						type="hidden"
						value="<?php echo esc_attr( $captcha['token'] ); ?>"
					/>
				</div>
			<?php endif; ?>
			<?php if ( '' !== $turnstile_site_key ) : ?>
				<div class="cf-turnstile" data-sitekey="<?php echo esc_attr( $turnstile_site_key ); ?>"></div>
			<?php endif; ?>
			<button type="submit" class="swiftforms-form__submit"><?php echo esc_html( $submit_label ); ?></button>
		</form>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Builds a fresh math captcha challenge for the current render.
	 *
	 * The expected sum never reaches the browser; only its HMAC token does. The
	 * matching verification lives in SwiftForms_Submissions::validate_captcha().
	 *
	 * @return array{a:int,b:int,token:string}
	 */
	private function build_captcha(): array {
		$a         = wp_rand( 1, 9 );
		$b         = wp_rand( 1, 9 );
		$issued_at = time();

		return array(
			'a'     => $a,
			'b'     => $b,
			'token' => $issued_at . '.' . SwiftForms_Submissions::hash_captcha_answer( $a + $b, $issued_at ),
		);
	}

	/**
	 * Server-renders an individual field block and exposes its HTML to a type-specific filter.
	 *
	 * @param array<string, mixed> $attributes Field block attributes.
	 * @param string               $content    Saved block HTML.
	 * @param WP_Block|null        $block      Block instance being rendered.
	 */
	public function render_field_block( array $attributes, string $content, ?WP_Block $block = null ): string {
		$block_name = $block instanceof WP_Block ? $block->name : '';
		$field_type = $this->get_field_type_from_block_name( $block_name );

		if ( '' === $field_type ) {
			return $content;
		}

		// Conditional logic rules live in the block comment attributes; the
		// frontend engine needs them on the rendered markup, so they are
		// injected here rather than baked into save() (which would invalidate
		// existing saved content).
		$conditions = SwiftForms_Conditions::sanitize( $attributes['conditions'] ?? array() );

		if ( ! empty( $conditions ) ) {
			$processor = new WP_HTML_Tag_Processor( $content );

			if ( $processor->next_tag() ) {
				$processor->set_attribute( 'data-sf-conditions', (string) wp_json_encode( $conditions ) );
				$content = $processor->get_updated_html();
			}
		}

		return (string) apply_filters(
			'swiftforms_field_html_' . $field_type,
			$content,
			$attributes,
			$block_name
		);
	}

	/**
	 * Injects frontend runtime configuration before the form view script executes.
	 */
	public function enqueue_frontend_settings(): void {
		wp_enqueue_style(
			'swiftforms-form-default-style',
			SWIFTFORMS_URL . 'includes/blocks/form/style.css',
			array(),
			SWIFTFORMS_VERSION
		);

		foreach ( $this->frontend_style_handles as $handle ) {
			wp_enqueue_style( $handle );
		}

		$config = wp_json_encode(
			array(
				'action'  => 'swiftforms_submit',
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'i18n'    => array(
					'genericError' => __( 'Submission failed.', 'swiftforms' ),
					'required'     => __( 'This field is required.', 'swiftforms' ),
					'next'         => __( 'Next', 'swiftforms' ),
					'previous'     => __( 'Back', 'swiftforms' ),
					/* translators: 1: current step number, 2: total step count. */
					'stepProgress' => __( 'Step %1$s of %2$s', 'swiftforms' ),
				),
				'nonce'   => wp_create_nonce( 'swiftforms_ajax' ),
				'restUrl' => esc_url_raw( rest_url( 'swiftforms/v1/submit' ) ),
			)
		);

		if ( ! $config ) {
			return;
		}

		foreach ( $this->frontend_script_handles as $handle ) {
			wp_add_inline_script( $handle, 'window.swiftformsSettings = ' . $config . ';', 'before' );
		}
	}

	/**
	 * Returns metadata directories for the first shipped block set.
	 *
	 * @return string[]
	 */
	public function get_block_metadata_paths(): array {
		return array(
			$this->plugin_path . 'includes/blocks/fields/checkbox',
			$this->plugin_path . 'includes/blocks/fields/date',
			$this->plugin_path . 'includes/blocks/fields/hidden',
			$this->plugin_path . 'includes/blocks/fields/radio',
			$this->plugin_path . 'includes/blocks/step',
			$this->plugin_path . 'includes/blocks/form',
			$this->plugin_path . 'includes/blocks/fields/file',
			$this->plugin_path . 'includes/blocks/fields/number',
			$this->plugin_path . 'includes/blocks/fields/select',
			$this->plugin_path . 'includes/blocks/fields/tel',
			$this->plugin_path . 'includes/blocks/fields/text',
			$this->plugin_path . 'includes/blocks/fields/email',
			$this->plugin_path . 'includes/blocks/fields/textarea',
			$this->plugin_path . 'includes/blocks/fields/url',
		);
	}

	/**
	 * Resolves the available block names from the current editor allow-list.
	 *
	 * @param bool|string[] $allowed_block_types Allowed block types from the editor.
	 *
	 * @return string[]
	 */
	private function resolve_available_block_types( bool|array $allowed_block_types ): array {
		if ( is_array( $allowed_block_types ) ) {
			return array_values( array_unique( $allowed_block_types ) );
		}

		if ( true === $allowed_block_types ) {
			return array_keys( WP_Block_Type_Registry::get_instance()->get_all_registered() );
		}

		return array();
	}

	/**
	 * Maps a block name to the hook suffix used for field HTML filters.
	 *
	 * @param string $block_name Registered block name.
	 */
	private function get_field_type_from_block_name( string $block_name ): string {
		return match ( $block_name ) {
			'swiftforms/checkbox-field' => 'checkbox',
			'swiftforms/date-field' => 'date',
			'swiftforms/hidden-field' => 'hidden',
			'swiftforms/radio-field' => 'radio',
			'swiftforms/email-field' => 'email',
			'swiftforms/file-field' => 'file',
			'swiftforms/number-field' => 'number',
			'swiftforms/select-field' => 'select',
			'swiftforms/tel-field' => 'tel',
			'swiftforms/text-field' => 'text',
			'swiftforms/textarea-field' => 'textarea',
			'swiftforms/url-field' => 'url',
			default => '',
		};
	}
}