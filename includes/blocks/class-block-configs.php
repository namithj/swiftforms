<?php
/**
 * Block configurations for the block builder.
 *
 * @package SwiftForms
 */

namespace SwiftForms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Block_Configs
 *
 * The one place where the SwiftForms blocks are described to the block builder:
 * the form, the twelve field blocks, the submit button and the confirmation, the
 * "Forms" block category and the six patterns.
 *
 * Names, attributes and defaults come from Field_Types (the single source of truth for
 * C-2.3 to C-2.5). Each attribute is declared once: by an inspector control when it has
 * one, otherwise as a plain attribute (the "attributes" key of the builder config), with
 * the type, default and enum of Field_Types either way. BlockConfigsTest proves that the
 * attribute schema the builder derives equals Field_Types for every block.
 *
 * Every block is dynamic. The canvas shows a client-side preview (markup trees below)
 * with real, disabled inputs and no network request. The attributes the PHP renderers
 * read are the stored ones, resolved against Field_Types (see Form_Context), not the
 * copy the builder sanitises for its own templates.
 */
class Block_Configs {

	/**
	 * Slug of the block category and of the pattern category.
	 */
	const CATEGORY = 'swiftforms';

	/**
	 * Dashicon per block.
	 */
	const ICONS = [
		'form'         => 'feedback',
		'submit'       => 'migrate',
		'confirmation' => 'yes',
		'text'         => 'editor-textcolor',
		'email'        => 'email',
		'textarea'     => 'editor-paragraph',
		'number'       => 'editor-ol',
		'url'          => 'admin-links',
		'tel'          => 'phone',
		'date'         => 'calendar-alt',
		'select'       => 'arrow-down-alt2',
		'radio'        => 'marker',
		'checkboxes'   => 'yes-alt',
		'consent'      => 'shield',
		'hidden'       => 'hidden',
	];

	/**
	 * The whole builder configuration.
	 *
	 * @return array<string, mixed>
	 */
	public static function all() {
		$blocks = [ self::form(), self::submit(), self::confirmation() ];
		foreach ( Field_Types::types() as $type ) {
			$blocks[] = self::field( $type );
		}

		return [
			'blocks'             => $blocks,
			'block_categories'   => [
				[
					'slug'  => self::CATEGORY,
					'title' => __( 'Forms', 'swiftforms' ),
					'icon'  => 'feedback',
				],
			],
			'pattern_categories' => [
				[
					'slug'  => self::CATEGORY,
					'label' => __( 'Forms', 'swiftforms' ),
				],
			],
			'patterns'           => Pattern_Configs::all(),
		];
	}

	/**
	 * Blocks allowed inside the form (R01 2.1).
	 *
	 * @return string[]
	 */
	public static function form_children() {
		$blocks = [];
		foreach ( Field_Types::types() as $type ) {
			$blocks[] = Field_Types::block_name( $type );
		}
		return array_merge(
			$blocks,
			[
				Field_Types::SUBMIT_BLOCK,
				Field_Types::CONFIRMATION_BLOCK,
				'core/columns',
				'core/column',
				'core/group',
				'core/paragraph',
				'core/heading',
				'core/separator',
				'core/spacer',
				'core/image',
				'core/list',
			]
		);
	}

	// ---------------------------------------------------------------------
	// Form.
	// ---------------------------------------------------------------------

