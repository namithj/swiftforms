<?php // phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- Included by the loader: the variables are local to the include, and the snippet's names are test fixtures.
/**
 * User config 16: content in every state.
 *
 * Raw stored data of a site, written exactly as 1.0.0 stores it. Frozen at the v1.0.0 tag.
 *
 * Six pages, each with one form: published (the control), draft, private, password-protected, scheduled (a future
 * date) and trashed (with the trash meta WordPress adds). Only the published page and the password page for a visitor who has
 * the post-password cookie accept submissions; the others answer "closed" (C-3.4, C-3.11).
 *
 * @package SwiftForms
 */

return [
	'slug'          => '16-source-states',
	'represents'    => 'Content in every state: forms in a published, draft, private, password-protected, scheduled and trashed page; only published (and unlocked) ones accept.',
	'guards'        => [ 'ADR-001', 'C-3.4', 'C-3.11' ],
	'site'          => [
		'locale'     => 'en_US',
		'theme'      => 'sf-test-block',
		'multisite'  => false,
		'timezone'   => 'Europe/London',
		'permalinks' => '/%postname%/',
	],
	'options'       => [
		'swiftforms_email_notification_to' => 'pages@states.example',
		'swiftforms_db_version'            => 1,
		'swiftforms_installed'             => [
			'version' => '1.0.0',
			'time'    => 1795082400,
		],
	],
	'posts'         => [
		[
			'key'       => 'published',
			'post_type' => 'page',
			'status'    => 'publish',
			'title'     => 'Open page',
			'slug'      => 'published',
			'date'      => '2026-10-01 09:00:00',
			'content'   => SF_PARAGRAPH( 'This page is published (control).' ) . "\n\n" . SF_FORM(
				[
					'formId' => '5f160001-0000-4000-8000-000000000001',
					'title'  => 'Published (control)',
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
							'label'        => 'Email',
							'name'         => 'email',
							'required'     => true,
							'autocomplete' => 'email',
						]
					),
				],
				'Send',
				'Thank you (published).'
			),
		],
		[
			'key'       => 'draft',
			'post_type' => 'page',
			'status'    => 'draft',
			'title'     => 'Draft page',
			'slug'      => 'draft',
			'date'      => '2026-10-02 09:00:00',
			'content'   => SF_PARAGRAPH( 'This page is draft.' ) . "\n\n" . SF_FORM(
				[
					'formId' => '5f160002-0000-4000-8000-000000000002',
					'title'  => 'Draft',
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
							'label'        => 'Email',
							'name'         => 'email',
							'required'     => true,
							'autocomplete' => 'email',
						]
					),
				],
				'Send',
				'Thank you (draft).'
			),
		],
		[
			'key'       => 'private',
			'post_type' => 'page',
			'status'    => 'private',
			'title'     => 'Private page',
			'slug'      => 'private',
			'date'      => '2026-10-03 09:00:00',
			'content'   => SF_PARAGRAPH( 'This page is private.' ) . "\n\n" . SF_FORM(
				[
					'formId' => '5f160003-0000-4000-8000-000000000003',
					'title'  => 'Private',
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
							'label'        => 'Email',
							'name'         => 'email',
							'required'     => true,
							'autocomplete' => 'email',
						]
					),
				],
				'Send',
				'Thank you (private).'
			),
		],
		[
			'key'       => 'locked',
			'post_type' => 'page',
			'status'    => 'publish',
			'title'     => 'Locked page',
			'slug'      => 'locked',
			'date'      => '2026-10-04 09:00:00',
			'password'  => 'letmein',
			'content'   => SF_PARAGRAPH( 'This page is password protected.' ) . "\n\n" . SF_FORM(
				[
					'formId' => '5f160004-0000-4000-8000-000000000004',
					'title'  => 'Password protected',
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
							'label'        => 'Email',
							'name'         => 'email',
							'required'     => true,
							'autocomplete' => 'email',
						]
					),
				],
				'Send',
				'Thank you (locked).'
			),
		],
		[
			'key'       => 'scheduled',
			'post_type' => 'page',
			'status'    => 'future',
			'title'     => 'Scheduled page',
			'slug'      => 'scheduled',
			'date'      => '2099-01-01 09:00:00',
			'content'   => SF_PARAGRAPH( 'This page is scheduled.' ) . "\n\n" . SF_FORM(
				[
					'formId' => '5f160005-0000-4000-8000-000000000005',
					'title'  => 'Scheduled',
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
							'label'        => 'Email',
							'name'         => 'email',
							'required'     => true,
							'autocomplete' => 'email',
						]
					),
				],
				'Send',
				'Thank you (scheduled).'
			),
		],
		[
			'key'       => 'trashed',
			'post_type' => 'page',
			'status'    => 'trash',
			'title'     => 'Trashed page',
			'slug'      => 'trashed__trashed',
			'date'      => '2026-10-06 09:00:00',
			'meta'      => [
				'_wp_trash_meta_status' => 'publish',
				'_wp_trash_meta_time'   => 1794000000,
			],
			'content'   => SF_PARAGRAPH( 'This page is trashed.' ) . "\n\n" . SF_FORM(
				[
					'formId' => '5f160006-0000-4000-8000-000000000006',
					'title'  => 'Trashed',
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
							'label'        => 'Email',
							'name'         => 'email',
							'required'     => true,
							'autocomplete' => 'email',
						]
					),
				],
				'Send',
				'Thank you (trashed).'
			),
		],
	],
	'users'         => [ 'admin' ],
	'expect_closed' => [ 'locked#1' ],
	'source_states' => [
		'published#1' => 'accepted',
		'draft#1'     => 'closed',
		'private#1'   => 'closed',
		'locked#1'    => 'password',
		'scheduled#1' => 'closed',
		'trashed#1'   => 'closed',
	],
	'submissions'   => [
		'published#1' => [
			'valid'   => [ 'c16-ada' ],
			'invalid' => [ 'c16-bad' ],
		],
		'draft#1'     => [
			'valid'   => [ 'c16-ada' ],
			'invalid' => [ 'c16-bad' ],
		],
		'private#1'   => [
			'valid'   => [ 'c16-ada' ],
			'invalid' => [ 'c16-bad' ],
		],
		'locked#1'    => [
			'valid'   => [ 'c16-ada' ],
			'invalid' => [ 'c16-bad' ],
		],
		'scheduled#1' => [
			'valid'   => [ 'c16-ada' ],
			'invalid' => [ 'c16-bad' ],
		],
		'trashed#1'   => [
			'valid'   => [ 'c16-ada' ],
			'invalid' => [ 'c16-bad' ],
		],
	],
	'privacy_email' => 'ada@example.com',
	'checks'        => [ 'source_states' ],
	'skip'          => [
		'S14' => 'pending: R02',
		'S15' => 'pending: R02',
		'S16' => 'pending: R02',
	],
];
