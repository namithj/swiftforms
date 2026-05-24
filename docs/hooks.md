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

## Planned hook documentation

The scaffold is ready for additional field-rendering and integration hooks as the plugin surface expands.