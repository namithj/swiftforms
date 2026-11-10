<?php // phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- Included by the loader: the variables are local to the include, and the snippet's names are test fixtures.
/**
 * User config 20: developer site.
 *
 * Raw stored data of a site, written exactly as 1.0.0 stores it. Frozen at the v1.0.0 tag.
 *
 * Twelve forms on six pages (contact, faq, services, pricing, events, updates; plus a thank-you page), with custom class names and anchors, labels inline or hidden, forms that do
 * not use JavaScript (ajax false) so they are posted to the REST route by a headless client or as a plain form post, a redirect
 * confirmation, a form with spam protection off, and notifications with a merge tag a hook adds. The plugin's own styles are
 * off (load_styles false), the debug log is on, and an mu-plugin (mu/20-all-hooks.php) uses every documented v1 hook. The site
 * owner's global styles (the user layer of theme.json, a wp_global_styles post) restyle three SwiftForms blocks. The variant
 * "headless-no-js-token" is the same site with spam.js_token off: a REST client that has no browser can post with the signed time of
 * the page instead of the JS time (C-3.1).
 *
 * @package SwiftForms
 */

$sf_c20_fields = static function ( array $extra = [], $message_required = false ) {
	$fields = [
		SF_FIELD(
			'text',
			[
				'label'        => 'Name',
				'name'         => 'name',
				'required'     => true,
				'autocomplete' => 'name',
			]
		),
		SF_FIELD(
			'email',
			[
				'label'        => 'Email',
				'name'         => 'email',
				'required'     => true,
				'autocomplete' => 'email',
			]
		),
	];
	foreach ( $extra as $field ) {
		$fields[] = $field;
	}
	$fields[] = SF_FIELD(
		'textarea',
		$message_required
			? [
				'label'    => 'Message',
				'name'     => 'message',
				'required' => true,
			]
			: [
				'label' => 'Message',
				'name'  => 'message',
			]
	);
	return $fields;
};

$sf_c20_form = static function ( $n, array $attrs, array $fields, $thanks = 'Received.' ) {
	return SF_FORM(
		array_merge(
			[ 'formId' => sprintf( '5f2000%02d-0000-4000-8000-0000000000%02d', $n, $n ) ],
			$attrs
		),
		$fields,
		'Send',
		$thanks
	);
};

