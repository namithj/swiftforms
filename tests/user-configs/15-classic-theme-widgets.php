<?php // phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- Included by the loader: the variables are local to the include, and the snippet's names are test fixtures.
/**
 * User config 15: classic theme user.
 *
 * Raw stored data of a site, written exactly as 1.0.0 stores it. Frozen at the v1.0.0 tag.
 *
 * sf-test-classic (no theme.json, one sidebar "sf-sidebar"). A form lives in a block widget in that sidebar
 * (widget_block + sidebars_widgets), another sits in the sidebar of inactive widgets, one is inside core/columns on a page, and a post
 * holds a form that shows in full on the blog index and on its own page: both are the same source, post:ID.
 *
 * @package SwiftForms
 */

$sf_c15_sidebar_form = SF_FORM(
	[
		'formId' => '5f150001-0000-4000-8000-000000000001',
		'title'  => 'Sidebar signup',
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
	'Join',
	'Welcome aboard.'
);

$sf_c15_inactive_form = SF_FORM(
	[
		'formId' => '5f150002-0000-4000-8000-000000000002',
		'title'  => 'Retired widget form',
	],
	[
		SF_FIELD(
			'text',
			[
				'label'    => 'Name',
				'name'     => 'name',
				'required' => true,
			]
		),
	],
	'Send',
	'Sent.'
);

return [
	'slug'          => '15-classic-theme-widgets',
	'represents'    => 'Classic theme user: a form in a block widget, one in an inactive widget, one inside core/columns, and a post whose form shows on the blog index and on its own page.',
	'guards'        => [ 'ADR-001', 'C-2.6', 'C-3.11', 'C-7.3' ],
	'site'          => [
		'locale'        => 'en_US',
		'theme'         => 'sf-test-classic',
		'multisite'     => false,
		'timezone'      => 'America/New_York',
		'permalinks'    => '/%postname%/',
		'show_on_front' => 'posts',
	],
	'options'       => [
		'swiftforms_email_notification_to' => 'webmaster@classic-site.example',
		'swiftforms_db_version'            => 1,
		'swiftforms_installed'             => [
			'version' => '1.0.0',
			'time'    => 1795082400,
		],
		'widget_block'                     => [
			2              => [ 'content' => $sf_c15_sidebar_form ],
			3              => [ 'content' => $sf_c15_inactive_form ],
			'_multiwidget' => 1,
		],
		'sidebars_widgets'                 => [
			'wp_inactive_widgets' => [ 'block-3' ],
			'sf-sidebar'          => [ 'block-2' ],
			'array_version'       => 3,
		],
	],
	'posts'         => [
		[
			'key'       => 'columns',
			'post_type' => 'page',
			'status'    => 'publish',
			'title'     => 'Visit us',
			'slug'      => 'visit',
			'date'      => '2026-10-01 09:00:00',
			'content'   => SF_BLOCK(
				'columns',
				[],
				'<div class="wp-block-columns">' . SF_BLOCK( 'column', [], '<div class="wp-block-column">' . SF_PARAGRAPH( 'Opening hours: every day, 9 to 5.' ) . '</div>' ) . SF_BLOCK(
					'column',
					[],
					'<div class="wp-block-column">' . SF_FORM(
						[
							'formId' => '5f150003-0000-4000-8000-000000000003',
							'title'  => 'Book a visit',
						],
						[
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
								'date',
								[
									'label' => 'Day',
									'name'  => 'day',
									'min'   => '2026-11-20',
								]
							),
						],
						'Book',
						'See you soon.'
					) . '</div>'
				) . '</div>'
			),
		],
		[
			'key'       => 'featured',
			'post_type' => 'post',
			'status'    => 'publish',
			'title'     => 'Join the club',
			'slug'      => 'join-the-club',
			'date'      => '2026-10-10 09:00:00',
			'content'   => SF_PARAGRAPH( 'Tell us who you are and we will keep you posted.' ) . "\n\n" . SF_FORM(
				[
					'formId' => '5f150004-0000-4000-8000-000000000004',
					'title'  => 'Club membership',
				],
				[
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
							'label'    => 'Email',
							'name'     => 'email',
							'required' => true,
						]
					),
				],
				'Join the club',
				'Welcome to the club.'
			),
		],
		[
			'key'       => 'plain',
			'post_type' => 'post',
			'status'    => 'publish',
			'title'     => 'Just news',
			'slug'      => 'just-news',
			'date'      => '2026-10-05 09:00:00',
			'content'   => SF_PARAGRAPH( 'A post with no form.' ),
		],
	],
	'users'         => [ 'admin' ],
	'mu_plugins'    => [ '15-classic-sidebar.php' ],
	'renders'       => [
		[
			'name'    => 'sidebar sf-sidebar',
			'type'    => 'widget_area',
			'sidebar' => 'sf-sidebar',
		],
		[
			'name' => 'blog index',
			'type' => 'blog_index',
		],
	],
	'submissions'   => [
		'columns#1'        => [
			'valid'   => [ 'c15-visit' ],
			'invalid' => [ 'c15-visit-bad' ],
		],
		'featured#1'       => [
			'valid'   => [ 'c15-club' ],
			'invalid' => [ 'c15-club-bad' ],
		],
		'widget:block-2#1' => [
			'valid'   => [ 'c15-signup' ],
			'invalid' => [ 'c15-signup-bad' ],
		],
	],
	'privacy_email' => 'ada@example.com',
	'skip'          => [
		'S14' => 'pending: R02',
		'S15' => 'pending: R02',
		'S16' => 'pending: R02',
	],
];
