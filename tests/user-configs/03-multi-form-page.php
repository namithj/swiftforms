<?php
/**
 * User config 03: landing page with several forms.
 *
 * Raw stored data of a site, written exactly as 1.0.0 stores it. Frozen at the v1.0.0 tag.
 *
 * @package SwiftForms
 */

return [
	'slug'          => '03-multi-form-page',
	'represents'    => 'Landing page with three different forms (newsletter, callback, contact); the newsletter is a synced pattern shown twice on the page and on two other pages.',
	'guards'        => [ 'C-2.7', 'C-7.3', 'ADR-001' ],
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
			'key'       => 'newsletter-pattern',
			'post_type' => 'wp_block',
			'status'    => 'publish',
			'title'     => 'Newsletter signup',
			'slug'      => 'newsletter-signup',
			'content'   => SF_FX( 'form-newsletter' ),
		],
		[
			'key'       => 'landing',
			'post_type' => 'page',
			'status'    => 'publish',
			'title'     => 'Landing',
			'slug'      => 'landing',
			'content'   => SF_FX( 'page-landing' ),
		],
		[
			'key'       => 'news-a',
			'post_type' => 'page',
			'status'    => 'publish',
			'title'     => 'News A',
			'slug'      => 'news-a',
			'content'   => SF_FX( 'page-pattern-embed' ),
		],
		[
			'key'       => 'news-b',
			'post_type' => 'page',
			'status'    => 'publish',
			'title'     => 'News B',
			'slug'      => 'news-b',
			'content'   => SF_FX( 'page-pattern-embed' ),
		],
	],
	'users'         => [ 'admin' ],
	'submissions'   => [
		'newsletter-pattern#1' => [
			'valid'   => [ 'c03-news-ada' ],
			'invalid' => [ 'c03-news-no-consent' ],
		],
		'landing#1'            => [
			'valid'   => [ 'c03-callback-grace' ],
			'invalid' => [ 'c03-callback-bad' ],
		],
		'landing#2'            => [
			'valid'   => [ 'c03-contact-alan' ],
			'invalid' => [ 'c03-contact-bad' ],
		],
	],
	'privacy_email' => 'ada@example.com',
	'checks'        => [ 'resave' ],
	'skip'          => [
		'S14' => 'pending: R02',
		'S15' => 'pending: R02',
		'S16' => 'pending: R02',
	],
];