$sf_c20_pages = [
	'contact'  => [
		$sf_c20_form(
			1,
			[
				'title'         => 'Hero contact',
				'labelPosition' => 'inline',
				'notifications' => [
					[
						'enabled' => true,
						'to'      => 'dev@power-site.example',
						'cc'      => '',
						'bcc'     => '',
						'subject' => '{power:greeting}: {field:name}',
						'replyTo' => '{field:email}',
						'body'    => '{all_fields}',
						'format'  => 'html',
					],
				],
				'anchor'        => 'contact-hero',
				'className'     => 'power-form power-form--hero',
			],
			$sf_c20_fields(
				[
					SF_FIELD(
						'text',
						[
							'label' => 'Promo code',
							'name'  => 'code',
						]
					),
				]
			)
		),
		$sf_c20_form(
			2,
			[
				'title'             => 'Newsletter (no JavaScript)',
				'requiredIndicator' => 'text',
				'ajax'              => false,
				'anchor'            => 'newsletter',
			],
			$sf_c20_fields()
		),
	],
	'faq'      => [
		$sf_c20_form(
			3,
			[
				'title'             => 'Quiet form',
				'labelPosition'     => 'hidden',
				'requiredIndicator' => 'none',
				'className'         => 'power-form power-form--quiet',
			],
			$sf_c20_fields()
		),
		$sf_c20_form(
			4,
			[
				'title'        => 'Anonymous poll (no JavaScript)',
				'storeEntries' => 'no',
				'ajax'         => false,
			],
			$sf_c20_fields()
		),
	],
	'services' => [
		$sf_c20_form(
			5,
			[
				'title'            => 'Redirect form',
				'confirmationType' => 'redirect',
				'redirectPageId'   => 900007, // The thank-you page: the 7th post of this config, so ID 900007 (IDs are pinned).
			],
			$sf_c20_fields()
		),
		$sf_c20_form(
			6,
			[
				'title'          => 'Spam protection off',
				'spamProtection' => 'off',
			],
			$sf_c20_fields()
		),
	],
	'pricing'  => [
		$sf_c20_form(
			7,
			[
				'title'                  => 'Two notifications',
				'useDefaultNotification' => false,
				'notifications'          => [
					[
						'enabled' => true,
						'to'      => 'sales@power-site.example',
						'cc'      => '',
						'bcc'     => '',
						'subject' => 'Pricing question from {field:name}',
						'replyTo' => '{field:email}',
						'body'    => "{field:message}\n\n{site:name}",
						'format'  => 'plain',
					],
					[
						'enabled' => true,
						'to'      => 'archive@power-site.example',
						'cc'      => '',
						'bcc'     => '',
						'subject' => 'Archive: {form:title}',
						'replyTo' => '',
						'body'    => '{all_fields}',
						'format'  => 'html',
					],
				],
			],
			$sf_c20_fields()
		),
		$sf_c20_form(
			8,
			[
				'title'        => 'Stored although the site does not store',
				'storeEntries' => 'yes',
				'ajax'         => false,
				'anchor'       => 'always-stored',
			],
			$sf_c20_fields()
		),
	],
	'events'   => [
		$sf_c20_form(
			9,
			[
				'title'     => 'Visit booking',
				'className' => 'power-form',
			],
			$sf_c20_fields(
				[
					SF_FIELD(
						'date',
						[
							'label' => 'Visit on',
							'name'  => 'visit',
							'min'   => '2026-11-20',
						]
					),
				]
			)
		),
		$sf_c20_form(
			10,
			[
				'title' => 'Plan chooser',
			],
			$sf_c20_fields(
				[
					SF_FIELD(
						'radio',
						[
							'label'   => 'Plan',
							'name'    => 'plan',
							'layout'  => 'inline',
							'options' => [
								[
									'value' => 'free',
									'label' => 'Free',
								],
								[
									'value' => 'pro',
									'label' => 'Pro',
								],
							],
						]
					),
				]
			)
		),
	],
	'updates'  => [
		$sf_c20_form(
			11,
			[
				'title' => 'Where did you read this?',
			],
			$sf_c20_fields(
				[
					SF_FIELD(
						'hidden',
						[
							'label' => 'Page',
							'name'  => 'page_title',
							'value' => '{post:title}',
						]
					),
				]
			)
		),
		$sf_c20_form(
			12,
			[
				'title'     => 'Topics',
				'anchor'    => 'topics',
				'className' => 'power-form power-form--topics',
			],
			$sf_c20_fields(
				[
					SF_FIELD(
						'select',
						[
							'label'    => 'Topics',
							'name'     => 'topics',
							'multiple' => true,
							'options'  => [
								[
									'value' => 'wp',
									'label' => 'WordPress',
								],
								[
									'value' => 'php',
									'label' => 'PHP',
								],
								[
									'value' => 'js',
									'label' => 'JavaScript',
								],
							],
						]
					),
				]
			)
		),
	],
];

$sf_c20_posts = [];
$sf_c20_day   = 0;
foreach ( $sf_c20_pages as $sf_c20_slug => $sf_c20_forms ) {
	++$sf_c20_day;
	$sf_c20_posts[] = [
		'key'       => $sf_c20_slug,
		'post_type' => 'page',
		'status'    => 'publish',
		'title'     => ucfirst( $sf_c20_slug ),
		'slug'      => $sf_c20_slug,
		'date'      => sprintf( '2026-10-%02d 09:00:00', $sf_c20_day ),
		'content'   => SF_HEADING( ucfirst( $sf_c20_slug ) ) . "\n\n" . implode( "\n\n", $sf_c20_forms ),
	];
}
$sf_c20_posts[] = [
	'key'       => 'thanks',
	'post_type' => 'page',
	'status'    => 'publish',
	'title'     => 'Thanks',
	'slug'      => 'thanks',
	'date'      => '2026-10-10 09:00:00',
	'content'   => SF_PARAGRAPH( 'Thank you, we have your message.' ),
];
$sf_c20_posts[] = [
	'key'       => 'global-styles',
	'post_type' => 'wp_global_styles',
	'status'    => 'publish',
	'title'     => 'Custom Styles',
	'slug'      => 'wp-global-styles-sf-test-block',
	'date'      => '2026-10-11 09:00:00',
	'terms'     => [ 'wp_theme' => [ 'sf-test-block' ] ],
	'content'   => '{"version":3,"isGlobalStylesUserThemeJSON":true,"styles":{"blocks":{"swiftforms/form":{"color":{"background":"#f6f7f7"},"spacing":{"padding":{"top":"1rem","bottom":"1rem"}},"border":{"radius":"6px"}},"swiftforms/submit":{"color":{"background":"#1d4ed8","text":"#ffffff"}},"swiftforms/field-text":{"spacing":{"margin":{"bottom":"0.75rem"}}}}}}',
];

