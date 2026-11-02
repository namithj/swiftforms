<?php // phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- Included by the loader: the variables are local to the include, and the snippet's names are test fixtures.
/**
 * User config 18: broken and partial data.
 *
 * Raw stored data of a site, written exactly as 1.0.0 stores it. Frozen at the v1.0.0 tag.
 *
 * Nothing here may be fatal. The base site has, in its content: a form block without a formId, the same formId stored in two
 * pages (a "duplicate post" copy), a form with duplicate field names, a form that carries attributes and blocks a newer
 * release would write (an unknown attribute on the form and on a field, an unknown wrapper block around a field, an unknown
 * field block), and a good control form. In its tables: an orphan entry whose form (and page) is gone, and an entry whose
 * schema_hash has no snapshot row. The variants change the options: options holding garbage (an array, an object, an empty string), options of
 * the wrong type or outside the allowed values, one option only, and the install marker missing.
 *
 * @package SwiftForms
 */

$sf_c18_hash = '8a04a7a022e7fbc49390b1cf54e01740632d96d6f2a680cfa6000271eefdcfe4';
$sf_c18_lost = '1f2e3d4c5b6a79881f2e3d4c5b6a79881f2e3d4c5b6a79881f2e3d4c5b6a7988';

$sf_c18_text_field = SF_FIELD(
	'text',
	[
		'label'        => 'Name',
		'name'         => 'name',
		'required'     => true,
		'autocomplete' => 'name',
	]
);
$sf_c18_email      = SF_FIELD(
	'email',
	[
		'label'        => 'Email',
		'name'         => 'email',
		'required'     => true,
		'autocomplete' => 'email',
	]
);
$sf_c18_message    = SF_FIELD(
	'textarea',
	[
		'label'    => 'Message',
		'name'     => 'message',
		'required' => true,
	]
);

