<?php
/**
 * User config 02: typical small business.
 *
 * Raw stored data of a site, written exactly as 1.0.0 stores it. Frozen at the v1.0.0 tag.
 *
 * @package SwiftForms
 */

return [
	'slug'          => '02-minimal-contact',
	'represents'    => 'Typical small business: saved settings hold only the notification recipient, one contact form with a confirmation message.',
	'guards'        => [ 'C-3.3', 'C-5.2', 'C-6.1' ],
	'site'          => [
		'locale'     => 'en_US',
		'theme'      => 'sf-test-block',
		'multisite'  => false,
		'timezone'   => 'Europe/London',
		'permalinks' => '/%postname%/',
	],
	'options'       => [
		'swiftforms_email_notification_to' => 'owner@example.org',
		'swiftforms_db_version'            => 1,
		'swiftforms_installed'             => [
			'version' => '1.0.0',
			'time'    => 1795082400,
		],
	],
	'posts'         => [
		[
			'key'       => 'contact',
			'post_type' => 'page',
			'status'    => 'publish',
			'title'     => 'Contact',
			'slug'      => 'contact',
			'content'   => SF_FX( 'page-minimal-contact' ),
		],
	],
	'tables'        => [
		'swiftforms_schemas'      => [
			[
				'schema_hash' => '2d82400fd7db8befa96f8a78e3bcc702e041a1f221299dc03fa7008310c37b1e',
				'form_id'     => '5f020001-0000-4000-8000-000000000001',
				'schema'      => '{"v":1,"hash":"2d82400fd7db8befa96f8a78e3bcc702e041a1f221299dc03fa7008310c37b1e","form_id":"5f020001-0000-4000-8000-000000000001","stored_id":"5f020001-0000-4000-8000-000000000001","source":"post:{{post:contact}}","title":"Contact us","fields":[{"type":"text","name":"name","label":"Your name","help":"","placeholder":"","required":true,"defaultValue":"","width":"100","autocomplete":"name","minLength":0,"maxLength":0,"pattern":"","patternMessage":""},{"type":"email","name":"email","label":"Email","help":"","placeholder":"","required":true,"defaultValue":"","width":"100","autocomplete":"email"},{"type":"textarea","name":"message","label":"How can we help?","help":"","placeholder":"","required":true,"defaultValue":"","width":"100","autocomplete":"","rows":4,"maxLength":0}]}',
				'created_gmt' => '2026-10-01 09:00:00',
			],
		],
		'swiftforms_entries'      => [
			[
				'id'          => 1,
				'form_id'     => '5f020001-0000-4000-8000-000000000001',
				'schema_hash' => '2d82400fd7db8befa96f8a78e3bcc702e041a1f221299dc03fa7008310c37b1e',
				'source'      => 'post:{{post:contact}}',
				'status'      => 'new',
				'starred'     => 0,
				'user_id'     => 0,
				'created_gmt' => '2026-11-18 09:30:00',
				'updated_gmt' => '2026-11-18 09:30:00',
			],
			[
				'id'          => 2,
				'form_id'     => '5f020001-0000-4000-8000-000000000001',
				'schema_hash' => '2d82400fd7db8befa96f8a78e3bcc702e041a1f221299dc03fa7008310c37b1e',
				'source'      => 'post:{{post:contact}}',
				'status'      => 'read',
				'starred'     => 0,
				'user_id'     => 0,
				'created_gmt' => '2026-11-15 14:05:00',
				'updated_gmt' => '2026-11-15 14:05:00',
			],
			[
				'id'          => 3,
				'form_id'     => '5f020001-0000-4000-8000-000000000001',
				'schema_hash' => '2d82400fd7db8befa96f8a78e3bcc702e041a1f221299dc03fa7008310c37b1e',
				'source'      => 'post:{{post:contact}}',
				'status'      => 'read',
				'starred'     => 1,
				'user_id'     => 0,
				'created_gmt' => '2026-11-10 08:45:00',
				'updated_gmt' => '2026-11-10 08:45:00',
			],
			[
				'id'          => 4,
				'form_id'     => '5f020001-0000-4000-8000-000000000001',
				'schema_hash' => '2d82400fd7db8befa96f8a78e3bcc702e041a1f221299dc03fa7008310c37b1e',
				'source'      => 'post:{{post:contact}}',
				'status'      => 'spam',
				'starred'     => 0,
				'user_id'     => 0,
				'created_gmt' => '2026-11-09 03:12:00',
				'updated_gmt' => '2026-11-09 03:12:00',
			],
			[
				'id'          => 5,
				'form_id'     => '5f020001-0000-4000-8000-000000000001',
				'schema_hash' => '2d82400fd7db8befa96f8a78e3bcc702e041a1f221299dc03fa7008310c37b1e',
				'source'      => 'post:{{post:contact}}',
				'status'      => 'trash',
				'starred'     => 0,
				'user_id'     => 0,
				'created_gmt' => '2026-11-01 16:20:00',
				'updated_gmt' => '2026-11-01 16:20:00',
			],
		],
		'swiftforms_entry_values' => [
			[
				'entry_id' => 1,
				'field'    => 'name',
				'idx'      => 0,
				'value'    => 'Henry Ford',
			],
			[
				'entry_id' => 1,
				'field'    => 'email',
				'idx'      => 0,
				'value'    => 'henry@example.com',
			],
			[
				'entry_id' => 1,
				'field'    => 'message',
				'idx'      => 0,
				'value'    => 'Can I book a visit for next week?',
			],
			[
				'entry_id' => 2,
				'field'    => 'name',
				'idx'      => 0,
				'value'    => 'Marie Curie',
			],
			[
				'entry_id' => 2,
				'field'    => 'email',
				'idx'      => 0,
				'value'    => 'marie@example.org',
			],
			[
				'entry_id' => 2,
				'field'    => 'message',
				'idx'      => 0,
				'value'    => 'Thanks for the quick reply last time.',
			],
			[
				'entry_id' => 3,
				'field'    => 'name',
				'idx'      => 0,
				'value'    => 'Nikola Tesla',
			],
			[
				'entry_id' => 3,
				'field'    => 'email',
				'idx'      => 0,
				'value'    => 'nikola@example.net',
			],
			[
				'entry_id' => 3,
				'field'    => 'message',
				'idx'      => 0,
				'value'    => 'Invoice question: who do I send it to?',
			],
			[
				'entry_id' => 4,
				'field'    => 'name',
				'idx'      => 0,
				'value'    => 'Cheap Pills',
			],
			[
				'entry_id' => 4,
				'field'    => 'email',
				'idx'      => 0,
				'value'    => 'pills@spam.example',
			],
			[
				'entry_id' => 4,
				'field'    => 'message',
				'idx'      => 0,
				'value'    => 'Buy now!!!',
			],
			[
				'entry_id' => 5,
				'field'    => 'name',
				'idx'      => 0,
				'value'    => 'Old Customer',
			],
			[
				'entry_id' => 5,
				'field'    => 'email',
				'idx'      => 0,
				'value'    => 'old@example.com',
			],
			[
				'entry_id' => 5,
				'field'    => 'message',
				'idx'      => 0,
				'value'    => 'Please delete this.',
			],
		],
	],
	'users'         => [ 'admin', 'editor' ],
	'submissions'   => [
		'contact#1' => [
			'valid'   => [ 'c02-ada', 'c02-grace' ],
			'invalid' => [ 'c02-bad-email' ],
		],
	],
	'privacy_email' => 'ada@example.com',
	'skip'          => [
		'S14' => 'pending: R02',
		'S15' => 'pending: R02',
		'S16' => 'pending: R02',
	],
];
