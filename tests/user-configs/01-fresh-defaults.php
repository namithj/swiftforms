<?php
/**
 * User config 01: a brand-new install.
 *
 * Raw stored data of a site, written exactly as 1.0.0 stores it. Frozen at the v1.0.0 tag.
 *
 * @package SwiftForms
 */

return [
	'slug'          => '01-fresh-defaults',
	'represents'    => 'Brand-new install that never opened the settings page: no settings row, one page with the inserted-default Form block.',
	'guards'        => [ 'C-1.4', 'C-5.2', 'C-5.3', 'C-2.6' ],
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
			'key'       => 'contact',
			'post_type' => 'page',
			'status'    => 'publish',
			'title'     => 'Contact',
			'slug'      => 'contact',
			'content'   => SF_FX( 'form-default' ),
		],
	],
	'users'         => [ 'admin' ],
	'submissions'   => [
		'contact#1' => [
			'valid'   => [ 'c01-ada' ],
			'invalid' => [ 'c01-bad-email' ],
		],
	],
	'privacy_email' => 'ada@example.com',
	'checks'        => [ 'patterns' ],
	'skip'          => [
		'S14' => 'pending: R02',
		'S15' => 'pending: R02',
		'S16' => 'pending: R02',
	],
];
