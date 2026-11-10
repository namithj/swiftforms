<?php // phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- Included by the loader: the variables are local to the include, and the snippet's names are test fixtures.
/**
 * User config 11: busy site with a long history.
 *
 * Raw stored data of a site, written exactly as 1.0.0 stores it. Frozen at the v1.0.0 tag.
 *
 * Three forms and 5,000 entries over the last year (entry n is created (5000 - n) x 6307 seconds before the
 * frozen "now", so ids and dates rise together): 3,000 in the contact form, 1,200 for the newsletter, 800 in the survey.
 * Statuses cycle new, read, spam and trash; every ninth entry is starred. The contact form changed three times, so its
 * entries point at four schema snapshots (the current one and three older ones, with a field added, removed and
 * renamed over time); the survey stores a multi-value checkbox field (one value row per tick).
 *
 * @package SwiftForms
 */

$sf_c11_now  = 1795082400;
$sf_c11_step = 6307;

// The schema snapshot the plugin stores for each current form (hash and JSON as the plugin wrote them).
$sf_c11_current = [
	'contact'    => [
		'hash'   => '71f98317627b3e2ca8b4eecf11d0b3e253ecb4cbf3a3f7dcb936dfd9bf003c9a',
		'schema' => <<<'JSON'
{"v":1,"hash":"71f98317627b3e2ca8b4eecf11d0b3e253ecb4cbf3a3f7dcb936dfd9bf003c9a","form_id":"5f110001-0000-4000-8000-000000000001","stored_id":"5f110001-0000-4000-8000-000000000001","source":"post:900001","title":"Contact","fields":[{"type":"text","name":"name","label":"Name","help":"","placeholder":"","required":true,"defaultValue":"","width":"100","autocomplete":"name","minLength":0,"maxLength":0,"pattern":"","patternMessage":""},{"type":"email","name":"email","label":"Email","help":"","placeholder":"","required":true,"defaultValue":"","width":"100","autocomplete":"email"},{"type":"select","name":"topic","label":"Topic","help":"","placeholder":"","required":false,"defaultValue":"","width":"100","autocomplete":"","options":[{"value":"sales","label":"Sales"},{"value":"support","label":"Support"},{"value":"press","label":"Press"}],"multiple":false,"placeholderOption":"Choose a topic"},{"type":"textarea","name":"details","label":"Details","help":"","placeholder":"","required":true,"defaultValue":"","width":"100","autocomplete":"","rows":4,"maxLength":0}]}
JSON
		,
	],
	'newsletter' => [
		'hash'   => '3318e4a0fa617118e8441c5283a417c78eeb6a291a6122e9cf429372f66c6eb5',
		'schema' => <<<'JSON'
{"v":1,"hash":"3318e4a0fa617118e8441c5283a417c78eeb6a291a6122e9cf429372f66c6eb5","form_id":"5f110002-0000-4000-8000-000000000002","stored_id":"5f110002-0000-4000-8000-000000000002","source":"post:900002","title":"Newsletter","fields":[{"type":"email","name":"email","label":"Email","help":"","placeholder":"","required":true,"defaultValue":"","width":"100","autocomplete":"email"}]}
JSON
		,
	],
	'survey'     => [
		'hash'   => '84acdf8d8f3b4809803a62f4f71c60ac4150485e027b0bc6c0b0ff2cc843c3eb',
		'schema' => <<<'JSON'
{"v":1,"hash":"84acdf8d8f3b4809803a62f4f71c60ac4150485e027b0bc6c0b0ff2cc843c3eb","form_id":"5f110003-0000-4000-8000-000000000003","stored_id":"5f110003-0000-4000-8000-000000000003","source":"post:900003","title":"Reader survey","fields":[{"type":"text","name":"name","label":"Name","help":"","placeholder":"","required":true,"defaultValue":"","width":"100","autocomplete":"","minLength":0,"maxLength":0,"pattern":"","patternMessage":""},{"type":"checkboxes","name":"interests","label":"What would you like to hear about?","help":"","placeholder":"","required":true,"defaultValue":"","width":"100","autocomplete":"","options":[{"value":"news","label":"News"},{"value":"events","label":"Events"},{"value":"offers","label":"Offers"},{"value":"jobs","label":"Jobs"},{"value":"partners","label":"Partners"}],"layout":"stack","minChoices":1,"maxChoices":3},{"type":"textarea","name":"comment","label":"Comment","help":"","placeholder":"","required":false,"defaultValue":"","width":"100","autocomplete":"","rows":4,"maxLength":0}]}
JSON
		,
	],
];

