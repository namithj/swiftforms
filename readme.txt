=== SwiftForms ===
Contributors: smartlogix
Requires at least: 6.6
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Tags: forms, contact form, gutenberg, block-editor, ajax

SwiftForms is a Gutenberg-native form builder focused on performance, secure submission handling, and an extensible developer API.

== Description ==

SwiftForms provides block-based form building inside the WordPress editor.

Features in the current plugin scaffold include:

* Native `swiftforms/form` Gutenberg block.
* Field blocks for text, email, textarea, URL, file, number, phone, select, and checkbox inputs.
* AJAX submissions with nonce protection, honeypot spam filtering, and optional math captcha validation.
* Submission storage in a private custom post type with `_sf_field_*` post meta.
* Configurable admin notifications and autoresponder templates.
* Developer hooks for submission lifecycle, upload policy, and email content filtering.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/swiftforms`.
2. Activate SwiftForms through the Plugins screen in WordPress.
3. Add the SwiftForms block to a page or post in the block editor.

== Frequently Asked Questions ==

= Does SwiftForms require a shortcode? =

No. Forms are created with native blocks in the WordPress editor.

= Where are submissions stored? =

Submissions are stored in the private `swiftform_entry` custom post type with field values saved as post meta.

= Can developers customize behavior? =

Yes. SwiftForms exposes filters and actions documented in the `docs` directory.

== Changelog ==

= 0.1.0 =

* Initial scaffold release with Gutenberg-native form blocks, AJAX submission handling, notifications, and automated test setup.