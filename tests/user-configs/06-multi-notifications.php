<?php
/**
 * User config 06: agency lead routing.
 *
 * Raw stored data of a site, written exactly as 1.0.0 stores it. Frozen at the v1.0.0 tag.
 *
 * @package SwiftForms
 */

return [
	'slug'          => '06-multi-notifications',
	'represents'    => 'Agency lead routing: three notifications (comma lists, cc and bcc, a recipient taken from an email field and, as a negative case, from a text field), a bare Reply-To, visitor mail under the per-address limit, the HTML template with logo, accent colour and footer.',
	'guards'        => [ 'C-6', 'C-6.1', 'C-6.2', 'C-6.3' ],
	'site'          => [
		'locale'     => 'en_US',
		'theme'      => 'sf-test-block',
		'multisite'  => false,
		'timezone'   => 'Europe/London',
		'permalinks' => '/%postname%/',
	],
	'options'       => [
		'swiftforms_general_store_ip'         => '1',
		'swiftforms_general_store_user_agent' => '1',
		'swiftforms_email_from_name'          => 'Agency Leads',
		'swiftforms_email_from_email'         => 'leads@agency.example',
		'swiftforms_email_notification_to'    => 'owner@agency.example',
		'swiftforms_email_html_template'      => '1',
		'swiftforms_email_logo_id'            => '{{post:logo}}',
		'swiftforms_email_accent_color'       => '#1d4ed8',
		'swiftforms_email_footer_text'        => '<p>Sent by Agency Ltd. <a href="https://agency.example/privacy">Privacy</a></p>',
		'swiftforms_spam_blocked_words'       => "casino\nviagra",
		'swiftforms_db_version'               => 1,
		'swiftforms_installed'                => [
			'version' => '1.0.0',
			'time'    => 1795082400,
		],
	],
	'posts'         => [
		[
			'key'       => 'logo',
			'post_type' => 'attachment',
			'status'    => 'inherit',
			'title'     => 'Agency logo',
			'slug'      => 'agency-logo',
			'mime'      => 'image/png',
			'meta'      => [
				'_wp_attached_file' => '2026/11/agency-logo.png',
			],
			'content'   => '',
		],
		[
			'key'       => 'start',
			'post_type' => 'page',
			'status'    => 'publish',
			'title'     => 'Start a project',
			'slug'      => 'start',
			'content'   => SF_FX( 'page-agency-lead' ),
		],
	],
	'users'         => [ 'admin' ],
	'submissions'   => [
		'start#1' => [
			'valid'   => [ 'c06-lead-a', 'c06-lead-b' ],
			'invalid' => [ 'c06-bad-department' ],
			'spam'    => [ 'c06-spam-casino' ],
		],
	],
	'privacy_email' => 'ada@bigco.example',
	'skip'          => [
		'S14' => 'pending: R02',
		'S15' => 'pending: R02',
		'S16' => 'pending: R02',
	],
];
