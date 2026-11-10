=== SwiftForms – Contact Form Builder for the Block Editor ===
Contributors: namithjawahar
Tags: contact form, form builder, forms, gutenberg form, block editor
Requires at least: 6.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Build forms right in the block editor. Entries, email notifications and spam protection included, styled by your theme.

== Description ==

SwiftForms adds a Form block to the block editor. Put it on any page or post, add field blocks inside it, publish, and the form works: it stores every submission as an entry in wp-admin, emails you, shows the visitor a confirmation and keeps spam out without a CAPTCHA.

The form looks like the rest of your site. It uses your theme's fonts, colours and spacing, so there is no separate style to learn.

**Build forms with blocks**

* One Form block you can place on pages, posts, synced patterns, template parts, templates and block widget areas.
* Twelve field blocks: text, email, textarea, number, URL, phone, date, dropdown, radio buttons, checkboxes, a consent checkbox and a hidden field.
* A submit button block and a confirmation block that you edit like any other block.
* Layout with the blocks you already know: columns, groups, headings, paragraphs, lists, images, separators and spacers can sit inside a form.
* Field settings in the sidebar: label, name, required, help text, placeholder, default value, width, autocomplete and the limits that fit the field (length, range, pattern, minimum and maximum choices).
* Six ready-made patterns in the inserter's "Forms" category: contact, quote request, callback request, newsletter signup, event RSVP and feedback.

**Entries in wp-admin**

* Every submission is stored on your site and listed under Forms, Entries, with the form, the date and a summary of what was sent.
* Filter by form or status. Open an entry to see everything the visitor sent, even after you change the form later.
* Mark entries read or unread, star them, mark them as spam, move them to the trash, or delete them for good.
* Visitor input is always shown as plain text. A web address becomes a link only when it starts with http or https.

**Email notifications**

* Each form emails the site's admin address by default, or the addresses you choose under Forms, Settings, Email.
* Add more notifications per form with their own recipients and subject, and use merge tags such as the form title and any field value.
* An HTML email template with your logo, an accent colour and a footer, plus a plain-text version.
* If an email fails, the entry is already stored, so nothing is lost.
* Limits on mail sent to visitors (for example when a merge tag picks the recipient) protect your sender reputation: once per address per form every ten minutes, and daily and hourly caps.

**Spam protection without a CAPTCHA**

* A hidden honeypot field, a minimum-time check, a signed token, and your own list of blocked words.
* A per-form submission limit, and a site-wide throttle on the public endpoints.
* Spam is stored as spam (so you can review it) or discarded, as you choose. Spam never triggers an email.

**Works with page caches**

* The security token is valid for seven days, so a form on a page that a cache has served for days still submits.
* Forms work without JavaScript: the page reloads with the confirmation or the errors.
* With JavaScript, submissions are sent in the background and the confirmation appears in place.

**Accessible and light**

* Built to WCAG 2.2 AA: real labels, fieldset and legend for grouped choices, an error summary that receives focus, errors linked to their fields, a confirmation announced to screen readers, visible focus, and large enough touch targets.
* The stylesheet and the script load only on pages that show a form. No jQuery.

**Privacy**

* Entries are stored in your own database. Nothing is sent to another site or service.
* IP addresses and browser user agents are not stored unless you turn that on.
* Set a retention period under Forms, Settings, Privacy and old entries are deleted automatically.
* Works with the WordPress personal data exporter and eraser, and adds suggested text to the privacy policy guide.

**For developers**

* Actions: `swiftforms_entry_saved` (after an entry is stored) and `swiftforms_submission_complete`; filters such as `swiftforms_before_send_mail`, `swiftforms_merge_tags`, `swiftforms_validate_field`, `swiftforms_spam_check` and `swiftforms_client_ip` (for sites behind a proxy or CDN).
* Entries have REST routes under `swiftforms/v1` and settings under `swiftforms-cmf/v1`, both protected by capabilities.
* Multisite aware: each site gets its own tables.

SwiftForms 1.0 does not include file uploads, conditional logic, multi-step forms, exports, a shortcode or a forms library.

== Installation ==

