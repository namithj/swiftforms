<?php // phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Short fixture helper SF_FX() used inside config files.
/**
 * Named block-markup fragments for the user configs.
 *
 * Each fragment is a nowdoc, so the bytes in the database are exactly the bytes
 * written here. Never edit a fragment that a frozen config uses: add a new one.
 *
 * Block markup follows what the editor serialises: attributes only when they differ from the default,
 * in the order the block registers them, self-closing field blocks, the formId first, "<", ">", "&" and
 * quotes inside attribute JSON escaped as the editor does (unicode escapes for the angle brackets, the ampersand and the quote), and fields joined by one blank line.
 * "form-default" is the markup the editor produced for a freshly inserted form (recorded by tests/e2e/editor.spec.js),
 * with a fixed formId. "{{post:key}}" is replaced by the loader with the ID of the post of that key.
 *
 * @package SwiftForms
 */

if ( ! function_exists( 'SF_FX' ) ) {
	/**
	 * Return the exact bytes of a named block-markup fragment.
	 *
	 * @param string $name Fragment name, for example "form-default".
	 * @return string
	 */
	function SF_FX( $name ) {
		static $fragments = null;
		if ( null === $fragments ) {
			$fragments = [
				// Harness self-test (R00): a core block, no SwiftForms markup.
				'selftest-paragraph'   => <<<'HTML'
<!-- wp:paragraph -->
<p>SwiftForms harness self-test.</p>
<!-- /wp:paragraph -->
HTML
				,
				// form-default.
				'form-default'         => <<<'HTML'
<!-- wp:swiftforms/form {"formId":"5f010001-0000-4000-8000-000000000001"} -->
<!-- wp:swiftforms/field-text {"label":"Name","name":"name","autocomplete":"name"} /-->

<!-- wp:swiftforms/field-email {"label":"Email","name":"email","required":true,"autocomplete":"email"} /-->

<!-- wp:swiftforms/field-textarea {"label":"Message","name":"message","required":true} /-->

<!-- wp:swiftforms/submit /-->

<!-- wp:swiftforms/confirmation -->
<!-- wp:paragraph -->
<p>Thanks! We'll be in touch.</p>
<!-- /wp:paragraph -->
<!-- /wp:swiftforms/confirmation -->
<!-- /wp:swiftforms/form -->
HTML
				,
				// form-minimal-contact.
				'form-minimal-contact' => <<<'HTML'
<!-- wp:swiftforms/form {"formId":"5f020001-0000-4000-8000-000000000001","title":"Contact us"} -->
<!-- wp:swiftforms/field-text {"label":"Your name","name":"name","required":true,"autocomplete":"name"} /-->

<!-- wp:swiftforms/field-email {"label":"Email","name":"email","required":true,"autocomplete":"email"} /-->

<!-- wp:swiftforms/field-textarea {"label":"How can we help?","name":"message","required":true} /-->

<!-- wp:swiftforms/submit {"label":"Send message"} /-->

<!-- wp:swiftforms/confirmation -->
<!-- wp:paragraph -->
<p>Thank you. We reply within one working day.</p>
<!-- /wp:paragraph -->
<!-- /wp:swiftforms/confirmation -->
<!-- /wp:swiftforms/form -->
HTML
				,
				// page-minimal-contact.
				'page-minimal-contact' => <<<'HTML'
<!-- wp:paragraph -->
<p>Get in touch with us below.</p>
<!-- /wp:paragraph -->

<!-- wp:swiftforms/form {"formId":"5f020001-0000-4000-8000-000000000001","title":"Contact us"} -->
<!-- wp:swiftforms/field-text {"label":"Your name","name":"name","required":true,"autocomplete":"name"} /-->

<!-- wp:swiftforms/field-email {"label":"Email","name":"email","required":true,"autocomplete":"email"} /-->

<!-- wp:swiftforms/field-textarea {"label":"How can we help?","name":"message","required":true} /-->

<!-- wp:swiftforms/submit {"label":"Send message"} /-->

<!-- wp:swiftforms/confirmation -->
<!-- wp:paragraph -->
<p>Thank you. We reply within one working day.</p>
<!-- /wp:paragraph -->
<!-- /wp:swiftforms/confirmation -->
<!-- /wp:swiftforms/form -->
HTML
				,
				// form-newsletter.
				'form-newsletter'      => <<<'HTML'
<!-- wp:swiftforms/form {"formId":"5f030001-0000-4000-8000-000000000001","title":"Newsletter"} -->
<!-- wp:swiftforms/field-email {"label":"Email","name":"email","required":true,"placeholder":"you@example.com","autocomplete":"email"} /-->

<!-- wp:swiftforms/field-consent {"label":"Consent","name":"consent","text":"Send me the monthly newsletter."} /-->

<!-- wp:swiftforms/submit {"label":"Subscribe"} /-->

<!-- wp:swiftforms/confirmation -->
<!-- wp:paragraph -->
<p>You are subscribed. Thank you!</p>
<!-- /wp:paragraph -->
<!-- /wp:swiftforms/confirmation -->
<!-- /wp:swiftforms/form -->
HTML
				,
				// form-callback.
				'form-callback'        => <<<'HTML'
<!-- wp:swiftforms/form {"formId":"5f030002-0000-4000-8000-000000000002","title":"Request a callback"} -->
<!-- wp:swiftforms/field-text {"label":"Name","name":"name","required":true,"autocomplete":"name"} /-->

<!-- wp:swiftforms/field-tel {"label":"Phone","name":"phone","required":true,"autocomplete":"tel"} /-->

<!-- wp:swiftforms/field-select {"label":"Best time to call","name":"time","placeholderOption":"Choose a time","options":[{"value":"morning","label":"Morning"},{"value":"afternoon","label":"Afternoon"},{"value":"evening","label":"Evening"}]} /-->

<!-- wp:swiftforms/submit {"label":"Call me"} /-->

<!-- wp:swiftforms/confirmation -->
<!-- wp:paragraph -->
<p>We will call you back soon.</p>
<!-- /wp:paragraph -->
<!-- /wp:swiftforms/confirmation -->
<!-- /wp:swiftforms/form -->
HTML
				,
				// form-contact-landing.
				'form-contact-landing' => <<<'HTML'
<!-- wp:swiftforms/form {"formId":"5f030003-0000-4000-8000-000000000003","title":"Contact"} -->
<!-- wp:swiftforms/field-text {"label":"Name","name":"name","required":true,"autocomplete":"name"} /-->

<!-- wp:swiftforms/field-email {"label":"Email","name":"email","required":true,"autocomplete":"email"} /-->

<!-- wp:swiftforms/field-textarea {"label":"Message","name":"message","required":true} /-->

<!-- wp:swiftforms/submit /-->

<!-- wp:swiftforms/confirmation -->
<!-- wp:paragraph -->
<p>Thanks for your message.</p>
<!-- /wp:paragraph -->
<!-- /wp:swiftforms/confirmation -->
<!-- /wp:swiftforms/form -->
HTML
				,
				// page-landing.
				'page-landing'         => <<<'HTML'
<!-- wp:heading -->
<h2 class="wp-block-heading">Welcome</h2>
<!-- /wp:heading -->

<!-- wp:block {"ref":{{post:newsletter-pattern}}} /-->

<!-- wp:swiftforms/form {"formId":"5f030002-0000-4000-8000-000000000002","title":"Request a callback"} -->
<!-- wp:swiftforms/field-text {"label":"Name","name":"name","required":true,"autocomplete":"name"} /-->

<!-- wp:swiftforms/field-tel {"label":"Phone","name":"phone","required":true,"autocomplete":"tel"} /-->

<!-- wp:swiftforms/field-select {"label":"Best time to call","name":"time","placeholderOption":"Choose a time","options":[{"value":"morning","label":"Morning"},{"value":"afternoon","label":"Afternoon"},{"value":"evening","label":"Evening"}]} /-->

<!-- wp:swiftforms/submit {"label":"Call me"} /-->

<!-- wp:swiftforms/confirmation -->
<!-- wp:paragraph -->
<p>We will call you back soon.</p>
<!-- /wp:paragraph -->
<!-- /wp:swiftforms/confirmation -->
<!-- /wp:swiftforms/form -->

<!-- wp:swiftforms/form {"formId":"5f030003-0000-4000-8000-000000000003","title":"Contact"} -->
<!-- wp:swiftforms/field-text {"label":"Name","name":"name","required":true,"autocomplete":"name"} /-->

<!-- wp:swiftforms/field-email {"label":"Email","name":"email","required":true,"autocomplete":"email"} /-->

<!-- wp:swiftforms/field-textarea {"label":"Message","name":"message","required":true} /-->

<!-- wp:swiftforms/submit /-->

<!-- wp:swiftforms/confirmation -->
<!-- wp:paragraph -->
<p>Thanks for your message.</p>
<!-- /wp:paragraph -->
<!-- /wp:swiftforms/confirmation -->
<!-- /wp:swiftforms/form -->

<!-- wp:paragraph -->
<p>Prefer the newsletter again?</p>
<!-- /wp:paragraph -->

<!-- wp:block {"ref":{{post:newsletter-pattern}}} /-->
HTML
				,
				// page-pattern-embed.
				'page-pattern-embed'   => <<<'HTML'
<!-- wp:paragraph -->
<p>Stay up to date.</p>
<!-- /wp:paragraph -->

<!-- wp:block {"ref":{{post:newsletter-pattern}}} /-->
HTML
				,
				// form-all-fields.
				'form-all-fields'      => <<<'HTML'
<!-- wp:swiftforms/form {"formId":"5f040001-0000-4000-8000-000000000001","title":"Kitchen sink","requiredIndicator":"text"} -->
<!-- wp:swiftforms/field-text {"label":"Full name","name":"full_name","required":true,"help":"As on your ID.","placeholder":"Ada Lovelace","minLength":2,"maxLength":60,"width":"50","autocomplete":"name"} /-->

<!-- wp:swiftforms/field-email {"label":"Email","name":"email","required":true,"help":"We never share it.","placeholder":"you@example.com","width":"50","autocomplete":"email"} /-->

<!-- wp:swiftforms/field-text {"label":"Reference","name":"ref_code","placeholder":"ABC-1234","width":"25","pattern":"[A-Z]{3}-[0-9]{4}","patternMessage":"Use the format ABC-1234."} /-->

<!-- wp:swiftforms/field-tel {"label":"Phone","name":"phone","placeholder":"+44 20 7946 0000","width":"75","autocomplete":"tel"} /-->

<!-- wp:swiftforms/field-url {"label":"Website","name":"website","placeholder":"https://","autocomplete":"url"} /-->

<!-- wp:swiftforms/field-number {"label":"Guests","name":"guests","required":true,"defaultValue":"2","min":"1","max":"12","step":"1","width":"33"} /-->

<!-- wp:swiftforms/field-date {"label":"Arrival","name":"arrival","min":"2026-11-20","max":"2027-12-31","width":"33"} /-->

<!-- wp:swiftforms/field-select {"label":"Room","name":"room","required":true,"placeholderOption":"Choose a room","options":[{"value":"single","label":"Single"},{"value":"double","label":"Double"},{"value":"suite","label":"Suite"}],"width":"33"} /-->

<!-- wp:swiftforms/field-select {"label":"Extras","name":"extras","multiple":true,"options":[{"value":"breakfast","label":"Breakfast"},{"value":"parking","label":"Parking"},{"value":"late-checkout","label":"Late checkout"}],"width":"66"} /-->

<!-- wp:swiftforms/field-radio {"label":"Contact me by","name":"contact_pref","defaultValue":"email","layout":"inline","options":[{"value":"email","label":"Email"},{"value":"phone","label":"Phone"}],"width":"66"} /-->

<!-- wp:swiftforms/field-checkboxes {"label":"Interests","name":"interests","help":"Pick one or two.","minChoices":1,"maxChoices":2,"options":[{"value":"food","label":"Food"},{"value":"hiking","label":"Hiking"},{"value":"museums","label":"Museums"},{"value":"nightlife","label":"Nightlife"}]} /-->

<!-- wp:swiftforms/field-textarea {"label":"Notes","name":"notes","help":"Anything we should know?","placeholder":"Allergies, arrival time...","maxLength":500,"rows":6} /-->

<!-- wp:swiftforms/field-consent {"label":"Terms","name":"terms","text":"I agree to the \u003ca href=\u0022https://example.org/terms\u0022\u003eterms\u003c/a\u003e."} /-->

<!-- wp:swiftforms/field-hidden {"label":"Page","name":"source_page","value":"{post:id}"} /-->

<!-- wp:swiftforms/field-hidden {"label":"User","name":"submitter","value":"{user:id}"} /-->

<!-- wp:swiftforms/submit {"label":"Book now"} /-->

<!-- wp:swiftforms/confirmation -->
<!-- wp:paragraph -->
<p>Booking request received.</p>
<!-- /wp:paragraph -->
<!-- /wp:swiftforms/confirmation -->
<!-- /wp:swiftforms/form -->
HTML
				,
				// page-all-fields.
				'page-all-fields'      => <<<'HTML'
<!-- wp:heading -->
<h2 class="wp-block-heading">Book a stay</h2>
<!-- /wp:heading -->

<!-- wp:swiftforms/form {"formId":"5f040001-0000-4000-8000-000000000001","title":"Kitchen sink","requiredIndicator":"text"} -->
<!-- wp:swiftforms/field-text {"label":"Full name","name":"full_name","required":true,"help":"As on your ID.","placeholder":"Ada Lovelace","minLength":2,"maxLength":60,"width":"50","autocomplete":"name"} /-->

<!-- wp:swiftforms/field-email {"label":"Email","name":"email","required":true,"help":"We never share it.","placeholder":"you@example.com","width":"50","autocomplete":"email"} /-->

<!-- wp:swiftforms/field-text {"label":"Reference","name":"ref_code","placeholder":"ABC-1234","width":"25","pattern":"[A-Z]{3}-[0-9]{4}","patternMessage":"Use the format ABC-1234."} /-->

<!-- wp:swiftforms/field-tel {"label":"Phone","name":"phone","placeholder":"+44 20 7946 0000","width":"75","autocomplete":"tel"} /-->

<!-- wp:swiftforms/field-url {"label":"Website","name":"website","placeholder":"https://","autocomplete":"url"} /-->

<!-- wp:swiftforms/field-number {"label":"Guests","name":"guests","required":true,"defaultValue":"2","min":"1","max":"12","step":"1","width":"33"} /-->

<!-- wp:swiftforms/field-date {"label":"Arrival","name":"arrival","min":"2026-11-20","max":"2027-12-31","width":"33"} /-->

<!-- wp:swiftforms/field-select {"label":"Room","name":"room","required":true,"placeholderOption":"Choose a room","options":[{"value":"single","label":"Single"},{"value":"double","label":"Double"},{"value":"suite","label":"Suite"}],"width":"33"} /-->

<!-- wp:swiftforms/field-select {"label":"Extras","name":"extras","multiple":true,"options":[{"value":"breakfast","label":"Breakfast"},{"value":"parking","label":"Parking"},{"value":"late-checkout","label":"Late checkout"}],"width":"66"} /-->

<!-- wp:swiftforms/field-radio {"label":"Contact me by","name":"contact_pref","defaultValue":"email","layout":"inline","options":[{"value":"email","label":"Email"},{"value":"phone","label":"Phone"}],"width":"66"} /-->

<!-- wp:swiftforms/field-checkboxes {"label":"Interests","name":"interests","help":"Pick one or two.","minChoices":1,"maxChoices":2,"options":[{"value":"food","label":"Food"},{"value":"hiking","label":"Hiking"},{"value":"museums","label":"Museums"},{"value":"nightlife","label":"Nightlife"}]} /-->

<!-- wp:swiftforms/field-textarea {"label":"Notes","name":"notes","help":"Anything we should know?","placeholder":"Allergies, arrival time...","maxLength":500,"rows":6} /-->

<!-- wp:swiftforms/field-consent {"label":"Terms","name":"terms","text":"I agree to the \u003ca href=\u0022https://example.org/terms\u0022\u003eterms\u003c/a\u003e."} /-->

<!-- wp:swiftforms/field-hidden {"label":"Page","name":"source_page","value":"{post:id}"} /-->

<!-- wp:swiftforms/field-hidden {"label":"User","name":"submitter","value":"{user:id}"} /-->

<!-- wp:swiftforms/submit {"label":"Book now"} /-->

<!-- wp:swiftforms/confirmation -->
<!-- wp:paragraph -->
<p>Booking request received.</p>
<!-- /wp:paragraph -->
<!-- /wp:swiftforms/confirmation -->
<!-- /wp:swiftforms/form -->
HTML
				,
				// form-nasty.
				'form-nasty'           => <<<'HTML'
<!-- wp:swiftforms/form {"formId":"5f050001-0000-4000-8000-000000000001","title":"Nasty \u0026 \u003cb\u003e\u0022quoted\u0022\u003c/b\u003e ünï 漢字 🚀"} -->
<!-- wp:swiftforms/field-text {"label":"Näme ünï 漢字 🚀 \u003c/textarea\u003e\u003cscript\u003ealert('l')\u003c/script\u003e","name":"name","required":true,"help":"Shortcode-looking: [contact-form-7 id=\u00221\u0022 title=\u0022x\u0022] and {field:x} and {all_fields}","placeholder":"\u0022\u003e\u003csvg onload=alert(1)\u003e","autocomplete":"name"} /-->

<!-- wp:swiftforms/field-email {"label":"E-mail — \u0022quoted\u0022 \u0026 'single' \u0026amp; \u0026lt;b\u0026gt;","name":"email","required":true,"autocomplete":"email"} /-->

<!-- wp:swiftforms/field-text {"label":"ملاحظة (RTL) ‮override","name":"note","help":"اكتب هنا — النص من اليمين إلى اليسار"} /-->

<!-- wp:swiftforms/field-url {"label":"Website","name":"website"} /-->

<!-- wp:swiftforms/field-select {"label":"Topic, with | pipes","name":"topic","required":true,"placeholderOption":"Pick, one | now","options":[{"value":"a,b","label":"Comma, separated"},{"value":"x|y","label":"Pipe | separated"},{"value":"ünï 漢字","label":"Unicode ünï 漢字 🚀"},{"value":"مرحبا","label":"مرحبا بالعالم"},{"value":"fish\u0026chips","label":"Fish \u0026amp; Chips"},{"value":"tom\u003cb\u003e","label":"Tom \u0026 \u0022Jerry\u0022 \u003cb\u003ebold\u003c/b\u003e"}]} /-->

<!-- wp:swiftforms/field-radio {"label":"الاسم الكامل","name":"plan","options":[{"value":"basic","label":"Basic [shortcode]"},{"value":"pro","label":"{field:other}"},{"value":"team","label":"\u003cscript\u003ealert(1)\u003c/script\u003e"}]} /-->

<!-- wp:swiftforms/field-checkboxes {"label":"Tags","name":"tags","options":[{"value":"a,b,c","label":"a,b,c"},{"value":"p|q|r","label":"p|q|r"},{"value":"it's","label":"It's \u0022quoted\u0022"}]} /-->

<!-- wp:swiftforms/field-textarea {"label":"Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text ","name":"message","required":true,"help":"\u003c/textarea\u003e\u003cb\u003ehelp\u003c/b\u003e"} /-->

<!-- wp:swiftforms/field-hidden {"label":"Marker","name":"marker","value":"{field:x} [gallery ids=\u00221\u0022]"} /-->

<!-- wp:swiftforms/submit {"label":"Send ünï 漢字 🚀"} /-->

<!-- wp:swiftforms/confirmation -->
<!-- wp:paragraph -->
<p>Thanks {field:name}! &lt;script&gt;alert(1)&lt;/script&gt; You wrote: <code>{field:message}</code> [gallery ids="1"] {field:x}</p>
<!-- /wp:paragraph -->
<!-- /wp:swiftforms/confirmation -->
<!-- /wp:swiftforms/form -->
HTML
				,
				// page-nasty.
				'page-nasty'           => <<<'HTML'
<!-- wp:heading -->
<h2 class="wp-block-heading">Nasty ünï 漢字 &amp; co</h2>
<!-- /wp:heading -->

<!-- wp:swiftforms/form {"formId":"5f050001-0000-4000-8000-000000000001","title":"Nasty \u0026 \u003cb\u003e\u0022quoted\u0022\u003c/b\u003e ünï 漢字 🚀"} -->
<!-- wp:swiftforms/field-text {"label":"Näme ünï 漢字 🚀 \u003c/textarea\u003e\u003cscript\u003ealert('l')\u003c/script\u003e","name":"name","required":true,"help":"Shortcode-looking: [contact-form-7 id=\u00221\u0022 title=\u0022x\u0022] and {field:x} and {all_fields}","placeholder":"\u0022\u003e\u003csvg onload=alert(1)\u003e","autocomplete":"name"} /-->

<!-- wp:swiftforms/field-email {"label":"E-mail — \u0022quoted\u0022 \u0026 'single' \u0026amp; \u0026lt;b\u0026gt;","name":"email","required":true,"autocomplete":"email"} /-->

<!-- wp:swiftforms/field-text {"label":"ملاحظة (RTL) ‮override","name":"note","help":"اكتب هنا — النص من اليمين إلى اليسار"} /-->

<!-- wp:swiftforms/field-url {"label":"Website","name":"website"} /-->

<!-- wp:swiftforms/field-select {"label":"Topic, with | pipes","name":"topic","required":true,"placeholderOption":"Pick, one | now","options":[{"value":"a,b","label":"Comma, separated"},{"value":"x|y","label":"Pipe | separated"},{"value":"ünï 漢字","label":"Unicode ünï 漢字 🚀"},{"value":"مرحبا","label":"مرحبا بالعالم"},{"value":"fish\u0026chips","label":"Fish \u0026amp; Chips"},{"value":"tom\u003cb\u003e","label":"Tom \u0026 \u0022Jerry\u0022 \u003cb\u003ebold\u003c/b\u003e"}]} /-->

<!-- wp:swiftforms/field-radio {"label":"الاسم الكامل","name":"plan","options":[{"value":"basic","label":"Basic [shortcode]"},{"value":"pro","label":"{field:other}"},{"value":"team","label":"\u003cscript\u003ealert(1)\u003c/script\u003e"}]} /-->

<!-- wp:swiftforms/field-checkboxes {"label":"Tags","name":"tags","options":[{"value":"a,b,c","label":"a,b,c"},{"value":"p|q|r","label":"p|q|r"},{"value":"it's","label":"It's \u0022quoted\u0022"}]} /-->

<!-- wp:swiftforms/field-textarea {"label":"Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text Long label text ","name":"message","required":true,"help":"\u003c/textarea\u003e\u003cb\u003ehelp\u003c/b\u003e"} /-->

<!-- wp:swiftforms/field-hidden {"label":"Marker","name":"marker","value":"{field:x} [gallery ids=\u00221\u0022]"} /-->

<!-- wp:swiftforms/submit {"label":"Send ünï 漢字 🚀"} /-->

<!-- wp:swiftforms/confirmation -->
<!-- wp:paragraph -->
<p>Thanks {field:name}! &lt;script&gt;alert(1)&lt;/script&gt; You wrote: <code>{field:message}</code> [gallery ids="1"] {field:x}</p>
<!-- /wp:paragraph -->
<!-- /wp:swiftforms/confirmation -->
<!-- /wp:swiftforms/form -->
HTML
				,
				// form-agency-lead.
				'form-agency-lead'     => <<<'HTML'
<!-- wp:swiftforms/form {"formId":"5f060001-0000-4000-8000-000000000001","title":"Agency lead","useDefaultNotification":false,"notifications":[{"enabled":true,"to":"sales@agency.example, leads@agency.example","cc":"manager@agency.example, ops@agency.example","bcc":"archive@agency.example","subject":"New lead: {field:name}","replyTo":"{field:email}","body":"{all_fields}","format":"html"},{"enabled":true,"to":"{field:email}","cc":"","bcc":"","subject":"Thanks {field:name}, we got your enquiry","replyTo":"sales@agency.example","body":"Hello {field:name},\n\nThank you for your enquiry. A member of the team will contact you within one working day.","format":"html"},{"enabled":true,"to":"{field:company}, support@agency.example","cc":"","bcc":"","subject":"Lead from {field:company}","replyTo":"","body":"Company: {field:company}\nDepartment: {field:department}\n\n{field:message}","format":"plain"}]} -->
<!-- wp:swiftforms/field-text {"label":"Name","name":"name","required":true,"autocomplete":"name"} /-->

<!-- wp:swiftforms/field-email {"label":"Work email","name":"email","required":true,"autocomplete":"email"} /-->

<!-- wp:swiftforms/field-text {"label":"Company","name":"company","autocomplete":"organization"} /-->

<!-- wp:swiftforms/field-select {"label":"Department","name":"department","required":true,"placeholderOption":"Choose a department","options":[{"value":"sales","label":"Sales"},{"value":"support","label":"Support"},{"value":"billing","label":"Billing"}]} /-->

<!-- wp:swiftforms/field-textarea {"label":"Project brief","name":"message","required":true} /-->

<!-- wp:swiftforms/submit {"label":"Request a proposal"} /-->

<!-- wp:swiftforms/confirmation -->
<!-- wp:paragraph -->
<p>Thanks. We will be in touch shortly.</p>
<!-- /wp:paragraph -->
<!-- /wp:swiftforms/confirmation -->
<!-- /wp:swiftforms/form -->
HTML
				,
				// page-agency-lead.
				'page-agency-lead'     => <<<'HTML'
<!-- wp:heading -->
<h2 class="wp-block-heading">Start a project</h2>
<!-- /wp:heading -->

<!-- wp:swiftforms/form {"formId":"5f060001-0000-4000-8000-000000000001","title":"Agency lead","useDefaultNotification":false,"notifications":[{"enabled":true,"to":"sales@agency.example, leads@agency.example","cc":"manager@agency.example, ops@agency.example","bcc":"archive@agency.example","subject":"New lead: {field:name}","replyTo":"{field:email}","body":"{all_fields}","format":"html"},{"enabled":true,"to":"{field:email}","cc":"","bcc":"","subject":"Thanks {field:name}, we got your enquiry","replyTo":"sales@agency.example","body":"Hello {field:name},\n\nThank you for your enquiry. A member of the team will contact you within one working day.","format":"html"},{"enabled":true,"to":"{field:company}, support@agency.example","cc":"","bcc":"","subject":"Lead from {field:company}","replyTo":"","body":"Company: {field:company}\nDepartment: {field:department}\n\n{field:message}","format":"plain"}]} -->
<!-- wp:swiftforms/field-text {"label":"Name","name":"name","required":true,"autocomplete":"name"} /-->

<!-- wp:swiftforms/field-email {"label":"Work email","name":"email","required":true,"autocomplete":"email"} /-->

<!-- wp:swiftforms/field-text {"label":"Company","name":"company","autocomplete":"organization"} /-->

<!-- wp:swiftforms/field-select {"label":"Department","name":"department","required":true,"placeholderOption":"Choose a department","options":[{"value":"sales","label":"Sales"},{"value":"support","label":"Support"},{"value":"billing","label":"Billing"}]} /-->

<!-- wp:swiftforms/field-textarea {"label":"Project brief","name":"message","required":true} /-->

<!-- wp:swiftforms/submit {"label":"Request a proposal"} /-->

<!-- wp:swiftforms/confirmation -->
<!-- wp:paragraph -->
<p>Thanks. We will be in touch shortly.</p>
<!-- /wp:paragraph -->
<!-- /wp:swiftforms/confirmation -->
<!-- /wp:swiftforms/form -->
HTML
				,
				// form-old-school.
				'form-old-school'      => <<<'HTML'
<!-- wp:swiftforms/form {"formId":"5f070001-0000-4000-8000-000000000001","title":"Shop enquiry","notifications":[{"enabled":true,"to":"shop@oldschool.example","cc":"","bcc":"","subject":"Enquiry from {field:name}","replyTo":"{field:email}","body":"Name: {field:name}\nEmail: {field:email}\n\n{field:message}","format":"plain"}]} -->
<!-- wp:swiftforms/field-text {"label":"Name","name":"name","required":true,"autocomplete":"name"} /-->

<!-- wp:swiftforms/field-email {"label":"Email","name":"email","required":true,"autocomplete":"email"} /-->

<!-- wp:swiftforms/field-textarea {"label":"Message","name":"message","required":true} /-->

<!-- wp:swiftforms/submit /-->

<!-- wp:swiftforms/confirmation -->
<!-- wp:paragraph -->
<p>Message sent. Thank you.</p>
<!-- /wp:paragraph -->
<!-- /wp:swiftforms/confirmation -->
<!-- /wp:swiftforms/form -->
HTML
				,
				// page-old-school.
				'page-old-school'      => <<<'HTML'
<!-- wp:paragraph -->
<p>Write to the shop.</p>
<!-- /wp:paragraph -->

<!-- wp:swiftforms/form {"formId":"5f070001-0000-4000-8000-000000000001","title":"Shop enquiry","notifications":[{"enabled":true,"to":"shop@oldschool.example","cc":"","bcc":"","subject":"Enquiry from {field:name}","replyTo":"{field:email}","body":"Name: {field:name}\nEmail: {field:email}\n\n{field:message}","format":"plain"}]} -->
<!-- wp:swiftforms/field-text {"label":"Name","name":"name","required":true,"autocomplete":"name"} /-->

<!-- wp:swiftforms/field-email {"label":"Email","name":"email","required":true,"autocomplete":"email"} /-->

<!-- wp:swiftforms/field-textarea {"label":"Message","name":"message","required":true} /-->

<!-- wp:swiftforms/submit /-->

<!-- wp:swiftforms/confirmation -->
<!-- wp:paragraph -->
<p>Message sent. Thank you.</p>
<!-- /wp:paragraph -->
<!-- /wp:swiftforms/confirmation -->
<!-- /wp:swiftforms/form -->
HTML
				,
				// page-funnel.
				'page-funnel'          => <<<'HTML'
<!-- wp:heading -->
<h2 class="wp-block-heading">Join the programme</h2>
<!-- /wp:heading -->

<!-- wp:swiftforms/form {"formId":"5f080001-0000-4000-8000-000000000001","title":"Funnel A (page)","confirmationType":"redirect","redirectPageId":{{post:thanks}}} -->
<!-- wp:swiftforms/field-text {"label":"Name","name":"name","required":true,"autocomplete":"name"} /-->

<!-- wp:swiftforms/field-email {"label":"Email","name":"email","required":true,"autocomplete":"email"} /-->

<!-- wp:swiftforms/submit {"label":"Continue"} /-->

<!-- wp:swiftforms/confirmation -->
<!-- wp:paragraph -->
<p>Funnel A: stay on this page.</p>
<!-- /wp:paragraph -->
<!-- /wp:swiftforms/confirmation -->
<!-- /wp:swiftforms/form -->

<!-- wp:swiftforms/form {"formId":"5f080002-0000-4000-8000-000000000002","title":"Funnel B (external)","confirmationType":"redirect","redirectUrl":"https://partner.example/welcome?email={field:email}\u0026src=funnel"} -->
<!-- wp:swiftforms/field-email {"label":"Email","name":"email","required":true,"autocomplete":"email"} /-->

<!-- wp:swiftforms/submit {"label":"Continue"} /-->

<!-- wp:swiftforms/confirmation -->
<!-- wp:paragraph -->
<p>Funnel B: stay on this page.</p>
<!-- /wp:paragraph -->
<!-- /wp:swiftforms/confirmation -->
<!-- /wp:swiftforms/form -->

<!-- wp:swiftforms/form {"formId":"5f080003-0000-4000-8000-000000000003","title":"Funnel C (deleted page)","confirmationType":"redirect","redirectPageId":4242424} -->
<!-- wp:swiftforms/field-text {"label":"Name","name":"name","required":true,"autocomplete":"name"} /-->

<!-- wp:swiftforms/field-email {"label":"Email","name":"email","required":true,"autocomplete":"email"} /-->

<!-- wp:swiftforms/submit {"label":"Continue"} /-->

<!-- wp:swiftforms/confirmation -->
<!-- wp:paragraph -->
<p>Funnel C: the thank-you page is gone, so you see this message.</p>
<!-- /wp:paragraph -->
<!-- /wp:swiftforms/confirmation -->
<!-- /wp:swiftforms/form -->

<!-- wp:swiftforms/form {"formId":"5f080004-0000-4000-8000-000000000004","title":"Funnel D (javascript)","confirmationType":"redirect","redirectUrl":"javascript:alert(1)"} -->
<!-- wp:swiftforms/field-text {"label":"Name","name":"name","required":true,"autocomplete":"name"} /-->

<!-- wp:swiftforms/field-email {"label":"Email","name":"email","required":true,"autocomplete":"email"} /-->

<!-- wp:swiftforms/submit {"label":"Continue"} /-->

<!-- wp:swiftforms/confirmation -->
<!-- wp:paragraph -->
<p>Funnel D: unsafe address ignored, so you see this message.</p>
<!-- /wp:paragraph -->
<!-- /wp:swiftforms/confirmation -->
<!-- /wp:swiftforms/form -->
HTML
				,
				// page-thanks.
				'page-thanks'          => <<<'HTML'
<!-- wp:heading -->
<h2 class="wp-block-heading">Thank you</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>You made it.</p>
<!-- /wp:paragraph -->
HTML
				,
				// form-eu-contact.
				'form-eu-contact'      => <<<'HTML'
<!-- wp:swiftforms/form {"formId":"5f090001-0000-4000-8000-000000000001","title":"EU contact"} -->
<!-- wp:swiftforms/field-text {"label":"Name","name":"name","required":true,"autocomplete":"name"} /-->

<!-- wp:swiftforms/field-email {"label":"Email","name":"email","required":true,"autocomplete":"email"} /-->

<!-- wp:swiftforms/field-textarea {"label":"Message","name":"message","required":true} /-->

<!-- wp:swiftforms/field-consent {"label":"Consent","name":"gdpr","text":"I agree that my data is stored to answer my request."} /-->

<!-- wp:swiftforms/submit /-->

<!-- wp:swiftforms/confirmation -->
<!-- wp:paragraph -->
<p>Thank you. Your request is stored for 30 days.</p>
<!-- /wp:paragraph -->
<!-- /wp:swiftforms/confirmation -->
<!-- /wp:swiftforms/form -->
HTML
				,
				// page-eu-contact.
				'page-eu-contact'      => <<<'HTML'
<!-- wp:heading -->
<h2 class="wp-block-heading">Contact (EU)</h2>
<!-- /wp:heading -->

<!-- wp:swiftforms/form {"formId":"5f090001-0000-4000-8000-000000000001","title":"EU contact"} -->
<!-- wp:swiftforms/field-text {"label":"Name","name":"name","required":true,"autocomplete":"name"} /-->

<!-- wp:swiftforms/field-email {"label":"Email","name":"email","required":true,"autocomplete":"email"} /-->

<!-- wp:swiftforms/field-textarea {"label":"Message","name":"message","required":true} /-->

<!-- wp:swiftforms/field-consent {"label":"Consent","name":"gdpr","text":"I agree that my data is stored to answer my request."} /-->

<!-- wp:swiftforms/submit /-->

<!-- wp:swiftforms/confirmation -->
<!-- wp:paragraph -->
<p>Thank you. Your request is stored for 30 days.</p>
<!-- /wp:paragraph -->
<!-- /wp:swiftforms/confirmation -->
<!-- /wp:swiftforms/form -->
HTML
				,
				// page-storage-a.
				'page-storage-a'       => <<<'HTML'
<!-- wp:heading -->
<h2 class="wp-block-heading">Stored and default</h2>
<!-- /wp:heading -->

<!-- wp:swiftforms/form {"formId":"5f100001-0000-4000-8000-000000000001","title":"Always stored","storeEntries":"yes"} -->
<!-- wp:swiftforms/field-text {"label":"Name","name":"name","required":true,"autocomplete":"name"} /-->

<!-- wp:swiftforms/field-email {"label":"Email","name":"email","required":true,"autocomplete":"email"} /-->

<!-- wp:swiftforms/field-textarea {"label":"Message","name":"message"} /-->

<!-- wp:swiftforms/submit /-->

<!-- wp:swiftforms/confirmation -->
<!-- wp:paragraph -->
<p>Sent. Always stored.</p>
<!-- /wp:paragraph -->
<!-- /wp:swiftforms/confirmation -->
<!-- /wp:swiftforms/form -->

<!-- wp:swiftforms/form {"formId":"5f100002-0000-4000-8000-000000000002","title":"Follows the site"} -->
<!-- wp:swiftforms/field-text {"label":"Name","name":"name","required":true,"autocomplete":"name"} /-->

<!-- wp:swiftforms/field-email {"label":"Email","name":"email","required":true,"autocomplete":"email"} /-->

<!-- wp:swiftforms/field-textarea {"label":"Message","name":"message"} /-->

<!-- wp:swiftforms/submit /-->

<!-- wp:swiftforms/confirmation -->
<!-- wp:paragraph -->
<p>Sent. Follows the site.</p>
<!-- /wp:paragraph -->
<!-- /wp:swiftforms/confirmation -->
<!-- /wp:swiftforms/form -->
HTML
				,
				// page-storage-b.
				'page-storage-b'       => <<<'HTML'
<!-- wp:heading -->
<h2 class="wp-block-heading">Never stored</h2>
<!-- /wp:heading -->

<!-- wp:swiftforms/form {"formId":"5f100003-0000-4000-8000-000000000003","title":"Never stored","storeEntries":"no"} -->
<!-- wp:swiftforms/field-text {"label":"Name","name":"name","required":true,"autocomplete":"name"} /-->

<!-- wp:swiftforms/field-email {"label":"Email","name":"email","required":true,"autocomplete":"email"} /-->

<!-- wp:swiftforms/field-textarea {"label":"Message","name":"message"} /-->

<!-- wp:swiftforms/submit /-->

<!-- wp:swiftforms/confirmation -->
<!-- wp:paragraph -->
<p>Sent. Never stored.</p>
<!-- /wp:paragraph -->
<!-- /wp:swiftforms/confirmation -->
<!-- /wp:swiftforms/form -->
HTML
				,
			];
		}
		if ( ! array_key_exists( $name, $fragments ) ) {
			throw new InvalidArgumentException( 'Unknown block fixture: ' . $name ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing message.
		}
		return $fragments[ $name ];
	}
}
