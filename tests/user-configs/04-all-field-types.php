<?php
/**
 * User config 04: kitchen sink.
 *
 * Raw stored data of a site, written exactly as 1.0.0 stores it. Frozen at the v1.0.0 tag.
 *
 * @package SwiftForms
 */

return [
	'slug'          => '04-all-field-types',
	'represents'    => 'Kitchen sink: one form with every v1 field type and every attribute set.',
	'guards'        => [ 'C-2.4', 'C-3.2', 'C-3.10' ],
	'site'          => [
		'locale'     => 'en_US',
		'theme'      => 'sf-test-block',
		'multisite'  => false,
		'timezone'   => 'Europe/London',
		'permalinks' => '/%postname%/',
	],
	'options'       => [
		'swiftforms_db_version' => 1,
		'swiftforms_installed'  => [
			'version' => '1.0.0',
			'time'    => 1795082400,
		],
	],
	'posts'         => [
		[
			'key'       => 'everything',
			'post_type' => 'page',
			'status'    => 'publish',
			'title'     => 'Everything',
			'slug'      => 'everything',
			'content'   => SF_FX( 'page-all-fields' ),
		],
	],
	'users'         => [ 'admin' ],
	'submissions'   => [
		'everything#1' => [
			'valid'   => [ 'c04-full', 'c04-min' ],
			'invalid' => [ 'c04-bad-text', 'c04-bad-numbers', 'c04-bad-choices', 'c04-bad-misc' ],
		],
	],
	'privacy_email' => 'ada@example.com',
	'skip'          => [
		'S14' => 'pending: R02',
		'S15' => 'pending: R02',
		'S16' => 'pending: R02',
	],
];