	/**
	 * The form block.
	 *
	 * @return array<string, mixed>
	 */
	private static function form() {
		$attributes = Field_Types::form_attributes();

		return [
			'id'           => Field_Types::FORM_BLOCK,
			'args'         => [
				'title'            => __( 'Form', 'swiftforms' ),
				'category'         => self::CATEGORY,
				'description'      => __( 'A contact form with fields, a submit button and a confirmation message.', 'swiftforms' ),
				'icon'             => self::ICONS['form'],
				'keywords'         => [ __( 'contact', 'swiftforms' ), __( 'email', 'swiftforms' ), __( 'form', 'swiftforms' ) ],
				'textdomain'       => 'swiftforms',
				'providesContext'  => [
					'swiftforms/formId'            => 'formId',
					'swiftforms/labelPosition'     => 'labelPosition',
					'swiftforms/requiredIndicator' => 'requiredIndicator',
				],
				'supports'         => [
					'anchor'        => true,
					'className'     => true,
					'color'         => [
						'background' => true,
						'text'       => true,
					],
					'spacing'       => [
						'padding' => true,
						'margin'  => true,
					],
					'typography'    => [
						'fontSize'   => true,
						'lineHeight' => true,
					],
					'border'        => [
						'color'  => true,
						'radius' => true,
						'style'  => true,
						'width'  => true,
					],
					'interactivity' => true,
				],
				'editorScript'     => Assets::EDITOR_SCRIPT,
				'editorStyle'      => [ Assets::STYLE, Assets::EDITOR_STYLE ],
				'viewStyle'        => Assets::STYLE,
				'viewScriptModule' => Assets::VIEW_MODULE,
			],
			'fields'       => [
				[
					'name' => 'formId',
					'type' => 'uuid',
				],
				[
					'name'         => 'panel_form',
					'type'         => 'panel',
					'title'        => __( 'Form', 'swiftforms' ),
					'initial_open' => true,
					'fields'       => [
						[
							'name'        => 'title',
							'type'        => 'text',
							'label'       => __( 'Form title', 'swiftforms' ),
							'description' => __( 'Shown in entries and emails. It is not displayed on the page.', 'swiftforms' ),
						],
						[
							'name'    => 'confirmationType',
							'type'    => 'select',
							'label'   => __( 'After submitting', 'swiftforms' ),
							'default' => $attributes['confirmationType']['default'],
							'options' => [
								[
									'value' => 'message',
									'label' => __( 'Show the confirmation message', 'swiftforms' ),
								],
								[
									'value' => 'redirect',
									'label' => __( 'Go to a page or address', 'swiftforms' ),
								],
							],
						],
						[
							'name'        => 'redirectPageId',
							'type'        => 'post_select',
							'label'       => __( 'Page to go to', 'swiftforms' ),
							'post_type'   => 'page',
							'default'     => 0,
							'conditional' => self::when_all( [ self::rule( 'confirmationType', '==', 'redirect' ) ] ),
						],
						[
							'name'        => 'redirectUrl',
							'type'        => 'url',
							'label'       => __( 'Or an address (http or https)', 'swiftforms' ),
							'conditional' => self::when_all( [ self::rule( 'confirmationType', '==', 'redirect' ) ] ),
						],
						[
							'name'    => 'labelPosition',
							'type'    => 'select',
							'label'   => __( 'Label position', 'swiftforms' ),
							'default' => $attributes['labelPosition']['default'],
							'options' => [
								[
									'value' => 'top',
									'label' => __( 'Above the field', 'swiftforms' ),
								],
								[
									'value' => 'inline',
									'label' => __( 'Beside the field', 'swiftforms' ),
								],
								[
									'value' => 'hidden',
									'label' => __( 'Hidden (still read by screen readers)', 'swiftforms' ),
								],
							],
						],
						[
							'name'    => 'requiredIndicator',
							'type'    => 'select',
							'label'   => __( 'Required indicator', 'swiftforms' ),
							'default' => $attributes['requiredIndicator']['default'],
							'options' => [
								[
									'value' => 'asterisk',
									'label' => __( 'Asterisk (*)', 'swiftforms' ),
								],
								[
									'value' => 'text',
									'label' => __( 'The word "(required)"', 'swiftforms' ),
								],
								[
									'value' => 'none',
									'label' => __( 'None', 'swiftforms' ),
								],
							],
						],
					],
				],
				[
					'name'         => 'panel_notifications',
					'type'         => 'panel',
					'title'        => __( 'Notifications', 'swiftforms' ),
					'initial_open' => true,
					'fields'       => [
						[
							'name'        => 'useDefaultNotification',
							'type'        => 'toggle',
							'label'       => __( 'Email the site default recipient', 'swiftforms' ),
							'description' => __( 'The recipient set under Forms, Settings, Email.', 'swiftforms' ),
							'default'     => true,
						],
						[
							'name'    => 'notifications',
							'type'    => 'repeater',
							'label'   => __( 'More notifications', 'swiftforms' ),
							'default' => [],
							'fields'  => self::notification_fields(),
						],
					],
				],
				[
					'name'         => 'panel_advanced',
					'type'         => 'panel',
					'title'        => __( 'Submission', 'swiftforms' ),
					'initial_open' => false,
					'fields'       => [
						[
							'name'        => 'storeEntries',
							'type'        => 'select',
							'label'       => __( 'Store entries', 'swiftforms' ),
							'description' => __( 'With "Never", submissions are only emailed and cannot be recovered later.', 'swiftforms' ),
							'default'     => $attributes['storeEntries']['default'],
							'options'     => [
								[
									'value' => 'default',
									'label' => __( 'Use the site setting', 'swiftforms' ),
								],
								[
									'value' => 'yes',
									'label' => __( 'Always', 'swiftforms' ),
								],
								[
									'value' => 'no',
									'label' => __( 'Never', 'swiftforms' ),
								],
							],
						],
						[
							'name'    => 'spamProtection',
							'type'    => 'select',
							'label'   => __( 'Spam protection', 'swiftforms' ),
							'default' => $attributes['spamProtection']['default'],
							'options' => [
								[
									'value' => 'default',
									'label' => __( 'On', 'swiftforms' ),
								],
								[
									'value' => 'off',
									'label' => __( 'Off', 'swiftforms' ),
								],
							],
						],
						[
							'name'    => 'ajax',
							'type'    => 'toggle',
							'label'   => __( 'Send without reloading the page', 'swiftforms' ),
							'default' => true,
						],
					],
				],
			],
			// Not shown in the inspector until 1.1.0; it exists from 1.0 so that no stored form lacks it (C-2.3).
			'attributes'   => [
				'autoresponder' => $attributes['autoresponder'],
			],
			'render'       => [
				'mode'     => 'dynamic',
				'callback' => [ Form_Renderer::class, 'render_block' ],
			],
			'preview'      => [
				'mode'   => 'markup',
				'markup' => [
					'tag'   => 'div',
					'class' => 'swiftforms-form swiftforms-form--labels-{{ attributes.labelPosition }}',
					'slot'  => 'inner_blocks',
				],
			],
			'inner_blocks' => [
				'allowed'  => self::form_children(),
				'template' => self::form_template(),
			],
		];
	}

