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

The captcha is intentionally lightweight. It is useful for reducing basic spam, but it is not meant to replace a dedicated third-party anti-spam service for high-risk sites.

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

Version: 0.1.0