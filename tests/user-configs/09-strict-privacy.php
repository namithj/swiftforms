<?php
/**
 * User config 09: strict privacy.
 *
 * Raw stored data of a site, written exactly as 1.0.0 stores it. Frozen at the v1.0.0 tag.
 *
 * Forty entries, one per day at 10:00 UTC from D-40 to D-1 (D = 2026-11-19), inserted with explicit IDs 1 to 40
 * (ID 1 is D-40 and ID 40 is D-1). With retention_days 30 the daily job deletes
 * exactly the entries created strictly before D-30 10:00 UTC: D-40 to D-31, which are IDs 1 to 10.
 *
 * @package SwiftForms
 */

return ( static function () {
	$now     = 1795082400;
	$entries = [];
	$values  = [];
	for ( $n = 1; $n <= 40; $n++ ) {
		$days      = 41 - $n;
		$created   = gmdate( 'Y-m-d H:i:s', $now - $days * 86400 );
		$entries[] = [
			'id'          => $n,
			'form_id'     => '5f090001-0000-4000-8000-000000000001',
			'schema_hash' => 'abf0e848d04bb8ee73c519aaf36067014ef0a906a3b3f476a56b3d4e50ec4dc9',
			'source'      => 'post:{{post:contact}}',
			'status'      => 1 === $n % 7 ? 'read' : 'new',
			'starred'     => 0,
			'user_id'     => 0,
			'created_gmt' => $created,
			'updated_gmt' => $created,
		];
		$label     = sprintf( '%02d', $n );
		$values[]  = [
			'entry_id' => $n,
			'field'    => 'name',
			'idx'      => 0,
			'value'    => 'Person ' . $label,
		];
		$values[]  = [
			'entry_id' => $n,
			'field'    => 'email',
			'idx'      => 0,
			'value'    => 'person' . $label . '@eu-example.org',
		];
		$values[]  = [
			'entry_id' => $n,
			'field'    => 'message',
			'idx'      => 0,
			'value'    => 'Request number ' . $n . '.',
		];
		$values[]  = [
			'entry_id' => $n,
			'field'    => 'gdpr',
			'idx'      => 0,
			'value'    => '1',
		];
	}

	return [
		'slug'          => '09-strict-privacy',
		'represents'    => 'EU site: no IP address or user agent stored, 30-day retention, a required consent field, forty entries from the last forty days.',
		'guards'        => [ 'C-4.5', 'C-9' ],
		'site'          => [
			'locale'     => 'en_US',
			'theme'      => 'sf-test-block',
			'multisite'  => false,
			'timezone'   => 'Europe/London',
			'permalinks' => '/%postname%/',
		],
		'options'       => [
			'swiftforms_general_store_ip'         => '0',
			'swiftforms_general_store_user_agent' => '0',
			'swiftforms_general_retention_days'   => 30,
			'swiftforms_email_notification_to'    => 'dpo@eu-example.org',
			'swiftforms_db_version'               => 1,
			'swiftforms_installed'                => [
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
				'content'   => SF_FX( 'page-eu-contact' ),
			],
		],
		'tables'        => [
			'swiftforms_schemas'      => [
				[
					'schema_hash' => 'abf0e848d04bb8ee73c519aaf36067014ef0a906a3b3f476a56b3d4e50ec4dc9',
					'form_id'     => '5f090001-0000-4000-8000-000000000001',
					'schema'      => '{"v":1,"hash":"abf0e848d04bb8ee73c519aaf36067014ef0a906a3b3f476a56b3d4e50ec4dc9","form_id":"5f090001-0000-4000-8000-000000000001","stored_id":"5f090001-0000-4000-8000-000000000001","source":"post:{{post:contact}}","title":"EU contact","fields":[{"type":"text","name":"name","label":"Name","help":"","placeholder":"","required":true,"defaultValue":"","width":"100","autocomplete":"name","minLength":0,"maxLength":0,"pattern":"","patternMessage":""},{"type":"email","name":"email","label":"Email","help":"","placeholder":"","required":true,"defaultValue":"","width":"100","autocomplete":"email"},{"type":"textarea","name":"message","label":"Message","help":"","placeholder":"","required":true,"defaultValue":"","width":"100","autocomplete":"","rows":4,"maxLength":0},{"type":"consent","name":"gdpr","label":"Consent","help":"","placeholder":"","required":true,"defaultValue":"","width":"100","autocomplete":"","text":"I agree that my data is stored to answer my request."}]}',
					'created_gmt' => '2026-09-10 10:00:00',
				],
			],
			'swiftforms_entries'      => $entries,
			'swiftforms_entry_values' => $values,
		],
		'users'         => [ 'admin' ],
		'submissions'   => [
			'contact#1' => [
				'valid'   => [ 'c09-eu' ],
				'invalid' => [ 'c09-no-consent' ],
			],
		],
		'privacy_email' => 'person25@eu-example.org',
		'checks'        => [ 'retention' ],
		'skip'          => [
			'S14' => 'pending: R02',
			'S15' => 'pending: R02',
			'S16' => 'pending: R02',
		],
	];
} )();