	/**
	 * The blocks a newly inserted form starts with (R01 2.1): Name, Email, Message, Submit, Confirmation.
	 *
	 * The field names are set here, not derived later: a name is the key of every stored value, so
	 * it must not wait for the author to open the inspector.
	 *
	 * @return array<int, array<int, mixed>>
	 */
	public static function form_template() {
		return [
			[
				Field_Types::block_name( 'text' ),
				[
					'name'         => 'name',
					'label'        => __( 'Name', 'swiftforms' ),
					'autocomplete' => 'name',
				],
			],
			[
				Field_Types::block_name( 'email' ),
				[
					'name'         => 'email',
					'label'        => __( 'Email', 'swiftforms' ),
					'required'     => true,
					'autocomplete' => 'email',
				],
			],
			[
				Field_Types::block_name( 'textarea' ),
				[
					'name'     => 'message',
					'label'    => __( 'Message', 'swiftforms' ),
					'required' => true,
				],
			],
			[ Field_Types::SUBMIT_BLOCK ],
			[
				Field_Types::CONFIRMATION_BLOCK,
				[],
				[
					[
						'core/paragraph',
						[ 'content' => __( "Thanks! We'll be in touch.", 'swiftforms' ) ],
					],
				],
			],
		];
	}

	/**
	 * Fields of one row of the notifications repeater (C-2.3).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function notification_fields() {
		return [
			[
				'name'    => 'enabled',
				'type'    => 'toggle',
				'label'   => __( 'Send this notification', 'swiftforms' ),
				'default' => true,
			],
			[
				'name'        => 'to',
				'type'        => 'text',
				'label'       => __( 'To', 'swiftforms' ),
				'description' => __( 'One or more email addresses, separated by commas.', 'swiftforms' ),
			],
			[
				'name'  => 'cc',
				'type'  => 'text',
				'label' => __( 'Cc', 'swiftforms' ),
			],
			[
				'name'  => 'bcc',
				'type'  => 'text',
				'label' => __( 'Bcc', 'swiftforms' ),
			],
			[
				'name'  => 'subject',
				'type'  => 'text',
				'label' => __( 'Subject', 'swiftforms' ),
			],
			[
				'name'  => 'replyTo',
				'type'  => 'text',
				'label' => __( 'Reply to', 'swiftforms' ),
			],
			[
				'name'  => 'body',
				'type'  => 'textarea',
				'label' => __( 'Message', 'swiftforms' ),
			],
			[
				'name'    => 'format',
				'type'    => 'select',
				'label'   => __( 'Format', 'swiftforms' ),
				'default' => 'html',
				'options' => [
					[
						'value' => 'html',
						'label' => __( 'HTML', 'swiftforms' ),
					],
					[
						'value' => 'plain',
						'label' => __( 'Plain text', 'swiftforms' ),
					],
				],
			],
		];
	}

	// ---------------------------------------------------------------------
	// Submit and confirmation.
	// ---------------------------------------------------------------------

	/**
	 * The submit button.
	 *
	 * @return array<string, mixed>
	 */
	private static function submit() {
		return [
			'id'      => Field_Types::SUBMIT_BLOCK,
			'args'    => [
				'title'       => __( 'Submit button', 'swiftforms' ),
				'category'    => self::CATEGORY,
				'description' => __( 'The button that sends the form.', 'swiftforms' ),
				'icon'        => self::ICONS['submit'],
				'keywords'    => [ __( 'button', 'swiftforms' ), __( 'send', 'swiftforms' ) ],
				'textdomain'  => 'swiftforms',
				'ancestor'    => [ Field_Types::FORM_BLOCK ],
				'supports'    => self::field_supports( true ),
			],
			'fields'  => [
				[
					'name'    => 'label',
					'type'    => 'text',
					'label'   => __( 'Button text', 'swiftforms' ),
					'default' => Field_Types::submit_attributes()['label']['default'],
				],
			],
			'render'  => [
				'mode'     => 'dynamic',
				'callback' => [ Submit_Renderer::class, 'render_block' ],
			],
			'preview' => [
				'mode'   => 'markup',
				'markup' => [
					'tag'      => 'div',
					'class'    => 'swiftforms-submit',
					'children' => [
						[
							'tag'   => 'button',
							'class' => 'swiftforms-submit__button wp-element-button',
							'attrs' => [
								'type'     => 'button',
								'disabled' => 'disabled',
							],
							'text'  => '{{ attributes.label | default:' . __( 'Submit', 'swiftforms' ) . ' }}',
						],
					],
				],
			],
		];
	}

