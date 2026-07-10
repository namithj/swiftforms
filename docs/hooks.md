# SwiftForms Hook Reference

## Actions

### `swiftforms_pre_submission`

Runs immediately before a validated submission is persisted.

Parameters:

* `array $request`
* `SwiftForms_Submissions $submissions`

### `swiftforms_post_submission`

Runs after a submission has been stored and notifications have been sent.

Parameters:

* `int $submission_id`
* `array $request`
* `SwiftForms_Submissions $submissions`

## Filters

### `swiftforms_email_content`

Filters the admin and autoresponder email body.

Parameters:

* `string $message`
* `string $context`
* `int $submission_id`
* `array $request`

### `swiftforms_allowed_upload_types`

Filters the allowed file upload MIME type map.

Parameter:

* `array $allowed_types`

### `swiftforms_field_html_{type}`

Filters the rendered HTML for a specific field type before it is output inside the frontend form.

Parameters:

* `string $html`
* `array $attributes`
* `string $block_name`

Examples of `{type}` include `text`, `email`, `textarea`, `select`, and `checkbox`.

### `swiftforms_webhook_payload`

Filters the JSON payload POSTed to a form's configured webhook URL.

Parameters:

* `array $payload` — `fields` (slug => value), `form_id`, `submission_id`, `submitted_at`
* `int $submission_id`
* `array $request`

### `swiftforms_rate_limit_max_requests`

Filters the maximum live submissions accepted per IP within the rate limit window. Default `5`.

The value saved on the Forms → Settings page is applied through this filter at priority `5`, so an explicit `add_filter()` at the default priority still overrides it.

### `swiftforms_rate_limit_window_seconds`

Filters the rate limit window size in seconds. Default `60`. Same priority-5 settings-page behavior as above.

### `swiftforms_min_submit_seconds`

Filters the time trap's minimum form age in seconds (default `3`, also configurable under **Forms → Settings → Spam protection**; the stored option feeds this filter at priority 5). Submissions faster than this are silently absorbed like honeypot hits. `0` disables the check. Only a minimum is enforced — cached pages serving old render timestamps still submit fine.

### `swiftforms_turnstile_verify_response`

Filters the decoded Cloudflare Turnstile siteverify response body (array) before its `success` key is checked. Receives the submission request as the second argument. Lets tests stub verification without HTTP.

### `swiftforms_akismet_result`

Filters the boolean Akismet spam verdict for a submission (second argument: the request array). Spam submissions are stored with `_sf_spam` meta, skipped for notifications/webhooks, and answered with a normal success response.

### `swiftforms_search_results_limit`

Filters the maximum number of submissions the admin field-value search matches (default `500`).

### `swiftforms_client_ip`

Filters the IP address used for rate limiting. Defaults to `REMOTE_ADDR`; sites behind a proxy or CDN can substitute a trusted forwarded-for header.

### `swiftforms_export_capability`

Filters the capability required to run the CSV export bulk action. Default `manage_options`.

### `swiftforms_uninstall_delete_data`

Return `true` (e.g. from a must-use plugin) to make plugin uninstall delete all forms, submissions, and uploaded files. Default `false` — data is preserved.

## Global settings

Site-wide options live in the `swiftforms_settings` option, editable under
**Forms → Settings** (`manage_options`): SMTP delivery (host, port,
encryption, credentials, from address — routed through `phpmailer_init`),
default admin notification recipients, the per-IP rate limit, the global
default for storing submissions as entries, spam protection (minimum submit
time, Cloudflare Turnstile keys, Akismet), and the uninstall data-deletion
opt-in. The SMTP password can be hardcoded via the `SWIFTFORMS_SMTP_PASSWORD`
constant in `wp-config.php`, which takes precedence over the stored value.
Secrets (SMTP password, Turnstile secret key) are never echoed back into the
settings form; submitting the field blank keeps the stored value.

Each form can override entry storage with its `saveEntries` setting
(`default` / `enabled` / `disabled`) in the editor sidebar. When storage is
off, notifications and webhooks still fire with `submission_id = 0`. Forms
opt into Cloudflare Turnstile individually with the `enableTurnstile` sidebar
toggle (ignored until a site key is saved globally).

## Conditional logic

Every field block carries a `conditions` attribute:

```json
{ "enabled": true, "action": "show",
  "groups": [ [ { "field": "topic", "operator": "equals", "value": "support" } ] ] }
```

Rules inside a group are AND-ed, groups are OR-ed; `action` is `show` or
`hide`. Operators: `equals`, `not_equals` (case-sensitive, trimmed),
`contains` (case-insensitive), `empty`, `not_empty`. The rules render onto
the field wrapper as `data-sf-conditions` for the frontend engine and are
re-evaluated server-side during submission: values submitted for hidden
fields are discarded and hidden required fields are not required. Chained
rules resolve by fixed-point iteration capped at 10 passes on both sides
(`SwiftForms_Conditions::MAX_PASSES`).

## Multi-step forms

Wrap fields in `swiftforms/step` blocks (two or more) and the frontend
paginates them with Back/Next navigation, per-step validation, and an
aria-live progress line. Inputs on inactive steps stay enabled so their
values submit from the last step; the schema parser sees fields inside steps
transparently.

## REST API

### `POST /wp-json/swiftforms/v1/submit`

Public submission endpoint mirroring the `admin-ajax.php` handler. Accepts the
same multipart form fields the frontend script sends (`nonce`, `form_id`,
`honeypot`, `fields[n][...]`, optional `captcha_token`/`captcha_answer`,
optional `swiftforms_files[n]` uploads) and returns the submission response
JSON with a matching HTTP status (`200`, `400`, `429`, or `500`).

Live submissions on both endpoints are validated against the stored form's
field schema: unknown field slugs are dropped, and each field's type,
required flag, number constraints, and select options come from the saved
form post — never from the client.