<?php
/**
 * User config 08: marketing funnel.
 *
 * Raw stored data of a site, written exactly as 1.0.0 stores it. Frozen at the v1.0.0 tag.
 *
 * @package SwiftForms
 */

return [
	'slug'          => '08-redirect-confirmations',
	'represents'    => 'Marketing funnel: four forms that redirect to a page, to an external address with the email in the query, to a deleted page and to a javascript: address.',
	'guards'        => [ 'C-2.3', 'C-3.4' ],
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
			'key'       => 'thanks',
			'post_type' => 'page',
			'status'    => 'publish',
			'title'     => 'Thanks',
			'slug'      => 'thanks',
			'content'   => SF_FX( 'page-thanks' ),
		],
		[
			'key'       => 'funnel',
			'post_type' => 'page',
			'status'    => 'publish',
			'title'     => 'Funnel',
			'slug'      => 'funnel',
			'content'   => SF_FX( 'page-funnel' ),
		],
	],
	'users'         => [ 'admin' ],
	'submissions'   => [
		'funnel#1' => [
			'valid'   => [ 'c08-ada' ],
			'invalid' => [ 'c08-bad' ],
		],
		'funnel#2' => [
			'valid'   => [ 'c08-ada-only-email' ],
			'invalid' => [ 'c08-bad' ],
		],
		'funnel#3' => [
			'valid'   => [ 'c08-ada' ],
			'invalid' => [ 'c08-bad' ],
		],
		'funnel#4' => [
			'valid'   => [ 'c08-ada' ],
			'invalid' => [ 'c08-bad' ],
		],
	],
	'privacy_email' => 'ada+funnel@example.com',
	'skip'          => [
		'S14' => 'pending: R02',
		'S15' => 'pending: R02',
		'S16' => 'pending: R02',
	],
];
