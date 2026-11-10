<?php
/**
 * User config 07: old-school mail setup.
 *
 * Raw stored data of a site, written exactly as 1.0.0 stores it. Frozen at the v1.0.0 tag.
 *
 * @package SwiftForms
 */

return [
	'slug'          => '07-plain-email-custom-from',
	'represents'    => 'Old-school mail setup: HTML template off, custom From, a plain-text notification, and an SMTP-plugin look-alike on wp_mail_from and phpmailer_init.',
	'guards'        => [ 'C-6.1', 'C-6.3' ],
	'site'          => [
		'locale'     => 'en_US',
		'theme'      => 'sf-test-block',
		'multisite'  => false,
		'timezone'   => 'Europe/London',
		'permalinks' => '/%postname%/',
	],
	'options'       => [
		'swiftforms_email_from_name'       => 'Old School Shop',
		'swiftforms_email_from_email'      => 'shop@oldschool.example',
		'swiftforms_email_notification_to' => 'owner@oldschool.example',
		'swiftforms_email_html_template'   => '0',
		'swiftforms_db_version'            => 1,
		'swiftforms_installed'             => [
			'version' => '1.0.0',
			'time'    => 1795082400,
		],
	],
	'posts'         => [
		[
			'key'       => 'shop',
			'post_type' => 'page',
			'status'    => 'publish',
			'title'     => 'Shop',
			'slug'      => 'shop',
			'content'   => SF_FX( 'page-old-school' ),
		],
	],
	'users'         => [ 'admin' ],
	'mu_plugins'    => [ '07-smtp-sim.php' ],
	'mail_capture'  => 'phpmailer',
	'submissions'   => [
		'shop#1' => [
			'valid'   => [ 'c07-ada' ],
			'invalid' => [ 'c07-bad' ],
		],
	],
	'privacy_email' => 'ada@example.com',
	'skip'          => [
		'S14' => 'pending: R02',
		'S15' => 'pending: R02',
		'S16' => 'pending: R02',
	],
];
