<?php // phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Short fixture helper SF_VALUES() used inside config files.
/**
 * Named submission payloads (valid, invalid, spammy, nasty) for the user configs.
 *
 * Payloads are field values (the "sf" object of a submission), named "cNN-..." after the config that uses them.
 *
 * @package SwiftForms
 */

if ( ! function_exists( 'SF_VALUES' ) ) {
	/**
	 * Return a named submission payload.
	 *
	 * @param string $name Payload name, for example "selftest".
	 * @return array<string, mixed>
	 */
	function SF_VALUES( $name ) {
		static $values = null;
		if ( null === $values ) {
			$values = [
				'selftest'            => [ 'marker' => 'selftest-v1' ],

				// 01 fresh defaults (name, email, message).
				'c01-ada'             => [
					'name'    => 'Ada Lovelace',
					'email'   => 'ada@example.com',
					'message' => 'Hello, I would like a quote for a small website.',
				],
				'c01-bad-email'       => [
					'name'    => 'Ada Lovelace',
					'email'   => 'not-an-email',
					'message' => '',
				],

				// 02 minimal contact.
				'c02-ada'             => [
					'name'    => 'Ada Lovelace',
					'email'   => 'ada@example.com',
					'message' => 'Do you deliver on Saturdays?',
				],
				'c02-grace'           => [
					'name'    => 'Grace Hopper',
					'email'   => 'grace@example.org',
					'message' => 'Please call me back about my order.',
				],
				'c02-bad-email'       => [
					'name'    => '',
					'email'   => 'ada@',
					'message' => 'Missing name and a broken address.',
				],

				// 03 multi form page.
				'c03-news-ada'        => [
					'email'   => 'ada@example.com',
					'consent' => '1',
				],
				'c03-news-no-consent' => [
					'email' => 'not-an-email',
				],
				'c03-callback-grace'  => [
					'name'  => 'Grace Hopper',
					'phone' => '+44 20 7946 0000',
					'time'  => 'afternoon',
				],
				'c03-callback-bad'    => [
					'name'  => 'Grace Hopper',
					'phone' => '',
					'time'  => 'midnight',
				],
				'c03-contact-alan'    => [
					'name'    => 'Alan Turing',
					'email'   => 'alan@example.net',
					'message' => 'A question about the landing page offer.',
				],
				'c03-contact-bad'     => [
					'name'    => '',
					'email'   => '',
					'message' => '',
				],

				// 04 all field types.
				'c04-full'            => [
					'full_name'    => 'Ada Lovelace',
					'email'        => 'ada@example.com',
					'ref_code'     => 'ABC-1234',
					'phone'        => '+44 20 7946 0000',
					'website'      => 'https://example.com/ada',
					'guests'       => '4',
					'arrival'      => '2026-12-24',
					'room'         => 'double',
					'extras'       => [ 'breakfast', 'parking' ],
					'contact_pref' => 'phone',
					'interests'    => [ 'food', 'hiking' ],
					'notes'        => "Arriving late.\nVegetarian meals please.",
					'terms'        => '1',
				],
				'c04-min'             => [
					'full_name' => 'Grace Hopper',
					'email'     => 'grace@example.org',
					'guests'    => '2',
					'room'      => 'single',
					'interests' => [ 'museums' ],
					'terms'     => '1',
				],
				'c04-bad-text'        => [
					'full_name' => 'A',
					'email'     => 'ada@example.com',
					'ref_code'  => 'abc-12',
					'guests'    => '2',
					'room'      => 'single',
					'interests' => [ 'food' ],
					'terms'     => '1',
				],
				'c04-bad-numbers'     => [
					'full_name' => 'Ada Lovelace',
					'email'     => 'ada@example.com',
					'guests'    => '13',
					'room'      => 'single',
					'interests' => [ 'food' ],
					'terms'     => '1',
				],
				'c04-bad-choices'     => [
					'full_name'    => 'Ada Lovelace',
					'email'        => 'ada@example.com',
					'guests'       => '2',
					'room'         => 'penthouse',
					'extras'       => [ 'breakfast', 'pool' ],
					'contact_pref' => 'carrier-pigeon',
					'interests'    => [ 'food', 'hiking', 'museums' ],
					'terms'        => '1',
				],
				'c04-bad-misc'        => [
					'full_name' => 'Ada Lovelace',
					'email'     => 'ada@example.com',
					'website'   => 'not a url',
					'guests'    => 'many',
					'arrival'   => '2030-01-01',
					'room'      => 'suite',
					'interests' => [],
					'notes'     => str_repeat( 'x', 501 ),
				],

				// 05 nasty content.
				'c05-nasty-a'         => [
					'name'    => "Zo\u{eb} \u{fc}n\u{ef} \u{6f22}\u{5b57} \u{1f680} </textarea><script>alert('v')</script>",
					'email'   => 'zoe@example.com',
					'note'    => "javascript:alert(1) \u{202e}override",
					'website' => 'https://example.com/?a=1&b=2#frag',
					'topic'   => 'a,b',
					'plan'    => 'pro',
					'tags'    => [ 'a,b,c', 'p|q|r', "it's" ],
					'message' => "Line one &amp; <b>two</b> \"quoted\"\n\u{645}\u{631}\u{62d}\u{628}\u{627} \u{628}\u{627}\u{644}\u{639}\u{627}\u{644}\u{645}\n[gallery ids=\"1\"] {field:x}",
				],
				'c05-nasty-b'         => [
					'name'    => '"><svg onload=alert(1)>',
					'email'   => 'mallory@example.com',
					'note'    => '{user:email}',
					'topic'   => 'x|y',
					'plan'    => 'team',
					'message' => '{all_fields} {field:other} {user:email} {form:title} {entry:id}',
				],
				'c05-javascript-url'  => [
					'name'    => 'Mallory',
					'email'   => 'mallory@example.com',
					'website' => 'javascript:alert(1)',
					'topic'   => 'tom<b>',
					'message' => 'A script URL in a web address field.',
				],

				// 06 agency lead routing.
				'c06-lead-a'          => [
					'name'       => 'Ada Lovelace',
					'email'      => 'ada@bigco.example',
					'company'    => 'BigCo',
					'department' => 'sales',
					'message'    => "We need a new site.\nBudget is flexible.",
				],
				'c06-lead-b'          => [
					'name'       => 'Ada Lovelace',
					'email'      => 'ada@bigco.example',
					'company'    => 'BigCo',
					'department' => 'support',
					'message'    => 'A second enquiry from the same address.',
				],
				'c06-bad-department'  => [
					'name'       => 'Ada Lovelace',
					'email'      => 'ada@bigco.example',
					'company'    => 'BigCo',
					'department' => 'marketing',
					'message'    => '',
				],
				'c06-spam-casino'     => [
					'name'       => 'Bot',
					'email'      => 'bot@spam.example',
					'company'    => 'Casino Royale',
					'department' => 'sales',
					'message'    => 'Best online CASINO bonus here.',
				],

				// 07 plain email, custom from.
				'c07-ada'             => [
					'name'    => 'Ada Lovelace',
					'email'   => 'ada@example.com',
					'message' => "Do you stock vinyl?\nI am after a first pressing.",
				],
				'c07-bad'             => [
					'name'    => 'Ada Lovelace',
					'email'   => 'ada@example',
					'message' => '',
				],

				// 08 redirect confirmations.
				'c08-ada'             => [
					'name'  => 'Ada Lovelace',
					'email' => 'ada+funnel@example.com',
				],
				'c08-ada-only-email'  => [
					'email' => 'ada+funnel@example.com',
				],
				'c08-bad'             => [
					'name'  => '',
					'email' => 'x',
				],

				// 09 strict privacy.
				'c09-eu'              => [
					'name'    => 'Lena Schmidt',
					'email'   => 'lena@eu-example.org',
					'message' => 'Please send me my data sheet.',
					'gdpr'    => '1',
				],
				'c09-no-consent'      => [
					'name'    => 'Lena Schmidt',
					'email'   => 'lena@eu-example.org',
					'message' => 'No consent ticked.',
					'gdpr'    => '',
				],

				// 10 email only, no storage.
				'c10-ada'             => [
					'name'    => 'Ada Lovelace',
					'email'   => 'ada@example.com',
					'message' => 'Please reply by email only.',
				],
				'c10-bad'             => [
					'name'  => '',
					'email' => 'nope',
				],
				// 12 spam hardened.
				'c12-ada'             => [
					'name'    => 'Ada Lovelace',
					'email'   => 'ada@example.com',
					'message' => 'I would like to ask about your opening hours.',
				],
				'c12-bad'             => [
					'name'    => 'Ada Lovelace',
					'email'   => 'ada@',
					'message' => '',
				],
				'c12-spam-seo'        => [
					'name'    => 'Rank Fast',
					'email'   => 'rank@spam.example',
					'message' => 'Cheap SEO Services for your site, first page guaranteed.',
				],
				'c12-spam-money'      => [
					'name'    => 'Rich Quick',
					'email'   => 'rich@spam.example',
					'message' => 'Get FREE Money today, no strings attached.',
				],
				'c12-quick'           => [
					'email'    => 'ada@example.com',
					'question' => 'Is the shop open on bank holidays?',
				],
				'c12-quick-spam'      => [
					'email'    => 'rank@spam.example',
					'question' => 'Do you want cheap seo services and a free money offer?',
				],
				'c12-quick-bad'       => [
					'email'    => 'ada',
					'question' => '',
				],
				// 13 localised site.
				'c13-camille'         => [
					'nom'            => 'Camille Durand',
					'email'          => 'camille@example.fr',
					'date_livraison' => '2026-12-05',
					'creneau'        => 'matin',
					'consentement'   => '1',
					'message'        => 'Livraison avant Noël, merci.',
				],
				'c13-date-fr'         => [
					'nom'            => 'Camille Durand',
					'email'          => 'camille@example.fr',
					'date_livraison' => '05/12/2026',
					'creneau'        => 'matin',
					'consentement'   => '1',
				],
				'c13-date-out'        => [
					'nom'            => 'Camille Durand',
					'email'          => 'camille',
					'date_livraison' => '2027-04-01',
					'creneau'        => 'soir',
					'consentement'   => '',
				],
				'c13-lettre'          => [
					'email' => 'camille@example.fr',
				],
				'c13-lettre-bad'      => [
					'email' => 'camille',
				],
				// 11 heavy history.
				'c11-contact'         => [
					'name'    => 'Ada Lovelace',
					'email'   => 'ada@example.com',
					'topic'   => 'support',
					'details' => 'A new enquiry on top of five thousand old ones.',
				],
				'c11-contact-bad'     => [
					'name'    => '',
					'email'   => 'ada',
					'topic'   => 'billing',
					'details' => '',
				],
				'c11-news'            => [
					'email' => 'reader@example.net',
				],
				'c11-news-bad'        => [
					'email' => 'reader',
				],
				'c11-survey'          => [
					'name'      => 'Grace Hopper',
					'interests' => [ 'news', 'jobs' ],
					'comment'   => 'More compilers, please.',
				],
				'c11-survey-bad'      => [
					'name'      => 'Grace Hopper',
					'interests' => [ 'news', 'events', 'offers', 'jobs' ],
					'comment'   => '',
				],
				// 14 block theme templates.
				'c14-news'            => [
					'email' => 'ada@example.com',
				],
				'c14-news-bad'        => [
					'email' => 'ada@',
				],
				'c14-feedback'        => [
					'name'     => 'Ada Lovelace',
					'feedback' => 'Loved the article on analytical engines.',
				],
				'c14-feedback-bad'    => [
					'name'     => '',
					'feedback' => '',
				],
				'c14-ask'             => [
					'email'    => 'ada@example.com',
					'question' => 'Do you cater for twenty people?',
				],
				'c14-ask-bad'         => [
					'email'    => 'ada',
					'question' => '',
				],
				'c14-table'           => [
					'name'   => 'Ada Lovelace',
					'guests' => '4',
				],
				'c14-table-bad'       => [
					'name'   => 'Ada Lovelace',
					'guests' => '40',
				],
				// 15 classic theme widgets.
				'c15-visit'           => [
					'name' => 'Ada Lovelace',
					'day'  => '2026-12-01',
				],
				'c15-visit-bad'       => [
					'name' => '',
					'day'  => '2026-01-01',
				],
				'c15-club'            => [
					'name'  => 'Ada Lovelace',
					'email' => 'ada@example.com',
				],
				'c15-club-bad'        => [
					'name'  => '',
					'email' => 'ada@',
				],
				'c15-signup'          => [
					'email' => 'ada@example.com',
				],
				'c15-signup-bad'      => [
					'email' => 'ada@',
				],
				// 16 source states.
				'c16-ada'             => [
					'name'  => 'Ada Lovelace',
					'email' => 'ada@example.com',
				],
				'c16-bad'             => [
					'name'  => '',
					'email' => 'ada@',
				],
				// 17 roles and caps.
				'c17-ada'             => [
					'name'    => 'Ada Lovelace',
					'email'   => 'ada@example.com',
					'message' => 'Can I get a demo?',
				],
				'c17-bad'             => [
					'name'    => '',
					'email'   => 'ada@',
					'message' => '',
				],
				// 18 corrupt and partial.
				'c18-ada'             => [
					'name'  => 'Ada Lovelace',
					'email' => 'ada@example.com',
				],
				'c18-bad'             => [
					'name'  => '',
					'email' => 'ada@',
				],
				'c18-ada-message'     => [
					'name'    => 'Ada Lovelace',
					'email'   => 'ada@example.com',
					'message' => 'Which of the two pages did you mean?',
				],
				'c18-ada-dupes'       => [
					'name'    => 'Ada Lovelace',
					'email'   => 'ada@example.com',
					'email-2' => 'ada@example.com',
					'message' => 'Two email fields share one name.',
				],
				'c18-ada-future'      => [
					'name'   => 'Ada Lovelace',
					'email'  => 'ada@example.com',
					'rating' => '5',
				],
				// 19 multisite.
				'c19-ada'             => [
					'name'  => 'Ada Lovelace',
					'email' => 'ada@example.com',
				],
				'c19-bad'             => [
					'name'  => '',
					'email' => 'ada@',
				],
				// 20 power user.
				'c20-basic'           => [
					'name'    => 'Ada Lovelace',
					'email'   => 'ada@example.com',
					'message' => 'Hello from the power site.',
				],
				'c20-basic-bad'       => [
					'name'    => '',
					'email'   => 'ada@',
					'message' => '',
				],
				'c20-code'            => [
					'name'    => 'Ada Lovelace',
					'email'   => 'ada@example.com',
					'code'    => 'WELCOME10',
					'message' => 'A promo code the hook accepts.',
				],
				'c20-code-rejected'   => [
					'name'    => 'Ada Lovelace',
					'email'   => 'ada@example.com',
					'code'    => 'forbidden-by-hook',
					'message' => 'A promo code the hook refuses.',
				],
				'c20-hook-spam'       => [
					'name'    => 'Hook Bot',
					'email'   => 'bot@example.com',
					'code'    => '',
					'message' => 'This mentions hook-spam on purpose.',
				],
				'c20-visit'           => [
					'name'    => 'Ada Lovelace',
					'email'   => 'ada@example.com',
					'visit'   => '2026-12-12',
					'message' => 'A visit in December.',
				],
				'c20-plan'            => [
					'name'    => 'Ada Lovelace',
					'email'   => 'ada@example.com',
					'plan'    => 'pro',
					'message' => 'The pro plan, please.',
				],
				'c20-topics'          => [
					'name'    => 'Ada Lovelace',
					'email'   => 'ada@example.com',
					'topics'  => [ 'wp', 'php' ],
					'message' => 'Two topics.',
				],
			];
		}
		if ( ! array_key_exists( $name, $values ) ) {
			throw new InvalidArgumentException( 'Unknown values fixture: ' . $name ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing message.
		}
		return $values[ $name ];
	}
}