	/**
	 * The confirmation: inner blocks shown after a successful submission.
	 *
	 * "At most one per form" is not enforceable with the multiple support, which counts per post and
	 * would stop a second form on the page from having one. More than one is tolerated: the resolver
	 * collects all of them in document order.
	 *
	 * @return array<string, mixed>
	 */
	private static function confirmation() {
		return [
			'id'           => Field_Types::CONFIRMATION_BLOCK,
			'args'         => [
				'title'       => __( 'Confirmation', 'swiftforms' ),
				'category'    => self::CATEGORY,
				'description' => __( 'What the visitor sees after sending the form. Use the toolbar button of the form to preview it.', 'swiftforms' ),
				'icon'        => self::ICONS['confirmation'],
				'textdomain'  => 'swiftforms',
				'parent'      => [ Field_Types::FORM_BLOCK ],
				'supports'    => [
					'className' => true,
					'color'     => [
						'background' => true,
						'text'       => true,
					],
					'spacing'   => [
						'padding' => true,
						'margin'  => true,
					],
				],
			],
			'render'       => [
				'mode'     => 'dynamic',
				'callback' => [ Confirmation_Renderer::class, 'render_block' ],
			],
			'preview'      => [
				'mode'   => 'markup',
				'markup' => [
					'tag'      => 'div',
					'class'    => 'swiftforms-confirmation',
					'children' => [
						[
							'tag'   => 'p',
							'class' => 'swiftforms-confirmation__label',
							'text'  => __( 'Confirmation message', 'swiftforms' ),
						],
						[
							'tag'  => 'div',
							'slot' => 'inner_blocks',
						],
					],
				],
			],
			'inner_blocks' => [
				'allowed'  => [ 'core/paragraph', 'core/heading', 'core/list', 'core/image', 'core/separator', 'core/spacer', 'core/group' ],
				'template' => [
					[ 'core/paragraph' ],
				],
			],
		];
	}

	// ---------------------------------------------------------------------
	// Field blocks.
	// ---------------------------------------------------------------------

