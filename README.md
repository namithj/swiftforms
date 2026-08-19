# SwiftForms

SwiftForms is a block-based form builder for WordPress. It lets you create a form once, save it inside WordPress, and then place that form on any page or post with a block.

It is built for people who want a simple editor-based workflow:

1. Create a form in the SwiftForms area in the WordPress admin.
2. Add the fields you need.
3. Adjust the submit message, email notifications, and spam protection.
4. Insert the SwiftForms block on a page and choose the form you created.

## What SwiftForms can do

- Build forms with WordPress blocks.
- Add text, email, textarea, URL, file upload, number, phone, select, and checkbox fields.
- Mark fields as required.
- Add labels, placeholders, help text, and select options.
- Save submissions in the WordPress admin.
- Send admin notification emails when someone submits a form.
- Send an optional autoresponder to the visitor when an email field is present.
- Protect forms with a hidden honeypot field and an optional math captcha.
- Export selected submissions to CSV.

## Who it is for

SwiftForms is a good fit if you want:

- a form builder that works inside the block editor,
- form entries stored in WordPress,
- a lightweight setup without shortcodes,
- simple built-in protection against basic spam.

## How the workflow works

SwiftForms uses a two-step workflow.

### 1. Create the form

In the WordPress admin, open the `Forms` menu added by SwiftForms.

Inside a form, you can add field blocks such as:

- Text Field
- Email Field
- Textarea Field
- URL Field
- File Field
- Number Field
- Phone Field
- Select Field
- Checkbox Field

Each field can be configured in the editor. Depending on the field type, you can set things like:

- field label,
- field slug,
- placeholder text,
- help text,
- required status,
- number limits,
- select options,
- checkbox text.

### 2. Place the form on a page

After saving a form, edit the page or post where you want it to appear.

Insert the `SwiftForms Form` block and choose one of your saved forms from the block settings. The block will display that saved form on the frontend.

## Form settings

When editing a form, SwiftForms adds document sidebar panels for form behavior and notifications.

### Form experience

You can control:

- the submit button label,
- the success message shown after submission,
- whether to enable the optional math captcha.

### Notifications

You can also control:

- the email address or addresses that receive admin notifications,
- the admin email subject,
- an optional admin email template,
- the autoresponder subject,
- an optional autoresponder template.

If you leave the template fields empty, SwiftForms generates a default message automatically.

## Where submissions are stored

Submitted entries are stored privately inside WordPress and are visible in the `Submissions` area in the admin.

From there you can:

- review stored field values,
- open uploaded files,
- see which form a submission came from,
- export selected submissions to CSV.

## Spam protection

SwiftForms includes two anti-spam measures:

- a hidden honeypot field that helps catch simple bots,
- an optional math captcha you can enable per form.

The captcha is intentionally lightweight. It uses short-lived, single-use challenges that refresh after submission attempts, but it is only useful for reducing basic automated spam. It is not a substitute for correctly configured Turnstile or Akismet on higher-risk forms. Cached pages can refresh stale nonces and challenges without counting the failed refresh as a submission attempt.

## Privacy and retention

Every form requires an explicit retention decision before it can be published. Choose the shortest period appropriate for the form; setting retention to `0` deliberately keeps entries indefinitely. Files follow their entry lifecycle. WordPress’s privacy exporter and eraser locate entries by submitted email address, but copies already delivered to email/SMTP, Akismet, Cloudflare Turnstile, or webhook providers must be handled separately with those recipients.

Before enabling an integration, document the fields collected, purpose, recipients, provider locations, retention, and legal basis in the site privacy policy. SwiftForms adds starter text to WordPress’s Privacy Policy Guide, but the site owner must adapt it to the actual form and providers.

### Secret storage and rotation

SMTP, Turnstile, and webhook secrets are never rendered back into settings screens. Blank saves preserve existing values; use the explicit clear control to remove one, or enter its replacement to rotate it. Database values are plaintext WordPress options/post meta and may appear in database backups—SwiftForms does not claim application-level encryption. For environment-managed read-only secrets, define `SMARTLOGIX_SWIFTFORMS_SMTP_PASSWORD`, `SMARTLOGIX_SWIFTFORMS_TURNSTILE_SITE_KEY`, `SMARTLOGIX_SWIFTFORMS_TURNSTILE_SECRET_KEY`, or `SMARTLOGIX_SWIFTFORMS_WEBHOOK_SECRET` in `wp-config.php`. Rotate at the provider/receiver first, update the constant or saved value, test delivery, then revoke the old credential.

## Installation

1. Upload the `swiftforms` folder to `wp-content/plugins/`.
2. Activate `SwiftForms` from the Plugins screen.
3. Open `Forms` in the WordPress admin and create your first form.
4. Add the `SwiftForms Form` block to a page or post and select your saved form.

## Frequently asked questions

### Do I build forms directly inside the page?

Not exactly. You first create and save a form in the `Forms` area, then you embed that saved form on a page or post using the `SwiftForms Form` block.

### Are submissions public?

No. Submissions are stored as private admin records.

### Can visitors upload files?

Yes, if you add a File Field to the form.

### Can I export entries?

Yes. Selected submissions can be exported as a CSV file from the WordPress admin.

### Does it send confirmation emails to visitors?

It can. If the submission includes an email field, SwiftForms can send an autoresponder email back to the visitor.

## Current version

Version: 1.0.0
