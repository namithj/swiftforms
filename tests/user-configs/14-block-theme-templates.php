<?php // phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- Included by the loader: the variables are local to the include, and the snippet's names are test fixtures.
/**
 * User config 14: full site editing.
 *
 * Raw stored data of a site, written exactly as 1.0.0 stores it. Frozen at the v1.0.0 tag.
 *
 * A block theme (sf-test-block-files: sf-test-block plus one extra template file) with forms in four places:
 * a footer template part and a custom single template saved to the database (wp_template_part and wp_template
 * posts tagged with the theme), a page, and a theme-file template (templates/sf-landing.html) that was never saved
 * to the database. The footer part replaces the theme's footer wherever the theme's templates use it.
 *
 * @package SwiftForms
 */

return [
	'slug'          => '14-block-theme-templates',
	'represents'    => 'Full site editing: forms in a database footer part, a database single template, a page and a theme-file template that was never saved.',
	'guards'        => [ 'ADR-001', 'C-3.11', 'C-2.6' ],
	'site'          => [
		'locale'     => 'en_US',
		'theme'      => 'sf-test-block-files',
		'multisite'  => false,
		'timezone'   => 'Europe/London',
		'permalinks' => '/%postname%/',
	],
	'options'       => [
		'swiftforms_email_notification_to' => 'editor@fse-site.example',
		'swiftforms_db_version'            => 1,
		'swiftforms_installed'             => [
			'version' => '1.0.0',
			'time'    => 1795082400,
		],
	],
	'posts'         => [
		[
			'key'       => 'footer-part',
			'post_type' => 'wp_template_part',
			'status'    => 'publish',
			'title'     => 'Footer',
			'slug'      => 'footer',
			'date'      => '2026-10-01 09:00:00',
			'terms'     => [ 'wp_theme' => [ 'sf-test-block-files' ] ],
			'meta'      => [ 'origin' => 'theme' ],
			'content'   => SF_BLOCK(
				'group',
				[ 'tagName' => 'footer' ],
				'<footer class="wp-block-group">' . SF_PARAGRAPH( 'Stay in touch.' ) . "\n\n" . SF_FORM(
					[
						'formId' => '5f140001-0000-4000-8000-000000000001',
						'title'  => 'Footer newsletter',
					],
					[
						SF_FIELD(
							'email',
							[
								'label'        => 'Email',
								'name'         => 'email',
								'required'     => true,
								'autocomplete' => 'email',
							]
						),
					],
					'Subscribe',
					'You are subscribed.'
				) . '</footer>'
			),
		],
		[
			'key'       => 'single-template',
			'post_type' => 'wp_template',
			'status'    => 'publish',
			'title'     => 'Single Post',
			'slug'      => 'single',
			'date'      => '2026-10-01 10:00:00',
			'terms'     => [ 'wp_theme' => [ 'sf-test-block-files' ] ],
			'meta'      => [ 'origin' => 'theme' ],
			'content'   => SF_BLOCK(
				'template-part',
				[
					'slug'    => 'header',
					'tagName' => 'header',
				]
			) . "\n\n" . SF_BLOCK(
				'group',
				[ 'tagName' => 'main' ],
				'<main class="wp-block-group">' . SF_BLOCK( 'post-title' ) . "\n\n" . SF_BLOCK( 'post-content' ) . "\n\n" . SF_FORM(
					[
						'formId' => '5f140002-0000-4000-8000-000000000002',
						'title'  => 'Reader feedback',
					],
					[
						SF_FIELD(
							'text',
							[
								'label'    => 'Your name',
								'name'     => 'name',
								'required' => true,
							]
						),
						SF_FIELD(
							'textarea',
							[
								'label'    => 'Feedback',
								'name'     => 'feedback',
								'required' => true,
							]
						),
					],
					'Send feedback',
					'Thank you for your feedback.'
				) . '</main>'
			) . "\n\n" . SF_BLOCK(
				'template-part',
				[
					'slug'    => 'footer',
					'tagName' => 'footer',
				]
			),
		],
		[
			'key'       => 'about',
			'post_type' => 'page',
			'status'    => 'publish',
			'title'     => 'About',
			'slug'      => 'about',
			'date'      => '2026-10-02 09:00:00',
			'content'   => SF_PARAGRAPH( 'We are a small restaurant.' ) . "\n\n" . SF_FORM(
				[
					'formId' => '5f140003-0000-4000-8000-000000000003',
					'title'  => 'Ask us',
				],
				[
					SF_FIELD(
						'email',
						[
							'label'    => 'Email',
							'name'     => 'email',
							'required' => true,
						]
					),
					SF_FIELD(
						'textarea',
						[
							'label'    => 'Question',
							'name'     => 'question',
							'required' => true,
						]
					),
				],
				'Ask',
				'We will answer soon.'
			),
		],
		[
			'key'       => 'hello',
			'post_type' => 'post',
			'status'    => 'publish',
			'title'     => 'Hello world',
			'slug'      => 'hello-world',
			'date'      => '2026-10-03 09:00:00',
			'content'   => SF_PARAGRAPH( 'A post without a form of its own; its single template carries one.' ),
		],
	],
	'users'         => [ 'admin' ],
	'renders'       => [
		[
			'name' => 'theme file template sf-landing',
			'type' => 'template',
			'id'   => 'sf-landing',
		],
		[
			'name' => 'theme file template part header',
			'type' => 'template_part',
			'id'   => 'header',
		],
	],
	'submissions'   => [
		'footer-part#1'                              => [
			'valid'   => [ 'c14-news' ],
			'invalid' => [ 'c14-news-bad' ],
		],
		'single-template#1'                          => [
			'valid'   => [ 'c14-feedback' ],
			'invalid' => [ 'c14-feedback-bad' ],
		],
		'about#1'                                    => [
			'valid'   => [ 'c14-ask' ],
			'invalid' => [ 'c14-ask-bad' ],
		],
		'template:sf-test-block-files//sf-landing#1' => [
			'valid'   => [ 'c14-table' ],
			'invalid' => [ 'c14-table-bad' ],
		],
	],
	'privacy_email' => 'ada@example.com',
	'checks'        => [ 'resave' ],
	'skip'          => [
		'S14' => 'pending: R02',
		'S15' => 'pending: R02',
		'S16' => 'pending: R02',
	],
];
