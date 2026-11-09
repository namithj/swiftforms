<?php
/**
 * Form block renderer.
 *
 * @package SwiftForms
 */

namespace SwiftForms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Form_Renderer
 *
 * Renders swiftforms/form and keeps the stack of forms that are being rendered.
 *
 * WordPress renders a block's inner blocks before it calls the block's own render
 * callback, so the field blocks have to find their form somewhere else: this class
 * pushes a Form_Context on pre_render_block (when it also decides the effective formId,
 * C-2.7, and builds the schema, C-3.9) and pops it on render_block. A field, submit or
 * confirmation block that renders with no form on the stack renders nothing.
 *
 * Output: a <form class="swiftforms-form" method="post" data-wp-interactive="swiftforms">
 * with an empty errors summary (hidden until used), a notice, the inner content, and the
 * control inputs (Form_Controls). When Form_State says "success" the fields give way to
 * the confirmation. A draft or private preview renders like a published form; only the
 * submission is refused (P4).
 */
class Form_Renderer {

	/**
	 * Open contexts, innermost last.
	 *
	 * @var Form_Context[]
	 */
	private static $stack = [];

	/**
	 * Forms rendered on this page, per effective formId.
	 *
	 * @var array<string, int>
	 */
	private static $instances = [];

	/**
	 * Block instances whose context was already popped (render_block can fire twice for one instance).
	 *
	 * @var \SplObjectStorage<\WP_Block, null>|null
	 */
	private static $ended = null;

	/**
	 * Hook the stack.
	 *
	 * @return void
	 */
	public static function init() {
		// After Source_Stack (priority -1000), so the source is already the form's own.
		add_filter( 'pre_render_block', [ self::class, 'on_pre_render_block' ], 0, 3 );
		add_filter( 'pre_render_block', [ self::class, 'on_pre_render_block_end' ], PHP_INT_MAX, 2 );
		add_filter( 'render_block', [ self::class, 'on_render_block' ], PHP_INT_MAX, 3 );
	}

	/**
	 * The form that is being rendered.
	 *
	 * @return Form_Context|null Null outside a form.
	 */
	public static function current_context() {
		$count = count( self::$stack );
		return $count > 0 ? self::$stack[ $count - 1 ] : null;
	}

	/**
	 * Forget the stack and the instance counters (tests; code that caught an exception mid-render).
	 *
	 * @return void
	 */
	public static function reset() {
		self::$stack     = [];
		self::$instances = [];
		self::$ended     = null;
	}

	/**
	 * Number of open contexts (tests assert it is back to zero).
	 *
	 * @return int
	 */
	public static function depth() {
		return count( self::$stack );
	}

	/**
	 * Open a context for a form block that is about to render.
	 *
	 * @param mixed                $pre_render   Pre-render value, passed through.
	 * @param array<string, mixed> $parsed_block Parsed block.
	 * @param \WP_Block|null       $parent_block Parent block when nested (unused).
	 * @return mixed
	 */
	public static function on_pre_render_block( $pre_render, $parsed_block = [], $parent_block = null ) {
		unset( $parent_block );
		if ( is_array( $parsed_block ) && Field_Types::FORM_BLOCK === ( $parsed_block['blockName'] ?? null ) ) {
			self::$stack[] = self::build_context( $parsed_block );
		}
		return $pre_render;
	}

	/**
	 * Undo the context when a later callback short-circuited the block (render_block will not run for it).
	 *
	 * @param mixed                $pre_render   Pre-render value after every other callback.
	 * @param array<string, mixed> $parsed_block Parsed block.
	 * @return mixed
	 */
	public static function on_pre_render_block_end( $pre_render, $parsed_block = [] ) {
		if ( null !== $pre_render && is_array( $parsed_block ) && Field_Types::FORM_BLOCK === ( $parsed_block['blockName'] ?? null ) ) {
			array_pop( self::$stack );
		}
		return $pre_render;
	}

