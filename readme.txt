=== SwiftForms ===
Contributors: smartlogix
Tags: forms, contact form, gutenberg, blocks, conditional logic
Requires at least: 6.6
Tested up to: 6.8
Requires PHP: 8.2
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A streamlined, block-based form builder for WordPress with a native
Settings meta box, a dedicated Entries screen, layered spam protection,
conditional logic, and a themeable design system.

== Description ==

SwiftForms builds forms entirely from Gutenberg blocks — one block per
field, plus a step block for multi-step forms. Every form is its own post,
edited like any other piece of content.

**Builder + Settings meta box, Entries screen** — notifications, spam
protection, integrations, and per-form design overrides all live in a Form
Settings meta box right on the form's own edit screen, saved the same
native way any other post's custom fields are, while entries across every
form get their own filterable list screen instead of one cramped sidebar
panel.

**A themeable design system** — six preset skins (Default, Minimal,
Outlined, Filled, Rounded, Dark), per-form color/radius/density/label
overrides (from that same meta box's Design tab), and a site-wide default,
all built on CSS custom properties a theme can override.

**Security by default** — honeypot, a signed render-timestamp time trap,
math CAPTCHA or Cloudflare Turnstile, per-IP rate limiting, and optional
Akismet integration. The submitted form's own stored blocks — not the
client request — are always the source of truth for field types and
validation rules.

**Built for developers** — a documented hook map (`docs/hooks.md`), a
`swf_field_types` filter for registering custom field types in pure PHP,
and an extensible Settings schema addons can add tabs/fields to without
writing any JavaScript.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/swiftforms` or install via
   Plugins → Add New.
2. Activate the plugin.
3. Go to SwiftForms → Add New to build your first form.

== Frequently Asked Questions ==

= Can I use a custom SMTP server? =

Yes — SwiftForms → Settings → Email.

= Can I add my own field types? =

Yes, in PHP via the `swf_field_types` filter — see `docs/hooks.md`.

== Changelog ==

= 1.0.0 =
* Initial release.