return ( static function () use ( $sf_c11_now, $sf_c11_step, $sf_c11_current ) {
	$form_ids = [
		'contact'    => '5f110001-0000-4000-8000-000000000001',
		'newsletter' => '5f110002-0000-4000-8000-000000000002',
		'survey'     => '5f110003-0000-4000-8000-000000000003',
	];

	// Older snapshots of the contact form: v1 (name, email, message), v2 (+ phone), v3 (- phone, + topic); the current one renamed message to details.
	$contact_fields = json_decode( $sf_c11_current['contact']['schema'], true )['fields'];
	$by_name        = [];
	foreach ( $contact_fields as $field ) {
		$by_name[ $field['name'] ] = $field;
	}
	$message           = $by_name['details'];
	$message['name']   = 'message';
	$message['label']  = 'Message';
	$phone             = $by_name['email'];
	$phone['type']     = 'tel';
	$phone['name']     = 'phone';
	$phone['label']    = 'Phone';
	$phone['required'] = false;
	$versions          = [
		'v1' => [ $by_name['name'], $by_name['email'], $message ],
		'v2' => [ $by_name['name'], $by_name['email'], $phone, $message ],
		'v3' => [ $by_name['name'], $by_name['email'], $by_name['topic'], $message ],
	];
	$schemas           = [];
	$hashes            = [
		'v1'      => hash( 'sha256', 'sf-config-11-contact-v1' ),
		'v2'      => hash( 'sha256', 'sf-config-11-contact-v2' ),
		'v3'      => hash( 'sha256', 'sf-config-11-contact-v3' ),
		'current' => $sf_c11_current['contact']['hash'],
	];
	$created           = [
		'v1' => '2025-10-01 09:00:00',
		'v2' => '2026-01-15 09:00:00',
		'v3' => '2026-05-20 09:00:00',
	];
	foreach ( $versions as $version => $fields ) {
		$snapshot           = json_decode( $sf_c11_current['contact']['schema'], true );
		$snapshot['hash']   = $hashes[ $version ];
		$snapshot['fields'] = $fields;
		$schemas[]          = [
			'schema_hash' => $hashes[ $version ],
			'form_id'     => $form_ids['contact'],
			'schema'      => json_encode( $snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ), // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Stable bytes.
			'created_gmt' => $created[ $version ],
		];
	}
	foreach ( $sf_c11_current as $name => $current ) {
		$schemas[] = [
			'schema_hash' => $current['hash'],
			'form_id'     => $form_ids[ $name ],
			'schema'      => str_replace( [ 'post:900001', 'post:900002', 'post:900003' ], [ 'post:{{post:contact}}', 'post:{{post:newsletter}}', 'post:{{post:survey}}' ], $current['schema'] ),
			'created_gmt' => '2026-05-20 09:00:00',
		];
	}

	$entries  = [];
	$values   = [];
	$topics   = [ 'sales', 'support', 'press' ];
	$ticks    = [ 'news', 'events', 'offers', 'jobs', 'partners' ];
	$statuses = [ 'new', 'read', 'new', 'read', 'read', 'spam', 'new', 'trash', 'read', 'new' ];
	for ( $n = 1; $n <= 5000; $n++ ) {
		if ( $n <= 3000 ) {
			$form = 'contact';
			$step = $n;
			$hash = $n <= 600 ? $hashes['v1'] : ( $n <= 1300 ? $hashes['v2'] : ( $n <= 2000 ? $hashes['v3'] : $hashes['current'] ) );
		} elseif ( $n <= 4200 ) {
			$form = 'newsletter';
			$step = $n - 3000;
			$hash = $sf_c11_current['newsletter']['hash'];
		} else {
			$form = 'survey';
			$step = $n - 4200;
			$hash = $sf_c11_current['survey']['hash'];
		}
		$when      = gmdate( 'Y-m-d H:i:s', $sf_c11_now - ( 5000 - $n ) * $sf_c11_step );
		$label     = sprintf( '%04d', $n );
		$entries[] = [
			'id'          => $n,
			'form_id'     => $form_ids[ $form ],
			'schema_hash' => $hash,
			'source'      => 'post:{{post:' . $form . '}}',
			'status'      => $statuses[ $n % 10 ],
			'starred'     => 0 === $n % 9 ? 1 : 0,
			'user_id'     => 0,
			'created_gmt' => $when,
			'updated_gmt' => $when,
		];
		if ( 'contact' === $form ) {
			$old      = $n <= 2000;
			$values[] = [
				'entry_id' => $n,
				'field'    => 'name',
				'idx'      => 0,
				'value'    => 'Person ' . $label,
			];
			$values[] = [
				'entry_id' => $n,
				'field'    => 'email',
				'idx'      => 0,
				'value'    => 'person' . $label . '@example.com',
			];
			if ( $n > 600 && $n <= 1300 ) {
				$values[] = [
					'entry_id' => $n,
					'field'    => 'phone',
					'idx'      => 0,
					'value'    => '+44 20 7946 ' . sprintf( '%04d', $n ),
				];
			}
			if ( $n > 1300 ) {
				$values[] = [
					'entry_id' => $n,
					'field'    => 'topic',
					'idx'      => 0,
					'value'    => $topics[ $n % 3 ],
				];
			}
			$values[] = [
				'entry_id' => $n,
				'field'    => $old ? 'message' : 'details',
				'idx'      => 0,
				'value'    => 'Enquiry number ' . $n . '.',
			];
		} elseif ( 'newsletter' === $form ) {
			$values[] = [
				'entry_id' => $n,
				'field'    => 'email',
				'idx'      => 0,
				'value'    => 'reader' . $label . '@example.net',
			];
		} else {
			$values[] = [
				'entry_id' => $n,
				'field'    => 'name',
				'idx'      => 0,
				'value'    => 'Respondent ' . $label,
			];
			$count    = 1 + $step % 3;
			for ( $i = 0; $i < $count; $i++ ) {
				$values[] = [
					'entry_id' => $n,
					'field'    => 'interests',
					'idx'      => $i,
					'value'    => $ticks[ ( $step + $i ) % 5 ],
				];
			}
			if ( 0 === $step % 4 ) {
				$values[] = [
					'entry_id' => $n,
					'field'    => 'comment',
					'idx'      => 0,
					'value'    => 'Survey comment ' . $step . '.',
				];
			}
		}
	}

	return [
		'slug'          => '11-heavy-history',
		'represents'    => 'Busy site: three forms, 5,000 entries in every status, multi-value checkboxes and four schema snapshots for the contact form; list queries use form_status_date.',
		'guards'        => [ 'C-4.1', 'C-4.2', 'C-4.3', 'C-4.4' ],
		'site'          => [
			'locale'     => 'en_US',
			'theme'      => 'sf-test-block',
			'multisite'  => false,
			'timezone'   => 'Europe/London',
			'permalinks' => '/%postname%/',
		],
		'options'       => [
			'swiftforms_email_notification_to' => 'ops@busy-site.example',
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
				'date'      => '2025-09-30 09:00:00',
				'content'   => SF_FORM(
					[
						'formId' => $form_ids['contact'],
						'title'  => 'Contact',
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
						SF_FIELD(
							'select',
							[
								'label'             => 'Topic',
								'name'              => 'topic',
								'placeholderOption' => 'Choose a topic',
								'options'           => [
									[
										'value' => 'sales',
										'label' => 'Sales',
									],
									[
										'value' => 'support',
										'label' => 'Support',
									],
									[
										'value' => 'press',
										'label' => 'Press',
									],
								],
							]
						),
						SF_FIELD(
							'textarea',
							[
								'label'    => 'Details',
								'name'     => 'details',
								'required' => true,
							]
						),
					],
					'Send',
					'Thank you. We will reply soon.'
				),
			],
			[
				'key'       => 'newsletter',
				'post_type' => 'page',
				'status'    => 'publish',
				'title'     => 'Newsletter',
				'slug'      => 'newsletter',
				'date'      => '2025-09-30 10:00:00',
				'content'   => SF_FORM(
					[
						'formId' => $form_ids['newsletter'],
						'title'  => 'Newsletter',
					],
					[
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
					'Subscribe',
					'You are on the list.'
				),
			],
			[
				'key'       => 'survey',
				'post_type' => 'page',
				'status'    => 'publish',
				'title'     => 'Survey',
				'slug'      => 'survey',
				'date'      => '2025-09-30 11:00:00',
				'content'   => SF_FORM(
					[
						'formId' => $form_ids['survey'],
						'title'  => 'Reader survey',
					],
					[
						SF_FIELD(
							'text',
							[
								'label'    => 'Name',
								'name'     => 'name',
								'required' => true,
							]
						),
						SF_FIELD(
							'checkboxes',
							[
								'label'      => 'What would you like to hear about?',
								'name'       => 'interests',
								'required'   => true,
								'minChoices' => 1,
								'maxChoices' => 3,
								'options'    => [
									[
										'value' => 'news',
										'label' => 'News',
									],
									[
										'value' => 'events',
										'label' => 'Events',
									],
									[
										'value' => 'offers',
										'label' => 'Offers',
									],
									[
										'value' => 'jobs',
										'label' => 'Jobs',
									],
									[
										'value' => 'partners',
										'label' => 'Partners',
									],
								],
							]
						),
						SF_FIELD(
							'textarea',
							[
								'label' => 'Comment',
								'name'  => 'comment',
							]
						),
					],
					'Send answers',
					'Thanks for your answers.'
				),
			],
		],
		'tables'        => [
			'swiftforms_schemas'      => $schemas,
			'swiftforms_entries'      => $entries,
			'swiftforms_entry_values' => $values,
		],
		'users'         => [ 'admin' ],
		'submissions'   => [
			'contact#1'    => [
				'valid'   => [ 'c11-contact' ],
				'invalid' => [ 'c11-contact-bad' ],
			],
			'newsletter#1' => [
				'valid'   => [ 'c11-news' ],
				'invalid' => [ 'c11-news-bad' ],
			],
			'survey#1'     => [
				'valid'   => [ 'c11-survey' ],
				'invalid' => [ 'c11-survey-bad' ],
			],
		],
		'privacy_email' => 'person0042@example.com',
		'checks'        => [ 'heavy_history' ],
		'skip'          => [
			'S14' => 'pending: R02',
			'S15' => 'pending: R02',
			'S16' => 'pending: R02',
		],
	];
} )();