$sf_c20_submissions = [];
foreach ( $sf_c20_pages as $sf_c20_slug => $sf_c20_forms ) {
	foreach ( array_keys( $sf_c20_forms ) as $sf_c20_i ) {
		$sf_c20_key                        = $sf_c20_slug . '#' . ( $sf_c20_i + 1 );
		$sf_c20_submissions[ $sf_c20_key ] = [
			'valid'   => [ 'c20-basic' ],
			'invalid' => [ 'c20-basic-bad' ],
		];
	}
}
$sf_c20_submissions['contact#1']['valid']   = [ 'c20-code' ];
$sf_c20_submissions['contact#1']['invalid'] = [ 'c20-basic-bad', 'c20-code-rejected' ];
$sf_c20_submissions['contact#1']['spam']    = [ 'c20-hook-spam' ];
$sf_c20_submissions['events#1']['valid']    = [ 'c20-visit' ];
$sf_c20_submissions['events#2']['valid']    = [ 'c20-plan' ];
$sf_c20_submissions['updates#2']['valid']   = [ 'c20-topics' ];

// The settings options as cassette-cmf stores them: one option per key, checkboxes "1"/"0", numbers as integers.
$sf_c20_settings = [
	'swiftforms_general_store_ip'                => '1',
	'swiftforms_general_store_user_agent'        => '1',
	'swiftforms_general_load_styles'             => '0',
	'swiftforms_general_default_success_message' => 'Received.',
	'swiftforms_email_from_name'                 => 'Power Site',
	'swiftforms_email_from_email'                => 'forms@power-site.example',
	'swiftforms_email_notification_to'           => 'dev@power-site.example, ops@power-site.example',
	'swiftforms_email_accent_color'              => '#1d4ed8',
	'swiftforms_email_footer_text'               => '<p>Sent by the power site.</p>',
	'swiftforms_spam_min_seconds'                => 2,
	'swiftforms_spam_rate_limit_per_minute'      => 20,
	'swiftforms_advanced_debug_log'              => '1',
];

return [
	'slug'          => '20-power-user',
	'represents'    => 'Developer site: twelve forms, custom classes and anchors, forms without JavaScript, restyled blocks (global styles), the plugin styles off, every documented hook in use, the debug log on.',
	'guards'        => [ 'C-3.1', 'C-7.1', 'C-8.1', 'C-8.3' ],
	'site'          => [
		'locale'     => 'en_US',
		'theme'      => 'sf-test-block',
		'multisite'  => false,
		'timezone'   => 'Europe/London',
		'permalinks' => '/%postname%/',
	],
	'options'       => array_merge(
		$sf_c20_settings,
		[
			'swiftforms_db_version' => 1,
			'swiftforms_installed'  => [
				'version' => '1.0.0',
				'time'    => 1795082400,
			],
		]
	),
	'variants'      => [
		'headless-no-js-token' => [
			'swiftforms_spam_js_token' => '0',
		],
	],
	'posts'         => $sf_c20_posts,
	'users'         => [ 'admin' ],
	'mu_plugins'    => [ '20-all-hooks.php' ],
	'submissions'   => $sf_c20_submissions,
	'privacy_email' => 'ada@example.com',
	'checks'        => [ 'global_styles', 'hooks', 'resave' ],
	'skip'          => [
		'S14' => 'pending: R02',
		'S15' => 'pending: R02',
		'S16' => 'pending: R02',
	],
];
