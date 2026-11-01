<?php
/**
 * Harness self-test (not one of the 20). Deleted when config 01 lands.
 *
 * It proves the machinery: raw writes, byte-identical post content, the
 * snapshot compare, and that a changed fixture turns the suite red.
 *
 * @package SwiftForms
 */

return [
	'slug'       => '00-harness-selftest',
	'represents' => 'Harness self-test (not one of the 20; deleted when config 01 lands).',
	'guards'     => [],
	'site'       => [
		'locale'     => 'en_US',
		'theme'      => 'sf-test-block',
		'multisite'  => false,
		'timezone'   => 'Europe/London',
		'permalinks' => '/%postname%/',
	],
	'options'    => [
		'swiftforms_installed' => [
			'version' => '1.0.0',
			'time'    => 1795082400,
		],
		'swiftforms_selftest'  => [
			'marker' => 'selftest-v1',
			'list'   => [ 1, 2, 3 ],
		],
	],
	'posts'      => [
		[
			'key'       => 'selftest',
			'post_type' => 'page',
			'status'    => 'publish',
			'title'     => 'Self-test',
			'slug'      => 'selftest',
			'content'   => SF_FX( 'selftest-paragraph' ),
		],
	],
	'users'      => [ 'admin' ],
	'skip'       => [
		'S2'  => 'pending: R01',
		'S3'  => 'pending: R01',
		'S4'  => 'pending: R01',
		'S5'  => 'pending: R01',
		'S6'  => 'pending: R01',
		'S8'  => 'pending: R01',
		'S9'  => 'pending: R01',
		'S11' => 'pending: R01',
		'S7'  => 'pending: R02',
		'S14' => 'pending: R02',
		'S15' => 'pending: R02',
		'S16' => 'pending: R02',
	],
];