	/**
	 * Block supports of a field (or the submit button): the Style panel.
	 *
	 * @param bool $border Whether the block also supports borders (the submit button does).
	 * @return array<string, mixed>
	 */
	private static function field_supports( $border = false ) {
		$supports = [
			'className'  => true,
			'color'      => [
				'background' => true,
				'text'       => true,
			],
			'spacing'    => [
				'margin' => true,
			],
			'typography' => [
				'fontSize' => true,
			],
		];
		if ( $border ) {
			$supports['border'] = [
				'color'  => true,
				'radius' => true,
				'style'  => true,
				'width'  => true,
			];
		}
		return $supports;
	}

	/**
	 * One field block.
	 *
	 * @param string $type Field type.
	 * @return array<string, mixed>
	 */
	private static function field( $type ) {
		$titles = self::field_titles();
		$layout = self::field_layout( $type );

		$controls = self::controls( $type );
		$used     = [];
		$panels   = [];
		foreach ( $layout as $key => $names ) {
			$children = [];
			foreach ( $names as $attribute ) {
				$children[]         = $controls[ $attribute ];
				$used[ $attribute ] = true;
			}
			$panels[ $key ] = $children;
		}

		$fields = [
			[
				'name'    => 'formId',
				'type'    => 'text',
				'source'  => 'context',
				'context' => 'swiftforms/formId',
			],
			[
				'name'    => 'formLabelPosition',
				'type'    => 'text',
				'source'  => 'context',
				'context' => 'swiftforms/labelPosition',
			],
			[
				'name'    => 'formRequiredIndicator',
				'type'    => 'text',
				'source'  => 'context',
				'context' => 'swiftforms/requiredIndicator',
			],
			[
				'name'         => 'panel_field',
				'type'         => 'panel',
				'title'        => __( 'Field', 'swiftforms' ),
				'initial_open' => true,
				'fields'       => $panels['field'],
			],
		];
		if ( ! empty( $panels['options'] ) ) {
			$fields[] = [
				'name'         => 'panel_options',
				'type'         => 'panel',
				'title'        => __( 'Options', 'swiftforms' ),
				'initial_open' => true,
				'fields'       => $panels['options'],
			];
		}
		if ( ! empty( $panels['advanced'] ) ) {
			$fields[] = [
				'name'         => 'panel_advanced',
				'type'         => 'panel',
				'title'        => __( 'More options', 'swiftforms' ),
				'initial_open' => false,
				'fields'       => $panels['advanced'],
			];
		}

		// Attributes without a control keep the definition of Field_Types as a plain attribute.
		$plain = [];
		foreach ( Field_Types::attributes( $type ) as $name => $definition ) {
			if ( ! isset( $used[ $name ] ) ) {
				$plain[ $name ] = $definition;
			}
		}

		return [
			'id'         => Field_Types::block_name( $type ),
			'args'       => [
				'title'       => $titles[ $type ]['title'],
				'category'    => self::CATEGORY,
				'description' => $titles[ $type ]['description'],
				'icon'        => self::ICONS[ $type ],
				'keywords'    => [ __( 'form', 'swiftforms' ), __( 'field', 'swiftforms' ), $titles[ $type ]['title'] ],
				'textdomain'  => 'swiftforms',
				'ancestor'    => [ Field_Types::FORM_BLOCK ],
				'supports'    => self::field_supports(),
			],
			'fields'     => $fields,
			'attributes' => $plain,
			'render'     => [
				'mode'     => 'dynamic',
				'callback' => [ Field_Renderer::class, 'render_block' ],
			],
			'preview'    => [
				'mode'   => 'markup',
				'markup' => Field_Preview::markup( $type ),
			],
		];
	}

