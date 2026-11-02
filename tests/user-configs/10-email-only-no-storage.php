<?php
/**
 * User config 10: "do not keep my data" site.
 *
 * Raw stored data of a site, written exactly as 1.0.0 stores it. Frozen at the v1.0.0 tag.
 *
 * @package SwiftForms
 */

return [
	'slug'          => '10-email-only-no-storage',
	'represents'    => 'Do-not-keep-data site: entry storage is off for the site, one form overrides it with "always", one says "never".',
	'guards'        => [ 'C-3.3', 'C-5.3' ],
	'site'          => [
		'locale'     => 'en_US',
		'theme'      => 'sf-test-block',
		'multisite'  => false,
		'timezone'   => 'Europe/London',
		'permalinks' => '/%postname%/',
	],
	'options'       => [
		'swiftforms_general_store_entries' => '0',
		'swiftforms_email_notification_to' => 'inbox@mailonly.example',
		'swiftforms_db_version'            => 1,
		'swiftforms_installed'             => [
			'version' => '1.0.0',
			'time'    => 1795082400,
		],
	],
	'posts'         => [
		[
			'key'       => 'stored',
			'post_type' => 'page',
			'status'    => 'publish',
			'title'     => 'Stored',
			'slug'      => 'stored',
			'content'   => SF_FX( 'page-storage-a' ),
		],
		[
			'key'       => 'never',
			'post_type' => 'page',
			'status'    => 'publish',
			'title'     => 'Never',
			'slug'      => 'never',
			'content'   => SF_FX( 'page-storage-b' ),
		],
	],
	'users'         => [ 'admin' ],
	'submissions'   => [
		'stored#1' => [
			'valid'   => [ 'c10-ada' ],
			'invalid' => [ 'c10-bad' ],
		],
		'stored#2' => [
			'valid'   => [ 'c10-ada' ],
			'invalid' => [ 'c10-bad' ],
		],
		'never#1'  => [
			'valid'   => [ 'c10-ada' ],
			'invalid' => [ 'c10-bad' ],
		],
	],
	'privacy_email' => 'ada@example.com',
	'skip'          => [
		'S14' => 'pending: R02',
		'S15' => 'pending: R02',
		'S16' => 'pending: R02',
	],
];
