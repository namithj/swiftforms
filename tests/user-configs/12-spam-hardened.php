<?php // phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- Included by the loader: the variables are local to the include, and the snippet's names are test fixtures.
/**
 * User config 12: spam-hardened site.
 *
 * Raw stored data of a site, written exactly as 1.0.0 stores it. Frozen at the v1.0.0 tag.
 *
 * The owner turned every spam control up: honeypot, eight seconds minimum, JS token, two submissions a minute
 * per form, a blocked-word list. Two forms: the contact form follows the site, the quick form has the form
 * override "spamProtection: off". The variant "discard" keeps the same site but throws spam away instead of storing it.
 *
 * @package BlockForms
 */

$bf_c12_hash = 'REPLACE_HASH';

return [
	'slug'          => '12-spam-hardened',
	'represents'    => 'Spam-plagued site: honeypot, 8 second minimum, JS token, 2 submissions a minute, blocked words; one form switches the protection off; a variant discards spam.',
	'guards'        => [ 'C-3.5', 'C-9.1' ],
	'site'          => [
		'locale'     => 'en_US',
		'theme'      => 'bf-test-block',
		'multisite'  => false,
		'timezone'   => 'Europe/London',
		'permalinks' => '/%postname%/',
	],
	'options'       => [
		'blockforms_general_store_ip'           => '1',
		'blockforms_general_store_user_agent'   => '1',
		'blockforms_email_notification_to'      => 'abuse-desk@example.org',
		'blockforms_spam_honeypot'              => '1',
		'blockforms_spam_min_seconds'           => 8,
		'blockforms_spam_js_token'              => '1',
		'blockforms_spam_rate_limit_per_minute' => 2,
		'blockforms_spam_blocked_words'         => "casino\nviagra\nfree money\nseo services",
		'blockforms_spam_spam_action'           => 'store',
		'blockforms_db_version'                 => 1,
		'blockforms_installed'                  => [
			'version' => '1.0.0',
			'time'    => 1795082400,
		],
	],
	'variants'      => [
		'discard' => [
			'blockforms_spam_spam_action' => 'discard',
		],
	],
	'posts'         => [
		[
			'key'       => 'contact',
			'post_type' => 'page',
			'status'    => 'publish',
			'title'     => 'Contact',
			'slug'      => 'contact',
			'date'      => '2026-10-01 09:00:00',
			'content'   => BF_HEADING( 'Contact us' ) . "\n\n" . BF_FORM(
				[
					'formId' => '5f120001-0000-4000-8000-000000000001',
					'title'  => 'Contact (protected)',
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
				'Thank you. We read every message.'
			),
		],
		[
			'key'       => 'quick',
			'post_type' => 'page',
			'status'    => 'publish',
			'title'     => 'Quick question',
			'slug'      => 'quick',
			'date'      => '2026-10-02 09:00:00',
			'content'   => BF_FORM(
				[
					'formId'         => '5f120002-0000-4000-8000-000000000002',
					'title'          => 'Quick question (protection off)',
					'spamProtection' => 'off',
				],
				[
					BF_FIELD(
						'email',
						[
							'label'    => 'Email',
							'name'     => 'email',
							'required' => true,
						]
					),
					BF_FIELD(
						'text',
						[
							'label'    => 'Question',
							'name'     => 'question',
							'required' => true,
						]
					),
				],
				'Ask',
				'Thanks, we will answer by email.'
			),
		],
	],
	'tables'        => [],
	'users'         => [ 'admin' ],
	'submissions'   => [
		'contact#1' => [
			'valid'   => [ 'c12-ada' ],
			'invalid' => [ 'c12-bad' ],
			'spam'    => [ 'c12-spam-seo', 'c12-spam-money' ],
		],
		'quick#1'   => [
			'valid'   => [ 'c12-quick' ],
			'invalid' => [ 'c12-quick-bad' ],
			'spam'    => [ 'c12-quick-spam' ],
		],
	],
	'privacy_email' => 'ada@example.com',
	'checks'        => [ 'spam_off' ],
	'skip'          => [
		'S14' => 'pending: R02',
		'S15' => 'pending: R02',
		'S16' => 'pending: R02',
	],
];