1. Upload the plugin to the `/wp-content/plugins/` folder, or install it from the Plugins screen.
2. Activate it through the Plugins screen.
3. Edit any page and add the Form block, or open the inserter, choose Patterns, then Forms, and pick a ready-made form.
4. Publish the page. Entries appear under Forms, Entries.

SwiftForms needs WordPress 6.8 or newer and PHP 7.4 or newer.

== Frequently Asked Questions ==

= Is SwiftForms free? =

Yes. SwiftForms is free software released under the GPL. The plugin on this page is the whole plugin: nothing in it is locked, time-limited or switched off until you pay.

= Where are the entries stored? =

In your own WordPress database, in six tables named `{prefix}swiftforms_forms`, `_schemas`, `_entries`, `_entry_values`, `_entry_meta` and `_log`. They are never sent anywhere. You read and manage them under Forms, Entries.

= Will updates break my site? =

Updates are not meant to. The stored data has a written format that releases must keep, the minimum versions (WordPress 6.8, PHP 7.4) are not raised, and each release has to pass a test suite that runs twenty sample site configurations (block and classic themes, multisite, other languages, heavy and damaged data, and pages cached for days) one after another. If something still goes wrong, please open a thread in the support forum.

= My form emails do not arrive. What should I do? =

First check that the entry appears under Forms, Entries: if it does, the form works and the problem is mail delivery. By default WordPress sends mail through your host's PHP mail, which many hosts and mail providers filter. Install an SMTP or transactional email plugin so your site sends through a proper mail service, and check the recipient under Forms, Settings, Email. Also look in the spam folder. A mail log inside SwiftForms is planned for version 1.2.

= Does it work with page caching plugins and CDNs? =

Yes. The token that proves a form came from your site is valid for seven days, and the form refreshes it in the background when needed, so a cached page keeps working. Without JavaScript, the confirmation comes back in the page URL; a cache that ignores query strings can hide that confirmation, but the entry is stored. If your site sits behind a CDN or proxy that does not pass on the visitor's real IP address, use the `swiftforms_client_ip` filter so the limits count visitors separately.

= What happens if I deactivate SwiftForms? =

Your entries and settings stay in the database. While the plugin is off, WordPress shows only the static content saved inside the form blocks, that is your layout blocks (headings, paragraphs, columns and so on) and the confirmation text; the fields are not shown and nobody can submit. Activate it again and everything comes back. Deleting the plugin in 1.0 also leaves the data in place.

= What data does SwiftForms store about visitors? =

What they typed in each field, the date and time, the form and the page it was on, and their user account if they were logged in. The IP address and browser user agent are stored only if you turn those settings on. Settings let you delete old entries automatically after a number of days. Spam checks use a salted, hashed form of the IP address for a short time; it is not stored with the entry.

= Can I answer a visitor's request to see or erase their data? =

Yes. SwiftForms registers with the WordPress exporter and eraser under Tools, Export Personal Data and Erase Personal Data. They find entries by the visitor's email address and, for logged-in visitors, by their user account.

= Does the plugin talk to other websites? =

No. See the "External services" section below.

= Why is there no CAPTCHA? =

The default protection is invisible: a honeypot field, a minimum fill-in time, a signed token and a rate limit. It stops common bots without asking your visitors to prove anything. You can add your own words to the blocked list under Forms, Settings, Spam.

== External services ==

SwiftForms does not connect to any external service. It does not send data to the plugin author or to any third party, loads no scripts, fonts or images from other sites, and has no tracking or analytics.

== Source code ==

The full source, including the JavaScript sources of everything in the `build/` folder, is on GitHub: https://github.com/namithj/swiftforms

To build the plugin from source:

`composer install && npm ci && npm run build`

== Screenshots ==

1. Add a Form block anywhere and build it like any other block.
2. Inherits your theme's fonts, colours and spacing. No CSS needed.
3. Start from a ready-made pattern.
4. Every submission is stored in a familiar WordPress screen.
5. Full details, even after you change the form.
6. Notifications and spam protection in one place.

== Changelog ==

= 1.0.0 =
* First release. Block-native forms with twelve field types, six ready-made patterns, submissions with or without JavaScript and behind page caches, entries in wp-admin, email notifications, invisible spam protection and rate limiting, settings and privacy tools.