return [
	'slug'          => '18-corrupt-and-partial',
	'represents'    => 'Broken data: settings as a string, wrong-typed settings, no install marker; a form without formId, a formId in two pages, duplicate field names, unknown attributes and blocks, an orphan entry, a schema_hash without a snapshot.',
	'guards'        => [ 'C-1.4', 'C-2.7', 'C-2.8', 'C-3.9', 'C-4.4', 'C-5.2' ],
	'site'          => [
		'locale'     => 'en_US',
		'theme'      => 'sf-test-block',
		'multisite'  => false,
		'timezone'   => 'Europe/London',
		'permalinks' => '/%postname%/',
	],
	'options'       => [
		'swiftforms_email_notification_to' => 'owner@broken-site.example',
		'swiftforms_db_version'            => 1,
		'swiftforms_installed'             => [
			'version' => '1.0.0',
			'time'    => 1795082400,
		],
	],
	'variants'      => [
		// Options holding something that is not a scalar of the right kind: an array, an object, an empty string, a huge number.
		'garbage-options'   => [
			'swiftforms_email_notification_to'           => [ 'owner@broken-site.example' ],
			'swiftforms_spam_blocked_words'              => [ 'casino', 'viagra' ],
			'swiftforms_general_default_success_message' => (object) [ 'text' => 'Thanks.' ],
			'swiftforms_general_store_entries'           => '',
			'swiftforms_general_retention_days'          => '99999999999999999999',
			'swiftforms_spam_min_seconds'                => [ 3 ],
		],
		// Individual options of the wrong type or outside the allowed values; the valid ones next to them still count.
		'corrupt-options'   => [
			'swiftforms_general_store_entries'      => 'yes',
			'swiftforms_general_retention_days'     => 'thirty',
			'swiftforms_general_load_styles'        => 'sometimes',
			'swiftforms_spam_honeypot'              => 'no',
			'swiftforms_spam_min_seconds'           => -5,
			'swiftforms_spam_rate_limit_per_minute' => '2',
			'swiftforms_spam_spam_action'           => 'delete',
			'swiftforms_email_accent_color'         => 'blue',
			'swiftforms_privacy_exporter'           => 5,
			'swiftforms_advanced_debug_log'         => 'true',
		],
		// A site that saved one setting only (everything else is absent, also the notification address).
		'partial-options'   => [
			'swiftforms_email_notification_to' => null,
			'swiftforms_email_from_name'       => 'Partial Site',
		],
		'no-install-marker' => [
			'swiftforms_installed' => null,
		],
	],
	'posts'         => [
		[
			'key'       => 'control',
			'post_type' => 'page',
			'status'    => 'publish',
			'title'     => 'Control',
			'slug'      => 'control',
			'date'      => '2026-09-01 09:00:00',
			'content'   => SF_FORM(
				[
					'formId' => '5f180001-0000-4000-8000-000000000001',
					'title'  => 'Control form',
				],
				[ $sf_c18_text_field, $sf_c18_email ],
				'Send',
				'Thanks.'
			),
		],
		[
			'key'       => 'nameless',
			'post_type' => 'page',
			'status'    => 'publish',
			'title'     => 'Nameless',
			'slug'      => 'nameless',
			'date'      => '2026-09-02 09:00:00',
			'content'   => SF_FORM(
				[ 'title' => 'Form without an id' ],
				[ $sf_c18_text_field, $sf_c18_email ],
				'Send',
				'Thanks.'
			),
		],
		[
			'key'       => 'orig',
			'post_type' => 'page',
			'status'    => 'publish',
			'title'     => 'Original',
			'slug'      => 'original',
			'date'      => '2026-09-03 09:00:00',
			'content'   => SF_FORM(
				[
					'formId' => '5f180003-0000-4000-8000-000000000003',
					'title'  => 'Shared id',
				],
				[ $sf_c18_text_field, $sf_c18_email, $sf_c18_message ],
				'Send',
				'Thanks.'
			),
		],
		[
			'key'       => 'copy',
			'post_type' => 'page',
			'status'    => 'publish',
			'title'     => 'Original (copy)',
			'slug'      => 'original-copy',
			'date'      => '2026-09-20 09:00:00',
			'content'   => SF_FORM(
				[
					'formId' => '5f180003-0000-4000-8000-000000000003',
					'title'  => 'Shared id',
				],
				[ $sf_c18_text_field, $sf_c18_email, $sf_c18_message ],
				'Send',
				'Thanks.'
			),
		],
		[
			'key'       => 'dupes',
			'post_type' => 'page',
			'status'    => 'publish',
			'title'     => 'Duplicate names',
			'slug'      => 'duplicate-names',
			'date'      => '2026-09-04 09:00:00',
			'content'   => SF_FORM(
				[
					'formId' => '5f180004-0000-4000-8000-000000000004',
					'title'  => 'Duplicate field names',
				],
				[
					$sf_c18_text_field,
					$sf_c18_email,
					SF_FIELD(
						'email',
						[
							'label'    => 'Confirm email',
							'name'     => 'email',
							'required' => true,
						]
					),
					$sf_c18_message,
				],
				'Send',
				'Thanks.'
			),
		],
		[
			'key'       => 'future',
			'post_type' => 'page',
			'status'    => 'publish',
			'title'     => 'From the future',
			'slug'      => 'from-the-future',
			'date'      => '2026-09-05 09:00:00',
			'content'   => SF_FORM(
				[
					'formId'     => '5f180005-0000-4000-8000-000000000005',
					'title'      => 'Written by a newer release',
					'futureFlag' => [
						'mode' => 'x',
						'deep' => [ 1, 2 ],
					],
				],
				[
					SF_BLOCK(
						'swiftforms/step',
						[
							'title' => 'Step one',
							'index' => 1,
						],
						SF_FIELD(
							'text',
							[
								'label'      => 'Name',
								'name'       => 'name',
								'required'   => true,
								'futureAttr' => 'kept',
							]
						)
					),
					SF_FIELD(
						'rating',
						[
							'label' => 'How did we do?',
							'name'  => 'rating',
							'max'   => 5,
						]
					),
					$sf_c18_email,
				],
				'Send',
				'Thanks.'
			),
		],
	],
	'tables'        => [
		'swiftforms_schemas'      => [
			[
				'schema_hash' => $sf_c18_hash,
				'form_id'     => '5f180003-0000-4000-8000-000000000003',
				'schema'      => <<<'JSON'
{"v":1,"hash":"8a04a7a022e7fbc49390b1cf54e01740632d96d6f2a680cfa6000271eefdcfe4","form_id":"5f180003-0000-4000-8000-000000000003","stored_id":"5f180003-0000-4000-8000-000000000003","source":"post:{{post:orig}}","title":"Shared id","fields":[{"type":"text","name":"name","label":"Name","help":"","placeholder":"","required":true,"defaultValue":"","width":"100","autocomplete":"name","minLength":0,"maxLength":0,"pattern":"","patternMessage":""},{"type":"email","name":"email","label":"Email","help":"","placeholder":"","required":true,"defaultValue":"","width":"100","autocomplete":"email"},{"type":"textarea","name":"message","label":"Message","help":"","placeholder":"","required":true,"defaultValue":"","width":"100","autocomplete":"","rows":4,"maxLength":0}]}
JSON
				,
				'created_gmt' => '2026-09-03 09:00:00',
			],
			[
				'schema_hash' => '9a8b7c6d5e4f30219a8b7c6d5e4f30219a8b7c6d5e4f30219a8b7c6d5e4f3021',
				'form_id'     => '5f18dead-0000-4000-8000-00000000dead',
				'schema'      => '{"v":1,"hash":"9a8b7c6d5e4f30219a8b7c6d5e4f30219a8b7c6d5e4f30219a8b7c6d5e4f3021","form_id":"5f18dead-0000-4000-8000-00000000dead","stored_id":"5f18dead-0000-4000-8000-00000000dead","source":"post:777777","title":"Deleted form","fields":[{"type":"text","name":"name","label":"Your name","help":"","placeholder":"","required":true,"defaultValue":"","width":"100","autocomplete":"name","minLength":0,"maxLength":0,"pattern":"","patternMessage":""},{"type":"email","name":"email","label":"Your email","help":"","placeholder":"","required":true,"defaultValue":"","width":"100","autocomplete":"email"}]}',
				'created_gmt' => '2026-06-01 09:00:00',
			],
		],
		'swiftforms_entries'      => [
			[
				'id'          => 1,
				'form_id'     => '5f180003-0000-4000-8000-000000000003',
				'schema_hash' => $sf_c18_hash,
				'source'      => 'post:{{post:orig}}',
				'status'      => 'new',
				'starred'     => 0,
				'user_id'     => 0,
				'created_gmt' => '2026-11-10 09:00:00',
				'updated_gmt' => '2026-11-10 09:00:00',
			],
			[
				'id'          => 2,
				'form_id'     => '5f180003-0000-4000-8000-000000000003',
				'schema_hash' => $sf_c18_lost,
				'source'      => 'post:{{post:orig}}',
				'status'      => 'read',
				'starred'     => 1,
				'user_id'     => 0,
				'created_gmt' => '2026-11-09 09:00:00',
				'updated_gmt' => '2026-11-09 09:00:00',
			],
			[
				'id'          => 3,
				'form_id'     => '5f18dead-0000-4000-8000-00000000dead',
				'schema_hash' => '9a8b7c6d5e4f30219a8b7c6d5e4f30219a8b7c6d5e4f30219a8b7c6d5e4f3021',
				'source'      => 'post:777777',
				'status'      => 'new',
				'starred'     => 0,
				'user_id'     => 0,
				'created_gmt' => '2026-08-01 09:00:00',
				'updated_gmt' => '2026-08-01 09:00:00',
			],
		],
		'swiftforms_entry_values' => [
			[
				'entry_id' => 1,
				'field'    => 'name',
				'idx'      => 0,
				'value'    => 'Linus Torvalds',
			],
			[
				'entry_id' => 1,
				'field'    => 'email',
				'idx'      => 0,
				'value'    => 'linus@example.org',
			],
			[
				'entry_id' => 1,
				'field'    => 'message',
				'idx'      => 0,
				'value'    => 'An entry stored under the current snapshot.',
			],
			[
				'entry_id' => 2,
				'field'    => 'name',
				'idx'      => 0,
				'value'    => 'Margaret Hamilton',
			],
			[
				'entry_id' => 2,
				'field'    => 'email',
				'idx'      => 0,
				'value'    => 'margaret@example.org',
			],
			[
				'entry_id' => 2,
				'field'    => 'legacy_phone',
				'idx'      => 0,
				'value'    => '+1 555 0100',
			],
			[
				'entry_id' => 3,
				'field'    => 'name',
				'idx'      => 0,
				'value'    => 'Dennis Ritchie',
			],
			[
				'entry_id' => 3,
				'field'    => 'email',
				'idx'      => 0,
				'value'    => 'dennis@example.org',
			],
		],
	],
	'users'         => [ 'admin' ],
	'submissions'   => [
		'control#1'  => [
			'valid'   => [ 'c18-ada' ],
			'invalid' => [ 'c18-bad' ],
		],
		'nameless#1' => [
			'valid'   => [ 'c18-ada' ],
			'invalid' => [ 'c18-bad' ],
		],
		'orig#1'     => [
			'valid'   => [ 'c18-ada-message' ],
			'invalid' => [ 'c18-bad' ],
		],
		'copy#1'     => [
			'valid'   => [ 'c18-ada-message' ],
			'invalid' => [ 'c18-bad' ],
		],
		'dupes#1'    => [
			'valid'   => [ 'c18-ada-dupes' ],
			'invalid' => [ 'c18-bad' ],
		],
		'future#1'   => [
			'valid'   => [ 'c18-ada-future' ],
			'invalid' => [ 'c18-bad' ],
		],
	],
	'privacy_email' => 'linus@example.org',
	'resave_writes' => true,
	'checks'        => [ 'entry_details', 'resave' ],
	'skip'          => [
		'S14' => 'pending: R02',
		'S15' => 'pending: R02',
		'S16' => 'pending: R02',
	],
];