	/**
	 * Close the context of a form block that finished rendering.
	 *
	 * @param string               $content      Block output, passed through.
	 * @param array<string, mixed> $parsed_block Parsed block.
	 * @param \WP_Block|null       $instance     The block instance.
	 * @return string
	 */
	public static function on_render_block( $content, $parsed_block = [], $instance = null ) {
		if ( ! is_array( $parsed_block ) || Field_Types::FORM_BLOCK !== ( $parsed_block['blockName'] ?? null ) ) {
			return $content;
		}

		if ( ! $instance instanceof \WP_Block ) {
			array_pop( self::$stack );
			return $content;
		}

		if ( null === self::$ended ) {
			self::$ended = new \SplObjectStorage();
		}
		if ( ! self::$ended->contains( $instance ) && count( self::$stack ) > 0 ) {
			array_pop( self::$stack );
			self::$ended->attach( $instance );
		}
		return $content;
	}

	/**
	 * Build the context of a form block: effective formId, instance and schema.
	 *
	 * @param array<string, mixed> $parsed Parsed form block.
	 * @return Form_Context
	 */
	private static function build_context( array $parsed ) {
		$attrs  = isset( $parsed['attrs'] ) && is_array( $parsed['attrs'] ) ? $parsed['attrs'] : [];
		$stored = isset( $attrs['formId'] ) && is_string( $attrs['formId'] ) ? $attrs['formId'] : '';
		$ref    = (string) Source_Stack::current();

		$ordinal = Source_Stack::next_ordinal( md5( serialize_block( $parsed ) ) );
		$id      = Form_Index::ensure( $stored, $ref, $ordinal );

		self::$instances[ $id ] = ( self::$instances[ $id ] ?? 0 ) + 1;

		return new Form_Context( $id, $stored, $ref, self::$instances[ $id ], Schema_Resolver::build( $parsed, $id, $stored, $ref ), $parsed );
	}

	/**
	 * Render callback of swiftforms/form.
	 *
	 * @param array<string, mixed> $attributes Attributes prepared by the block builder (the stored ones are read).
	 * @param string               $content    Rendered inner blocks.
	 * @param \WP_Block            $block      The block instance.
	 * @return string
	 */
	public static function render_block( $attributes, $content, $block ) {
		unset( $attributes );

		$context = self::current_context();
		if ( null === $context || $context->used ) {
			// Rendered without pre_render_block (a direct $block->render() call): the inner blocks had no form, so there is nothing to wrap.
			$context = self::build_context( $block->parsed_block );
			$content = '';
		}
		$context->used = true;

		return self::render( $context, (string) $content );
	}

	/**
	 * Markup of a form.
	 *
	 * @param Form_Context $context Form.
	 * @param string       $content Rendered inner blocks.
	 * @return string
	 */
	public static function render( Form_Context $context, $content ) {
		$state = $context->state();
		$class = 'swiftforms-form swiftforms-form--labels-' . $context->label_position();

		if ( true === $state['success'] ) {
			$html = '<div ' . get_block_wrapper_attributes( [ 'class' => $class . ' swiftforms-form--submitted' ] ) . '>'
				. Confirmation_Renderer::render( $context, $state )
				. '</div>';
		} else {
			$ajax      = true === $context->attribute( 'ajax', true );
			$directive = wp_json_encode(
				[
					'formId'   => $context->form_id,
					'source'   => $context->source,
					'instance' => $context->instance,
					'ajax'     => $ajax,
					// Hours since the epoch when the token was issued (the view module refreshes a token older than 12 hours). Same for every visitor.
					'issued'   => intdiv( Clock::now(), HOUR_IN_SECONDS ),
				]
			);

			$attributes = [
				'class'               => $class,
				'method'              => 'post',
				'data-wp-interactive' => 'swiftforms',
				'data-wp-context'     => (string) $directive,
			];
			if ( $ajax ) {
				// With ajax off the form posts natively and the view module stays idle.
				$attributes['data-wp-init']                          = 'callbacks.init';
				$attributes['data-wp-on--submit']                    = 'actions.submit';
				$attributes['data-wp-on--input']                     = 'actions.input';
				$attributes['data-wp-on--focusout']                  = 'actions.focusout';
				$attributes['data-wp-on-document--visibilitychange'] = 'callbacks.visible';
				self::interactivity_state();
			}

			$html = '<form ' . get_block_wrapper_attributes( $attributes ) . '>'
				. self::errors_summary( $context, $state )
				. self::notice( $state )
				. $content
				. Form_Controls::render( $context )
				. '</form>';
		}

		/**
		 * Filters the HTML of a rendered form.
		 *
		 * Runs for the form itself and for the confirmation that replaces it after a successful
		 * submission. Return a string; anything else is ignored. Escape what you add.
		 *
		 * @since 1.0.0
		 *
		 * @param string               $html       Form HTML.
		 * @param string               $form_id    Effective formId (a UUID).
		 * @param int                  $instance   Position of this copy of the form on the page, 1 for the first.
		 * @param array<string, mixed> $attributes The form block's attributes at their resolved value (C-2.3).
		 */
		$filtered = apply_filters( 'swiftforms_render_form', $html, $context->form_id, $context->instance, $context->schema->attributes );

		return is_string( $filtered ) ? $filtered : $html;
	}

