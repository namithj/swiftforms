<?php
/**
 * User config 05: real-world mess.
 *
 * Raw stored data of a site, written exactly as 1.0.0 stores it. Frozen at the v1.0.0 tag.
 *
 * @package SwiftForms
 */

return [
	'slug'          => '05-nasty-content',
	'represents'    => 'Real-world mess: unicode, RTL, entities, quotes, script and shortcode look-alikes in labels, options, messages and visitor values.',
	'guards'        => [ 'C-6.2', 'C-7', 'XSS' ],
	'site'          => [
		'locale'     => 'en_US',
		'theme'      => 'sf-test-block',
		'multisite'  => false,
		'timezone'   => 'Europe/London',
		'permalinks' => '/%postname%/',
	],
	'options'       => [
		'swiftforms_email_notification_to' => 'owner@example.org',
		'swiftforms_email_footer_text'     => '<p>Sent from ünï &amp; co — <script src="https://evil.example/x.js"></script><a href="javascript:alert(1)">unsafe link</a></p>',
		'swiftforms_db_version'            => 1,
		'swiftforms_installed'             => [
			'version' => '1.0.0',
			'time'    => 1795082400,
		],
	],
	'posts'         => [
		[
			'key'       => 'nasty',
			'post_type' => 'page',
			'status'    => 'publish',
			'title'     => 'Nasty',
			'slug'      => 'nasty',
			'content'   => SF_FX( 'page-nasty' ),
		],
	],
	'users'         => [ 'admin' ],
	'submissions'   => [
		'nasty#1' => [
			'valid'   => [ 'c05-nasty-a', 'c05-nasty-b' ],
			'invalid' => [ 'c05-javascript-url' ],
		],
	],
	'privacy_email' => 'zoe@example.com',
	'skip'          => [
		'S14' => 'pending: R02',
		'S15' => 'pending: R02',
		'S16' => 'pending: R02',
	],
];
