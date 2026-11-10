<?php // phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- Included by the loader: the variables are local to the include, and the snippet's names are test fixtures.
/**
 * User config 13: non-English site.
 *
 * Raw stored data of a site, written exactly as 1.0.0 stores it. Frozen at the v1.0.0 tag.
 *
 * A French shop in India: locale fr_FR (the fixture .mo for the "swiftforms" text domain is loaded by mu/13-french.php),
 * timezone Asia/Kolkata (UTC+05:30), date format d/m/Y and time format H:i. French labels and messages are stored
 * block content. The entries are stored in UTC; three of them sit around local midnight so a date shown in the site
 * timezone falls on a different day than the stored UTC date.
 *
 * @package SwiftForms
 */

$sf_c13_hash   = '23504079905a698ecf81e28d5bb37b0e1e31c63211f9c43fdc68ac7201e044ec';
$sf_c13_values = [
	[ 1, 'Camille Durand', 'camille@example.fr', '2026-12-05', 'matin', '1', 'Livraison avant Noël, merci.' ],
	[ 2, 'Étienne Müller', 'etienne@example.fr', '2026-11-25', 'apres-midi', '1', 'Sonner deux fois.' ],
	[ 3, 'Zoé Lefèvre', 'zoe@example.fr', '2027-01-15', 'matin', '1', '' ],
];
$sf_c13_when   = [ '2026-11-18 20:30:00', '2026-11-17 18:29:59', '2026-11-18 18:30:00' ];

$sf_c13_entries = [];
$sf_c13_rows    = [];
foreach ( $sf_c13_values as $i => $row ) {
	$sf_c13_entries[] = [
		'id'          => $row[0],
		'form_id'     => '5f130001-0000-4000-8000-000000000001',
		'schema_hash' => $sf_c13_hash,
		'source'      => 'post:{{post:livraison}}',
		'status'      => 1 === $row[0] ? 'new' : 'read',
		'starred'     => 0,
		'user_id'     => 0,
		'created_gmt' => $sf_c13_when[ $i ],
		'updated_gmt' => $sf_c13_when[ $i ],
	];
	foreach ( [ 'nom', 'email', 'date_livraison', 'creneau', 'consentement', 'message' ] as $n => $field ) {
		if ( '' === $row[ $n + 1 ] ) {
			continue; // An empty optional value stores no row.
		}
		$sf_c13_rows[] = [
			'entry_id' => $row[0],
			'field'    => $field,
			'idx'      => 0,
			'value'    => $row[ $n + 1 ],
		];
	}
}

