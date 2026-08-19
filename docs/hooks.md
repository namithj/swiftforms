# SwiftForms — Developer Hooks Reference

All hooks are prefixed `smartlogix_swiftforms_`. Field-type, endpoint, and design internals are
built on the same hooks documented here — there is no separate "internal"
API.

SwiftForms is unreleased, so no short-prefix compatibility aliases are provided. The `smartlogix_swiftforms_*` surface below is the initial public API; breaking hook changes will require a documented deprecation period after 1.0. Runtime Composer dependencies are lockfile-controlled. If another plugin preloads an incompatible Cassette-CMF API, SwiftForms stops before registration and shows administrators an error instead of producing a fatal collision.

## Actions

### `smartlogix_swiftforms_pre_submission( array $fields, int $form_id )`
Fires after validation passes, before the entry is saved. `$fields` is the
schema-enforced, validated field list (`[{slug, type, value, attributes}]`).

### `smartlogix_swiftforms_post_submission( int $entry_id, int $form_id, array $fields )`
Fires after storage, notifications, and webhooks have run. `$entry_id` is
`0` when the form's `saveEntries` setting is disabled.

### `smartlogix_swiftforms_entry_saved( int $entry_id, int $form_id )`
Fires the moment an entry post and its field meta are written.

## Filters

### `smartlogix_swiftforms_field_types( array $types )`
The complete field-type registry (`Fields\FieldType` instances, keyed by
type). Add a custom field type in pure PHP:

```php
add_filter( 'smartlogix_swiftforms_field_types', function ( array $types ) {
    $types['rating_10'] = new SwiftForms\Fields\FieldType(
        type: 'rating_10',
        label: __( '1-10 Rating', 'my-plugin' ),
        attributes: [],
        validate: fn( $value, $attrs ) => null,
    );
    return $types;
} );
```
A matching `smartlogix-swiftforms/field-rating_10` block and its editor UI still need to be
registered on the JS side (see `src/field-factory/`).

### `smartlogix_swiftforms_field_html_{type}( string $html, array $attributes, string $block_name )`
Filters one field's rendered HTML. `{type}` is the field type key, e.g.
`smartlogix_swiftforms_field_html_email`.

### `smartlogix_swiftforms_settings_schema( array $tabs )`
Adds tabs/fields to the global Settings screen. See
`includes/Settings/GlobalSettingsPage.php` for the shape — tab id => `{
label, fields: [ field config, … ] }`, where each field config is a
Cassette-CMF field array (`name`, `type`, `label`, `default`, …; see that
library's field types: `text`, `textarea`, `select`, `checkbox`, `radio`,
`number`, `email`, `url`, `date`, `password`, `color`, and the container
types `tabs`/`metabox`/`group`/`repeater`).

### `smartlogix_swiftforms_form_settings_schema( array $tabs )`
Same shape, for the per-form Settings meta box on the form's post-edit
screen. See `includes/Settings/FormSettingsMetabox.php` — every field
`name` must be run through `FormSettingsMetabox::meta_key( $key )` so it's
stored under the right `_smartlogix_swiftforms_setting_{key}` post meta and
`FormSettings::get()` can find it again. Unlike the old REST-backed popup,
Cassette-CMF builds this field tree once on `init`, before any specific
form is being edited, so there's no `$form_id` to filter on.

### `smartlogix_swiftforms_email_content( string $body, string $context, int $entry_id )`
Filters an outgoing notification body. `$context` is `admin` or
`autoresponder`.

### `smartlogix_swiftforms_webhook_payload( array $payload, int $entry_id )`
Filters the immutable JSON payload before it is stored and queued for a
form's webhook URL. See README.md for the signature and retry contract.

### `smartlogix_swiftforms_allowed_upload_types( array $types )`
Extends the allowed file upload extension => MIME map (default: jpg/jpeg,
png, pdf, txt).

### `smartlogix_swiftforms_rate_limit_max_requests( int $max )` / `smartlogix_swiftforms_rate_limit_window_seconds( int $seconds )`
Override the submission rate limit (defaults come from global settings).

### `smartlogix_swiftforms_min_submit_seconds( int $seconds )`
Minimum time that must elapse between a form rendering and being submitted
(the time-trap spam check).

### `smartlogix_swiftforms_client_ip( string $ip )`
Override how the client IP is resolved (e.g. behind a proxy/load balancer).

### `smartlogix_swiftforms_turnstile_verify_response( array $decoded, array $request )`
Filters the decoded Cloudflare Turnstile verification response.

### `smartlogix_swiftforms_akismet_result( bool $is_spam, array $request )`
Filters the final Akismet spam determination.

### `smartlogix_swiftforms_uninstall_delete_data( bool $delete )`
Whether forms, entries, and uploaded files are deleted when the plugin is
uninstalled (default: the site's "Delete all data on uninstall" setting).

## REST API

`POST /wp-json/smartlogix-swiftforms/v1/submit` accepts submissions. `GET /wp-json/smartlogix-swiftforms/v1/challenge/{form_id}` refreshes short-lived public form tokens and the optional math challenge for published forms. Both routes are
public — the pipeline's own nonce/rate-limit/spam checks are the gate, not a
capability. A stale-nonce rejection returns a fresh nonce in its response
body so a cached page can retry once.

Global and per-form settings have no REST route either: both save through
Cassette-CMF's own postback/`save_post` handlers.

Entries have no REST route. They use the `smartlogix_swf_entry` custom post type and a read-only details panel with protected attachment links, delivery state, search, filters, and export. The `smartlogix_swf_entry_form` taxonomy filters by source form.
