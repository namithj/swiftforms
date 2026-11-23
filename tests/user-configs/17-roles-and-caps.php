<?php // phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- Included by the loader: the variables are local to the include, and the snippet's names are test fixtures.
/**
 * User config 17: team site.
 *
 * Raw stored data of a site, written exactly as 1.0.0 stores it. Frozen at the v1.0.0 tag.
 *
 * An administrator, an editor, an author, a contributor and a support agent (a custom role with the capability
 * "bf_support_desk", stored in wp_user_roles like any role). A snippet (mu/17-capability-map.php) maps
 * blockforms_view_entries and blockforms_edit_entries to that capability, using the documented
 * blockforms_capability_map filter. BlockForms writes no role and no capability to the database.
 *
 * @package BlockForms
 */

$bf_c17_hash = '8a04a7a022e7fbc49390b1cf54e01740632d96d6f2a680cfa6000271eefdcfe4';

$bf_c17_entries = [];
$bf_c17_values  = [];
foreach ( [
	[ 1, 'new', 0, '2026-11-17 09:00:00', 'Ada Lovelace', 'ada@example.com', 'Can I get a demo?' ],
	[ 2, 'read', 1, '2026-11-16 15:30:00', 'Grace Hopper', 'grace@example.org', 'The invoice total looks wrong.' ],
	[ 3, 'read', 0, '2026-11-15 11:10:00', 'Alan Turing', 'alan@example.net', 'Where do I reset my password?' ],
	[ 4, 'new', 0, '2026-11-14 08:05:00', 'Edsger Dijkstra', 'edsger@example.com', 'A feature request.' ],
] as $row ) {
	$bf_c17_entries[] = [
		'id'          => $row[0],
		'form_id'     => '5f170001-0000-4000-8000-000000000001',
		'schema_hash' => $bf_c17_hash,
		'source'      => 'post:{{post:support}}',
		'status'      => $row[1],
		'starred'     => $row[2],
		'user_id'     => 0,
		'created_gmt' => $row[3],
		'updated_gmt' => $row[3],
	];
	foreach ( [
		'name'    => $row[4],
		'email'   => $row[5],
		'message' => $row[6],
	] as $field => $value ) {
		$bf_c17_values[] = [
			'entry_id' => $row[0],
			'field'    => $field,
			'idx'      => 0,
			'value'    => $value,
		];
	}
}

return [
	'slug'          => '17-roles-and-caps',
	'represents'    => 'Team site: administrator, editor, author, contributor and a custom support role that views entries through the capability map filter; no role or capability is written.',
	'guards'        => [ 'C-8.4' ],
	'site'          => [
		'locale'     => 'en_US',
		'theme'      => 'bf-test-block',
		'multisite'  => false,
		'timezone'   => 'Europe/London',
		'permalinks' => '/%postname%/',
	],
	'roles'         => [
		'bf_support' => [
			'name' => 'Support agent',
			'caps' => [
				'read'            => true,
				'bf_support_desk' => true,
			],
		],
	],
	'options'       => [
		'blockforms_email_notification_to' => 'team@support-site.example',
		'blockforms_db_version'            => 1,
		'blockforms_installed'             => [
			'version' => '1.0.0',
			'time'    => 1795082400,
		],
	],
	'posts'         => [
		[
			'key'       => 'support',
			'post_type' => 'page',
			'status'    => 'publish',
			'title'     => 'Support',
			'slug'      => 'support',
			'date'      => '2026-10-01 09:00:00',
			'content'   => BF_FORM(
				[
					'formId' => '5f170001-0000-4000-8000-000000000001',
					'title'  => 'Support request',
				],
				[
					BF_FIELD(
						'text',
						[
							'label'        => 'Name',
							'name'         => 'name',
							'required'     => true,
							'autocomplete' => 'name',
						]
					),
					BF_FIELD(
						'email',
						[
							'label'        => 'Email',
							'name'         => 'email',
							'required'     => true,
							'autocomplete' => 'email',
						]
					),
					BF_FIELD(
						'textarea',
						[
							'label'    => 'Message',
							'name'     => 'message',
							'required' => true,
						]
					),
				],
				'Send',
				'We will get back to you.'
			),
		],
	],
	'users'         => [
		'admin'       => 'admin',
		'editor'      => 'editor',
		'author'      => 'author',
		'contributor' => 'contributor',
		'support'     => 'bf_support',
	],
	'mu_plugins'    => [ '17-capability-map.php' ],
	'tables'        => [
		'blockforms_schemas'      => [
			[
				'schema_hash' => $bf_c17_hash,
				'form_id'     => '5f170001-0000-4000-8000-000000000001',
				'schema'      => <<<'JSON'
{"v":1,"hash":"8a04a7a022e7fbc49390b1cf54e01740632d96d6f2a680cfa6000271eefdcfe4","form_id":"5f170001-0000-4000-8000-000000000001","stored_id":"5f170001-0000-4000-8000-000000000001","source":"post:{{post:support}}","title":"Support request","fields":[{"type":"text","name":"name","label":"Name","help":"","placeholder":"","required":true,"defaultValue":"","width":"100","autocomplete":"name","minLength":0,"maxLength":0,"pattern":"","patternMessage":""},{"type":"email","name":"email","label":"Email","help":"","placeholder":"","required":true,"defaultValue":"","width":"100","autocomplete":"email"},{"type":"textarea","name":"message","label":"Message","help":"","placeholder":"","required":true,"defaultValue":"","width":"100","autocomplete":"","rows":4,"maxLength":0}]}
JSON
				,
				'created_gmt' => '2026-10-01 09:00:00',
			],
		],
		'blockforms_entries'      => $bf_c17_entries,
		'blockforms_entry_values' => $bf_c17_values,
	],
	'submissions'   => [
		'support#1' => [
			'valid'   => [ 'c17-ada' ],
			'invalid' => [ 'c17-bad' ],
		],
	],
	'privacy_email' => 'ada@example.com',
	'checks'        => [ 'roles' ],
	'skip'          => [
		'S14' => 'pending: R02',
		'S15' => 'pending: R02',
		'S16' => 'pending: R02',
	],
];