	/**
	 * Title and description of every field block.
	 *
	 * @return array<string, array<string, string>>
	 */
	private static function field_titles() {
		return [
			'text'       => [
				'title'       => __( 'Text field', 'swiftforms' ),
				'description' => __( 'A single line of text.', 'swiftforms' ),
			],
			'email'      => [
				'title'       => __( 'Email field', 'swiftforms' ),
				'description' => __( 'An email address.', 'swiftforms' ),
			],
			'textarea'   => [
				'title'       => __( 'Message field', 'swiftforms' ),
				'description' => __( 'Several lines of text.', 'swiftforms' ),
			],
			'number'     => [
				'title'       => __( 'Number field', 'swiftforms' ),
				'description' => __( 'A number, with optional limits.', 'swiftforms' ),
			],
			'url'        => [
				'title'       => __( 'Website field', 'swiftforms' ),
				'description' => __( 'A web address.', 'swiftforms' ),
			],
			'tel'        => [
				'title'       => __( 'Phone field', 'swiftforms' ),
				'description' => __( 'A telephone number.', 'swiftforms' ),
			],
			'date'       => [
				'title'       => __( 'Date field', 'swiftforms' ),
				'description' => __( 'A date.', 'swiftforms' ),
			],
			'select'     => [
				'title'       => __( 'Dropdown field', 'swiftforms' ),
				'description' => __( 'Choose from a list.', 'swiftforms' ),
			],
			'radio'      => [
				'title'       => __( 'Radio buttons field', 'swiftforms' ),
				'description' => __( 'Choose one of a few options.', 'swiftforms' ),
			],
			'checkboxes' => [
				'title'       => __( 'Checkboxes field', 'swiftforms' ),
				'description' => __( 'Choose any number of options.', 'swiftforms' ),
			],
			'consent'    => [
				'title'       => __( 'Consent checkbox', 'swiftforms' ),
				'description' => __( 'A box the visitor ticks to agree to something.', 'swiftforms' ),
			],
			'hidden'     => [
				'title'       => __( 'Hidden field', 'swiftforms' ),
				'description' => __( 'A value sent with the form that visitors do not see.', 'swiftforms' ),
			],
		];
	}

	/**
	 * Which attribute controls go to which panel for a field type.
	 *
	 * @param string $type Field type.
	 * @return array{field: string[], options: string[], advanced: string[]}
	 */
	private static function field_layout( $type ) {
		$basics = [ 'label', 'name', 'required', 'help' ];

		switch ( $type ) {
			case 'text':
				return [
					'field'    => array_merge( $basics, [ 'placeholder', 'defaultValue', 'minLength', 'maxLength' ] ),
					'options'  => [],
					'advanced' => [ 'width', 'autocomplete', 'pattern', 'patternMessage' ],
				];
			case 'textarea':
				return [
					'field'    => array_merge( $basics, [ 'placeholder', 'defaultValue', 'maxLength' ] ),
					'options'  => [],
					'advanced' => [ 'width', 'rows', 'autocomplete' ],
				];
			case 'number':
				return [
					'field'    => array_merge( $basics, [ 'placeholder', 'defaultValue', 'min', 'max', 'step' ] ),
					'options'  => [],
					'advanced' => [ 'width', 'autocomplete' ],
				];
			case 'date':
				return [
					'field'    => array_merge( $basics, [ 'defaultValue', 'min', 'max' ] ),
					'options'  => [],
					'advanced' => [ 'width', 'autocomplete' ],
				];
			case 'select':
				return [
					'field'    => array_merge( $basics, [ 'multiple', 'placeholderOption', 'defaultValue' ] ),
					'options'  => [ 'options' ],
					'advanced' => [ 'width', 'autocomplete' ],
				];
			case 'radio':
				return [
					'field'    => array_merge( $basics, [ 'defaultValue', 'layout' ] ),
					'options'  => [ 'options' ],
					'advanced' => [ 'width' ],
				];
			case 'checkboxes':
				return [
					'field'    => array_merge( $basics, [ 'defaultValue', 'layout', 'minChoices', 'maxChoices' ] ),
					'options'  => [ 'options' ],
					'advanced' => [ 'width' ],
				];
			case 'consent':
				return [
					'field'    => array_merge( $basics, [ 'text' ] ),
					'options'  => [],
					'advanced' => [ 'width' ],
				];
			case 'hidden':
				return [
					'field'    => [ 'label', 'name', 'value' ],
					'options'  => [],
					'advanced' => [],
				];
		}

		// email, url, tel.
		return [
			'field'    => array_merge( $basics, [ 'placeholder', 'defaultValue' ] ),
			'options'  => [],
			'advanced' => [ 'width', 'autocomplete' ],
		];
	}