return [
	'slug'          => '13-localised-site',
	'represents'    => 'Non-English site: fr_FR with a .mo fixture, Asia/Kolkata, d/m/Y, French labels and messages, a date field with min and max; dates stored in UTC.',
	'guards'        => [ 'i18n', 'C-4.2' ],
	'site'          => [
		'locale'      => 'fr_FR',
		'theme'       => 'sf-test-block',
		'multisite'   => false,
		'timezone'    => 'Asia/Kolkata',
		'date_format' => 'd/m/Y',
		'time_format' => 'H:i',
		'permalinks'  => '/%postname%/',
	],
	'options'       => [
		'swiftforms_email_from_name'       => 'Boutique Soleil',
		'swiftforms_email_notification_to' => 'commandes@boutique-soleil.example',
		'swiftforms_db_version'            => 1,
		'swiftforms_installed'             => [
			'version' => '1.0.0',
			'time'    => 1795082400,
		],
	],
	'posts'         => [
		[
			'key'       => 'livraison',
			'post_type' => 'page',
			'status'    => 'publish',
			'title'     => 'Demande de livraison',
			'slug'      => 'livraison',
			'date'      => '2026-10-01 09:00:00',
			'content'   => SF_HEADING( 'Demande de livraison' ) . "\n\n" . SF_FORM(
				[
					'formId'        => '5f130001-0000-4000-8000-000000000001',
					'title'         => 'Demande de livraison',
					'notifications' => [
						[
							'enabled' => true,
							'to'      => 'logistique@boutique-soleil.example',
							'cc'      => '',
							'bcc'     => '',
							'subject' => 'Livraison demandée par {field:nom} le {entry:date}',
							'replyTo' => '{field:email}',
							'body'    => "Date souhaitée : {field:date_livraison}\nCréneau : {field:creneau}\n\n{field:message}",
							'format'  => 'plain',
						],
					],
				],
				[
					SF_FIELD(
						'text',
						[
							'label'        => 'Nom et prénom',
							'name'         => 'nom',
							'required'     => true,
							'autocomplete' => 'name',
						]
					),
					SF_FIELD(
						'email',
						[
							'label'        => 'Adresse e-mail',
							'name'         => 'email',
							'required'     => true,
							'autocomplete' => 'email',
						]
					),
					SF_FIELD(
						'date',
						[
							'label'    => 'Date de livraison souhaitée',
							'name'     => 'date_livraison',
							'required' => true,
							'help'     => 'Entre le 20 novembre 2026 et le 31 mars 2027.',
							'min'      => '2026-11-20',
							'max'      => '2027-03-31',
						]
					),
					SF_FIELD(
						'select',
						[
							'label'             => 'Créneau',
							'name'              => 'creneau',
							'required'          => true,
							'placeholderOption' => 'Choisissez un créneau',
							'options'           => [
								[
									'value' => 'matin',
									'label' => 'Matin (8 h – 12 h)',
								],
								[
									'value' => 'apres-midi',
									'label' => 'Après-midi (14 h – 18 h)',
								],
							],
						]
					),
					SF_FIELD(
						'consent',
						[
							'label' => 'Consentement',
							'name'  => 'consentement',
							'text'  => 'J’accepte que mes données servent à organiser la livraison.',
						]
					),
					SF_FIELD(
						'textarea',
						[
							'label' => 'Précisions',
							'name'  => 'message',
						]
					),
				],
				'Demander la livraison',
				'Merci ! Nous confirmons la date par e-mail.'
			),
		],
		[
			'key'       => 'lettre',
			'post_type' => 'page',
			'status'    => 'publish',
			'title'     => 'Lettre d’information',
			'slug'      => 'lettre',
			'date'      => '2026-10-02 09:00:00',
			'content'   => SF_FORM(
				[
					'formId' => '5f130002-0000-4000-8000-000000000002',
					'title'  => 'Lettre d’information',
				],
				[
					SF_FIELD(
						'email',
						[
							'label'    => 'Votre e-mail',
							'name'     => 'email',
							'required' => true,
						]
					),
				],
				'',
				null
			),
		],
	],
	'tables'        => [
		'swiftforms_schemas'      => [
			[
				'schema_hash' => $sf_c13_hash,
				'form_id'     => '5f130001-0000-4000-8000-000000000001',
				'schema'      => <<<'JSON'
{"v":1,"hash":"23504079905a698ecf81e28d5bb37b0e1e31c63211f9c43fdc68ac7201e044ec","form_id":"5f130001-0000-4000-8000-000000000001","stored_id":"5f130001-0000-4000-8000-000000000001","source":"post:{{post:livraison}}","title":"Demande de livraison","fields":[{"type":"text","name":"nom","label":"Nom et prénom","help":"","placeholder":"","required":true,"defaultValue":"","width":"100","autocomplete":"name","minLength":0,"maxLength":0,"pattern":"","patternMessage":""},{"type":"email","name":"email","label":"Adresse e-mail","help":"","placeholder":"","required":true,"defaultValue":"","width":"100","autocomplete":"email"},{"type":"date","name":"date_livraison","label":"Date de livraison souhaitée","help":"Entre le 20 novembre 2026 et le 31 mars 2027.","placeholder":"","required":true,"defaultValue":"","width":"100","autocomplete":"","min":"2026-11-20","max":"2027-03-31"},{"type":"select","name":"creneau","label":"Créneau","help":"","placeholder":"","required":true,"defaultValue":"","width":"100","autocomplete":"","options":[{"value":"matin","label":"Matin (8 h – 12 h)"},{"value":"apres-midi","label":"Après-midi (14 h – 18 h)"}],"multiple":false,"placeholderOption":"Choisissez un créneau"},{"type":"consent","name":"consentement","label":"Consentement","help":"","placeholder":"","required":true,"defaultValue":"","width":"100","autocomplete":"","text":"J’accepte que mes données servent à organiser la livraison."},{"type":"textarea","name":"message","label":"Précisions","help":"","placeholder":"","required":false,"defaultValue":"","width":"100","autocomplete":"","rows":4,"maxLength":0}]}
JSON
				,
				'created_gmt' => '2026-10-01 09:00:00',
			],
		],
		'swiftforms_entries'      => $sf_c13_entries,
		'swiftforms_entry_values' => $sf_c13_rows,
	],
	'users'         => [ 'admin' ],
	'mu_plugins'    => [ '13-french.php' ],
	'submissions'   => [
		'livraison#1' => [
			'valid'   => [ 'c13-camille' ],
			'invalid' => [ 'c13-date-fr', 'c13-date-out' ],
		],
		'lettre#1'    => [
			'valid'   => [ 'c13-lettre' ],
			'invalid' => [ 'c13-lettre-bad' ],
		],
	],
	'privacy_email' => 'camille@example.fr',
	'skip'          => [
		'S14' => 'pending: R02',
		'S15' => 'pending: R02',
		'S16' => 'pending: R02',
	],
];