	/**
	 * Hand the view module its state (printed once in the page's script module data).
	 *
	 * Nothing here depends on the visitor except the REST nonce, which a logged-in user's page gets in the
	 * state and never in the form markup, so a guest's HTML is the same for everyone (C-7.4). The call merges,
	 * so a second form on the page changes nothing.
	 *
	 * @return void
	 */
	private static function interactivity_state() {
		if ( ! function_exists( 'wp_interactivity_state' ) ) {
			return;
		}

		$state = [
			'restUrl'   => esc_url_raw( rest_url() ),
			'submitUrl' => esc_url_raw( rest_url( 'swiftforms/v1/submit' ) ),
			'tokenUrl'  => esc_url_raw( rest_url( 'swiftforms/v1/token' ) ),
			'ajaxUrl'   => esc_url_raw( admin_url( 'admin-ajax.php' ) ),
			'messages'  => Validator::messages(),
			'i18n'      => [
				'summary'     => __( 'Please fix the following:', 'swiftforms' ),
				/* translators: 1: field label, 2: error message. */
				'summaryItem' => __( '%1$s: %2$s', 'swiftforms' ),
				'network'     => __( 'The form could not be sent. Please check your connection and try again.', 'swiftforms' ),
				'closed'      => __( 'This form is no longer accepting submissions.', 'swiftforms' ),
				'rateLimited' => __( 'Too many attempts. Please wait a moment and try again.', 'swiftforms' ),
				'blocked'     => __( 'Your submission could not be accepted. Please reload the page and try again.', 'swiftforms' ),
			],
		];
		if ( is_user_logged_in() ) {
			$state['nonce'] = wp_create_nonce( 'wp_rest' );
		}

		wp_interactivity_state( 'swiftforms', $state );
	}

	/**
	 * The errors summary: an empty hidden container, or the list of errors the no-JS path returned.
	 *
	 * @param Form_Context         $context Form.
	 * @param array<string, mixed> $state   Form_State.
	 * @return string
	 */
	private static function errors_summary( Form_Context $context, array $state ) {
		$errors = is_array( $state['errors'] ) ? $state['errors'] : [];
		$items  = '';
		foreach ( $context->schema->fields as $field ) {
			$name = $field['name'];
			if ( isset( $errors[ $name ] ) && is_string( $errors[ $name ] ) ) {
				$message = $errors[ $name ];
				$label   = isset( $field['label'] ) && is_string( $field['label'] ) ? trim( $field['label'] ) : '';
				// A consent label is a long sentence, so the message stands alone there.
				if ( '' !== $label && ( ! isset( $field['type'] ) || 'consent' !== $field['type'] ) ) {
					/* translators: 1: field label, 2: error message. */
					$message = sprintf( __( '%1$s: %2$s', 'swiftforms' ), $label, $message );
				}
				$items .= '<li><a href="#' . esc_attr( $context->element_id( $name ) ) . '">' . esc_html( $message ) . '</a></li>';
			}
		}

		if ( '' === $items ) {
			return '<div class="swiftforms-errors-summary" tabindex="-1" hidden></div>';
		}

		return '<div class="swiftforms-errors-summary" tabindex="-1" role="alert"><p>' . esc_html__( 'Please fix the following:', 'swiftforms' ) . '</p><ul>' . $items . '</ul></div>';
	}

	/**
	 * A notice above the fields ("Please submit again" and similar).
	 *
	 * @param array<string, mixed> $state Form_State.
	 * @return string
	 */
	private static function notice( array $state ) {
		if ( '' === trim( $state['notice'] ) ) {
			return '';
		}
		$type = 'info' === $state['notice_type'] ? 'info' : 'error';
		return '<div class="swiftforms-notice swiftforms-notice--' . $type . '" role="' . ( 'info' === $type ? 'status' : 'alert' ) . '">' . esc_html( $state['notice'] ) . '</div>';
	}
}
