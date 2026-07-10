=== SwiftForms ===
Contributors: smartlogix
Tags: forms, contact form, block editor, gutenberg, submissions
Requires at least: 6.6
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Create forms in the WordPress block editor, collect submissions in the admin, and place saved forms on any page with a block.

== Description ==

SwiftForms is a block-based form builder for WordPress.

Instead of building a form with a shortcode, you create a saved form inside WordPress and then embed it on a page or post using the SwiftForms block.

SwiftForms currently includes:

* A saved-form workflow built for the block editor, with ready-made starter templates (contact, quote request, feedback survey, event registration).
* Form field blocks for text, email, textarea, URL, file upload, number, phone, date, select, radio, checkbox, and hidden fields — select and radio options support `Label|value` pairs.
* Conditional logic on every field: show or hide fields based on other answers, with AND/OR rule groups, enforced on the server as well as in the browser.
* Multi-step forms with a step container block, per-step validation, and an accessible progress indicator.
* Required-field support, placeholders, help text, select options, and number/date constraints.
* Admin notification emails for new submissions, with configurable recipients, subjects, and templates.
* Optional autoresponder emails for visitors who submit an email address.
* SMTP delivery settings with a one-click test email.
* Layered spam protection: hidden honeypot, minimum-submit-time trap, per-IP rate limiting, optional math captcha, optional Cloudflare Turnstile, and optional Akismet filtering that files matches as reviewable spam.
* Private submission storage with unread indicators, field-value search, per-form filtering, spam management, and CSV export.
* Webhooks, a REST submission endpoint, GDPR personal-data export/erase, and per-form data retention.

How it works:

1. Create a form in the `Forms` area added by SwiftForms.
2. Add and configure your field blocks.
3. Set the submit button text, success message, and notification options.
4. Add the `SwiftForms Form` block to a page or post and choose the saved form you want to display.

When editing a form, SwiftForms provides settings for:

* Submit button label.
* Success message.
* Optional math captcha.
* Admin notification recipients and subject.
* Optional admin email template.
* Autoresponder subject and template.

Submissions are stored privately in the WordPress admin. Site owners can review field values, open uploaded files, and export selected entries to CSV.

== Installation ==

1. Upload the `swiftforms` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the `Plugins` screen in WordPress.
3. Open the `Forms` menu in the WordPress admin and create your first form.
4. Add the `SwiftForms Form` block to a page or post.
5. Select the saved form you want to display.

== Frequently Asked Questions ==

= Do I create the form directly on the page? =

No. First create and save the form in the `Forms` area, then embed it on a page or post using the `SwiftForms Form` block.

= Where are submissions stored? =

Submissions are stored privately in the WordPress admin and are not publicly viewable.

= Can I export submissions? =

Yes. SwiftForms includes a CSV export bulk action for selected submissions.

= Does SwiftForms support file uploads? =

Yes. Add a `File Field` block to your form if you want visitors to upload a file.

= Does SwiftForms send confirmation emails to visitors? =

SwiftForms can send an autoresponder when the form submission includes an email address.

= Does SwiftForms include spam protection? =

Yes, in layers. Every form includes a hidden honeypot field, a minimum-submit-time trap, and per-IP rate limiting. Per form you can additionally enable a simple math captcha and Cloudflare Turnstile (add your keys under Forms → Settings → Spam protection). With the Akismet plugin active, submissions can also be checked against Akismet — matches are kept as reviewable spam entries instead of being rejected.

= Can I show a field only when another field has a certain answer? =

Yes. Every field block has a Conditional Logic panel where you build show/hide rules referencing other fields, with AND/OR groups. The rules are also enforced server-side.

= Can I split a long form into steps? =

Yes. Wrap fields in `Form Step` blocks; the frontend shows one step at a time with Back/Next navigation and a progress indicator.

= What happens to my data if I uninstall SwiftForms? =

Nothing, by default — forms and submissions are left untouched. You can opt in to full data deletion under Forms → Settings → Advanced.

== Changelog ==

= 0.1.0 =

* Initial public release: block-based form building with conditional logic, multi-step forms, starter templates, twelve field types, layered spam protection (honeypot, time trap, rate limiting, math captcha, Cloudflare Turnstile, Akismet), admin-managed submissions with spam review and CSV export, notification emails with SMTP delivery, webhooks, a REST endpoint, and GDPR tools.