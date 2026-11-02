<?php // phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- Included by the loader: the variables are local to the include, and the snippet's names are test fixtures.
/**
 * User config 19: network install.
 *
 * Raw stored data of a site, written exactly as 1.0.0 stores it. Frozen at the v1.0.0 tag. Needs WP_TESTS_MULTISITE=1
 * (bin/test-user-configs.sh sets it for this config) and is not run in the browser.
 *
 * The main site is an ordinary SwiftForms site (one contact page, two entries) in a network where the plugin is network-activated.
 * The check "multisite" then builds the other sites: "alpha" and "beta" (plugin on before the network activation, each with its
 * own tables, settings and entries), "legacy" (never had the plugin: no tables until its first request) and "fresh" (created after
 * the activation: installed on wp_initialize_site); then submits to alpha and deletes fresh and legacy.
 *
 * @package SwiftForms
 */

$sf_c19_form = static function ( $form_id, $title, $thanks ) {
	return SF_FORM(
		[
			'formId' => $form_id,
			'title'  => $title,
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
		$thanks
	);
};

return [
	'slug'          => '19-multisite-subsite',
	'represents'    => 'Network install: network-activated; two subsites with their own tables and settings, one without tables until its first request, one created after activation; deleting a subsite drops its tables.',
	'guards'        => [ 'ADR-003', 'C-1.4', 'C-4.1', 'C-5.2' ],
	'site'          => [
		'locale'         => 'en_US',
		'theme'          => 'sf-test-block',
		'multisite'      => true,
		'network_active' => true,
		'timezone'       => 'Europe/London',
		'permalinks'     => '/%postname%/',
	],
	'options'       => [
		'swiftforms_email_notification_to' => 'network-admin@network.example',
		'swiftforms_db_version'            => 1,
		'swiftforms_installed'             => [
			'version' => '1.0.0',
			'time'    => 1795082400,
		],
	],
	'posts'         => [
		[
			'key'       => 'main-contact',
			'post_type' => 'page',
			'status'    => 'publish',
			'title'     => 'Contact',
			'slug'      => 'contact',
			'date'      => '2026-10-01 09:00:00',
			'content'   => $sf_c19_form( '5f190001-0000-4000-8000-000000000001', 'Main site contact', 'Thanks from the main site.' ),
		],
	],
	'users'         => [ 'admin' ],
	'submissions'   => [
		'main-contact#1' => [
			'valid'   => [ 'c19-ada' ],
			'invalid' => [ 'c19-bad' ],
		],
	],
	'subsites'      => [
		'alpha'  => [
			'settings'   => [
				'swiftforms_email_notification_to' => 'alpha@network.example',
			],
			'page'       => $sf_c19_form( '5f190011-0000-4000-8000-000000000011', 'Alpha contact', 'Thanks from alpha.' ),
			'entries'    => [
				[
					'status'  => 'new',
					'created' => '2026-11-10 10:00:00',
					'values'  => [
						'name'  => 'Alpha One',
						'email' => 'one@alpha.example',
					],
				],
				[
					'status'  => 'read',
					'created' => '2026-11-11 10:00:00',
					'values'  => [
						'name'  => 'Alpha Two',
						'email' => 'two@alpha.example',
					],
				],
				[
					'status'  => 'spam',
					'created' => '2026-11-12 10:00:00',
					'values'  => [
						'name'  => 'Alpha Bot',
						'email' => 'bot@alpha.example',
					],
				],
			],
			'submission' => [
				'name'  => 'Ada Lovelace',
				'email' => 'ada@example.com',
			],
		],
		'beta'   => [
			'settings' => [
				'swiftforms_general_store_ip'       => '1',
				'swiftforms_general_retention_days' => 30,
				'swiftforms_email_notification_to'  => 'beta@network.example',
			],
			'page'     => $sf_c19_form( '5f190012-0000-4000-8000-000000000012', 'Beta contact', 'Thanks from beta.' ),
			'entries'  => [
				[
					'status'  => 'new',
					'created' => '2026-11-13 10:00:00',
					'values'  => [
						'name'  => 'Beta One',
						'email' => 'one@beta.example',
					],
				],
			],
		],
		'legacy' => [],
		'fresh'  => [
			'page'    => $sf_c19_form( '5f190013-0000-4000-8000-000000000013', 'Fresh contact', 'Thanks from fresh.' ),
			'entries' => [
				[
					'status'  => 'new',
					'created' => '2026-11-18 10:00:00',
					'values'  => [
						'name'  => 'Fresh One',
						'email' => 'one@fresh.example',
					],
				],
			],
		],
	],
	'privacy_email' => 'ada@example.com',
	'checks'        => [ 'multisite' ],
	'skip'          => [
		'S14' => 'pending: R02',
		'S15' => 'pending: R02',
		'S16' => 'pending: R02',
	],
];
