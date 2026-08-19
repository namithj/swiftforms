# SwiftForms — Developer Hooks Reference

All hooks are prefixed `swf_`. Field-type, endpoint, and design internals are
built on the same hooks documented here — there is no separate "internal"
API.

## Actions

### `swf_pre_submission( array $fields, int $form_id )`
Fires after validation passes, before the entry is saved. `$fields` is the
schema-enforced, validated field list (`[{slug, type, value, attributes}]`).

### `swf_post_submission( int $entry_id, int $form_id, array $fields )`
Fires after storage, notifications, and webhooks have run. `$entry_id` is
`0` when the form's `saveEntries` setting is disabled.

### `swf_entry_saved( int $entry_id, int $form_id )`
Fires the moment an entry post and its field meta are written.

## Filters

### `swf_field_types( array $types )`
The complete field-type registry (`Fields\FieldType` instances, keyed by
type). Add a custom field type in pure PHP:

```php
add_filter( 'swf_field_types', function ( array $types ) {
    $types['rating_10'] = new SwiftForms\Fields\FieldType(
        type: 'rating_10',
        label: __( '1-10 Rating', 'my-plugin' ),
        attributes: [],
        validate: fn( $value, $attrs ) => null,
    );
    return $types;
} );
```
A matching `swf/field-rating_10` block and its editor UI still need to be
registered on the JS side (see `src/field-factory/`).

### `swf_field_html_{type}( string $html, array $attributes, string $block_name )`
Filters one field's rendered HTML. `{type}` is the field type key, e.g.
`swf_field_html_email`.

### `swf_settings_schema( array $tabs )`
Adds tabs/fields to the global Settings screen. See
`includes/Settings/GlobalSettingsPage.php` for the shape — tab id => `{
label, fields: [ field config, … ] }`, where each field config is a
Cassette-CMF field array (`name`, `type`, `label`, `default`, …; see that
library's field types: `text`, `textarea`, `select`, `checkbox`, `radio`,
`number`, `email`, `url`, `date`, `password`, `color`, and the container
types `tabs`/`metabox`/`group`/`repeater`).

### `swf_form_settings_schema( array $tabs )`
Same shape, for the per-form Settings meta box on the form's post-edit
screen. See `includes/Settings/FormSettingsMetabox.php` — every field
`name` must be run through `FormSettingsMetabox::meta_key( $key )` so it's
stored under the right `_swf_setting_{key}` post meta and
`FormSettings::get()` can find it again. Unlike the old REST-backed popup,
Cassette-CMF builds this field tree once on `init`, before any specific
form is being edited, so there's no `$form_id` to filter on.

### `swf_email_content( string $body, string $context, int $entry_id )`
Filters an outgoing notification body. `$context` is `admin` or
`autoresponder`.

### `swf_webhook_payload( array $payload, int $entry_id )`
Filters the JSON payload sent to a form's webhook URL.

### `swf_allowed_upload_types( array $types )`
Extends the allowed file upload extension => MIME map (default: jpg/jpeg,
png, pdf, txt).

### `swf_rate_limit_max_requests( int $max )` / `swf_rate_limit_window_seconds( int $seconds )`
Override the submission rate limit (defaults come from global settings).

### `swf_min_submit_seconds( int $seconds )`
Minimum time that must elapse between a form rendering and being submitted
(the time-trap spam check).

### `swf_client_ip( string $ip )`
Override how the client IP is resolved (e.g. behind a proxy/load balancer).

### `swf_turnstile_verify_response( array $decoded, array $request )`
Filters the decoded Cloudflare Turnstile verification response.

### `swf_akismet_result( bool $is_spam, array $request )`
Filters the final Akismet spam determination.

### `swf_uninstall_delete_data( bool $delete )`
Whether forms, entries, and uploaded files are deleted when the plugin is
uninstalled (default: the site's "Delete all data on uninstall" setting).

## REST API

`POST /wp-json/swf/v1/submit` is the only route SwiftForms registers. It is
public — the pipeline's own nonce/rate-limit/spam checks are the gate, not a
capability. A stale-nonce rejection returns a fresh nonce in its response
body so a cached page can retry once.

Global and per-form settings have no REST route either: both save through
Cassette-CMF's own postback/`save_post` handlers.

Entries have no REST route or dedicated admin screen — they're a plain
`swf_entry` custom post type (see `includes/PostTypes.php`), managed through
WordPress's own list table and Custom Fields metabox, with a `swf_entry_form`
taxonomy for filtering by source form.