	/**
	 * Every inspector control a field block can have, keyed by attribute name, for one type.
	 *
	 * @param string $type Field type.
	 * @return array<string, array<string, mixed>>
	 */
	private static function controls( $type ) {
		$definitions = Field_Types::attributes( $type );
		$fallbacks   = [
			'rows'   => 4,
			'layout' => 'stack',
		];
		$default     = static function ( $attribute ) use ( $definitions, $fallbacks ) {
			return isset( $definitions[ $attribute ] ) ? $definitions[ $attribute ]['default'] : ( $fallbacks[ $attribute ] ?? null );
		};

		$widths = [];
		foreach ( Field_Types::WIDTHS as $width ) {
			$widths[] = [
				'value' => $width,
				'label' => $width . '%',
			];
		}

		$multi_default = in_array( $type, [ 'checkboxes' ], true );

		return [
			'label'             => [
				'name'  => 'label',
				'type'  => 'text',
				'label' => 'hidden' === $type ? __( 'Label (for you)', 'swiftforms' ) : __( 'Label', 'swiftforms' ),
			],
			'name'              => [
				'name'        => 'name',
				'type'        => 'text',
				'label'       => __( 'Field name', 'swiftforms' ),
				'description' => __( 'Identifies the value in entries and emails. Made from the label; letters, numbers, dashes and underscores.', 'swiftforms' ),
				'derive_from' => 'label',
				'format'      => 'slug',
				'unique'      => [
					'scope' => 'ancestor',
					'block' => Field_Types::FORM_BLOCK,
					'key'   => 'swiftforms-name',
				],
			],
			'required'          => [
				'name'    => 'required',
				'type'    => 'toggle',
				'label'   => __( 'Required', 'swiftforms' ),
				'default' => $default( 'required' ),
			],
			'help'              => [
				'name'  => 'help',
				'type'  => 'text',
				'label' => __( 'Help text', 'swiftforms' ),
			],
			'placeholder'       => [
				'name'  => 'placeholder',
				'type'  => 'text',
				'label' => __( 'Placeholder', 'swiftforms' ),
			],
			'defaultValue'      => self::default_value_control( $type, $multi_default ),
			'minLength'         => [
				'name'        => 'minLength',
				'type'        => 'number',
				'label'       => __( 'Shortest answer (characters)', 'swiftforms' ),
				'description' => __( '0 means no minimum.', 'swiftforms' ),
				'value_type'  => 'integer',
				'default'     => 0,
				'min'         => 0,
			],
			'maxLength'         => [
				'name'        => 'maxLength',
				'type'        => 'number',
				'label'       => __( 'Longest answer (characters)', 'swiftforms' ),
				'description' => __( '0 means no maximum.', 'swiftforms' ),
				'value_type'  => 'integer',
				'default'     => 0,
				'min'         => 0,
			],
			'min'               => [
				'name'  => 'min',
				'type'  => 'date' === $type ? 'date' : 'text',
				'label' => 'date' === $type ? __( 'Earliest date', 'swiftforms' ) : __( 'Smallest number', 'swiftforms' ),
			],
			'max'               => [
				'name'  => 'max',
				'type'  => 'date' === $type ? 'date' : 'text',
				'label' => 'date' === $type ? __( 'Latest date', 'swiftforms' ) : __( 'Largest number', 'swiftforms' ),
			],
			'step'              => [
				'name'  => 'step',
				'type'  => 'text',
				'label' => __( 'Step', 'swiftforms' ),
			],
			'rows'              => [
				'name'       => 'rows',
				'type'       => 'number',
				'label'      => __( 'Visible lines', 'swiftforms' ),
				'value_type' => 'integer',
				'default'    => $default( 'rows' ),
				'min'        => 1,
			],
			'width'             => [
				'name'    => 'width',
				'type'    => 'select',
				'label'   => __( 'Width', 'swiftforms' ),
				'default' => $default( 'width' ),
				'options' => $widths,
			],
			'autocomplete'      => [
				'name'        => 'autocomplete',
				'type'        => 'text',
				'label'       => __( 'Autocomplete', 'swiftforms' ),
				'description' => __( 'A browser hint such as "name", "email" or "tel".', 'swiftforms' ),
			],
			'pattern'           => [
				'name'        => 'pattern',
				'type'        => 'text',
				'label'       => __( 'Pattern', 'swiftforms' ),
				'description' => __( 'A regular expression the answer must match.', 'swiftforms' ),
			],
			'patternMessage'    => [
				'name'  => 'patternMessage',
				'type'  => 'text',
				'label' => __( 'Message when the pattern does not match', 'swiftforms' ),
			],
			'options'           => [
				'name'    => 'options',
				'type'    => 'options_list',
				'label'   => __( 'Options', 'swiftforms' ),
				'default' => [],
			],
			'multiple'          => [
				'name'    => 'multiple',
				'type'    => 'toggle',
				'label'   => __( 'Allow several answers', 'swiftforms' ),
				'default' => false,
			],
			'placeholderOption' => [
				'name'        => 'placeholderOption',
				'type'        => 'text',
				'label'       => __( 'First option text', 'swiftforms' ),
				'description' => __( 'An empty first option such as "Choose one". Leave empty for none.', 'swiftforms' ),
			],
			'layout'            => [
				'name'    => 'layout',
				'type'    => 'select',
				'label'   => __( 'Layout', 'swiftforms' ),
				'default' => $default( 'layout' ),
				'options' => [
					[
						'value' => 'stack',
						'label' => __( 'One under the other', 'swiftforms' ),
					],
					[
						'value' => 'inline',
						'label' => __( 'Side by side', 'swiftforms' ),
					],
				],
			],
			'minChoices'        => [
				'name'        => 'minChoices',
				'type'        => 'number',
				'label'       => __( 'Fewest choices', 'swiftforms' ),
				'description' => __( '0 means no minimum.', 'swiftforms' ),
				'value_type'  => 'integer',
				'default'     => 0,
				'min'         => 0,
			],
			'maxChoices'        => [
				'name'        => 'maxChoices',
				'type'        => 'number',
				'label'       => __( 'Most choices', 'swiftforms' ),
				'description' => __( '0 means no maximum.', 'swiftforms' ),
				'value_type'  => 'integer',
				'default'     => 0,
				'min'         => 0,
			],
			'text'              => [
				'name'        => 'text',
				'type'        => 'textarea',
				'label'       => __( 'Consent text', 'swiftforms' ),
				'description' => __( 'Shown next to the box. Links are allowed.', 'swiftforms' ),
			],
			'value'             => [
				'name'        => 'value',
				'type'        => 'text',
				'label'       => __( 'Value', 'swiftforms' ),
				'description' => __( 'Text, or {post:id}, {post:title}, {post:url}, {user:id}. Visitors cannot change it.', 'swiftforms' ),
			],
		];
	}

	/**
	 * The default-value control of a type.
	 *
	 * @param string $type          Field type.
	 * @param bool   $one_per_line Whether the value is a list of option values, one per line.
	 * @return array<string, mixed>
	 */
	private static function default_value_control( $type, $one_per_line ) {
		$control = [
			'name'  => 'defaultValue',
			'type'  => 'text',
			'label' => __( 'Default value', 'swiftforms' ),
		];

		if ( $one_per_line ) {
			$control['type']        = 'textarea';
			$control['description'] = __( 'The option values to tick, one per line.', 'swiftforms' );
		} elseif ( 'textarea' === $type ) {
			$control['type'] = 'textarea';
		} elseif ( 'date' === $type ) {
			$control['type'] = 'date';
		} elseif ( in_array( $type, [ 'select', 'radio' ], true ) ) {
			$control['description'] = __( 'The value of the option to pick first. With several answers allowed: one value per line.', 'swiftforms' );
		}
		return $control;
	}

	// ---------------------------------------------------------------------
	// Condition helpers.
	// ---------------------------------------------------------------------

	/**
	 * One condition rule.
	 *
	 * @param string $field    Attribute name.
	 * @param string $operator Operator.
	 * @param mixed  $value    Compared value (left out for empty / not_empty).
	 * @return array<string, mixed>
	 */
	public static function rule( $field, $operator, $value = null ) {
		$rule = [
			'field'    => $field,
			'operator' => $operator,
		];
		if ( null !== $value ) {
			$rule['value'] = $value;
		}
		return $rule;
	}

	/**
	 * A condition that holds when every rule holds.
	 *
	 * @param array<int, array<string, mixed>> $rules Rules.
	 * @return array<string, mixed>
	 */
	public static function when_all( array $rules ) {
		return [
			'relation' => 'AND',
			'rules'    => $rules,
		];
	}
}
