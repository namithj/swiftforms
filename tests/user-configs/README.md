# User configurations: upgrade-safety suite

Specification: [`.ai/testing/user-configs.md`](../../.ai/testing/user-configs.md). This folder is the harness (R00) and, from R01, the 20 configs (frozen at the v1.0.0 tag).

A config is a different kind of real site, written to the database as **raw stored data** (options, post content, table rows), never through SwiftForms code. Each runs through the scenario matrix in its own PHPUnit process on a clean database, and every output is compared with a golden-master snapshot.

## What each config represents

| # | Slug | Represents |
|---|---|---|
| 01 | `01-fresh-defaults` | Brand-new install that never opened settings: no settings option, one page with the inserted-default Form block (the markup the editor produced, formId fixed). |
| 02 | `02-minimal-contact` | Typical small business: the only settings option is `swiftforms_email_notification_to`; one contact form; five existing entries in every status. |
| 03 | `03-multi-form-page` | Landing page with three forms (newsletter, callback, contact); the newsletter is a synced pattern shown twice on the page and once on two other pages. |
| 04 | `04-all-field-types` | Kitchen sink: every field type with every attribute set, hidden `{post:id}` and `{user:id}`. |
| 05 | `05-nasty-content` | Real-world mess: unicode, RTL, entities, quotes, `</textarea>`, script and shortcode look-alikes, 2 KB labels, option values with commas and pipes, in the form and in the visitor values. |
| 06 | `06-multi-notifications` | Agency lead routing: three notifications, a recipient tag on an email field and on a text field, bare Reply-To, visitor-mail limit, HTML template with logo, accent colour and footer; IP and user agent stored; blocked words. |
| 07 | `07-plain-email-custom-from` | Old-school mail: HTML template off, custom From, a plain notification, an SMTP-plugin look-alike (`mu/07-smtp-sim.php`). |
| 08 | `08-redirect-confirmations` | Marketing funnel: redirect to a page, to an external address with the email in the query, to a deleted page and to a `javascript:` address. |
| 09 | `09-strict-privacy` | EU site: no IP or user agent, 30-day retention, forty entries D-40 to D-1 (the daily job deletes exactly D-40 to D-31). |
| 10 | `10-email-only-no-storage` | "Do not keep my data": storage off for the site, one form overrides it with "always", one says "never". |
| 11 | `11-heavy-history` | Busy site: three forms, 5,000 entries in every status, multi-value checkboxes, four schema snapshots for the contact form (a field added, removed, renamed). Check: the list queries use `form_status_date` and run at most four queries a page. |
| 12 | `12-spam-hardened` | Spam-plagued site: honeypot, 8 second minimum, JS token, 2 submissions a minute, blocked words; one form with protection off; variant `discard`. |
| 13 | `13-localised-site` | `fr_FR` with a `.mo` fixture, `Asia/Kolkata`, `d/m/Y`, French labels and messages, a date field with min and max; entries near local midnight prove dates are stored in UTC. |
| 14 | `14-block-theme-templates` | Full site editing (theme `sf-test-block-files`): forms in a database footer part, a database single template, a page and a theme-file template that was never saved. |
| 15 | `15-classic-theme-widgets` | `sf-test-classic`: a form in a block widget, one in an inactive widget, one in `core/columns`, and a post whose form shows on the blog index and on its own page. |
| 16 | `16-source-states` | A form in a published, draft, private, password-protected, scheduled and trashed page; only published (and unlocked) ones accept. |
| 17 | `17-roles-and-caps` | Administrator, editor, author, contributor and a custom support role that views entries through `swiftforms_capability_map`; no role or capability is written. |
| 18 | `18-corrupt-and-partial` | Broken data: garbage options, wrong-typed options, one option only, no install marker (variants); a form without `formId`, a `formId` in two pages, duplicate field names, unknown attributes and blocks, an orphan entry, a `schema_hash` without a snapshot. |
| 19 | `19-multisite-subsite` | Network install (`WP_TESTS_MULTISITE=1`): network-activated; sites with their own tables and settings, one without tables until its first request, one created after activation; deleting a site drops its tables. PHPUnit only. |
| 20 | `20-power-user` | Developer site: twelve forms, custom classes and anchors, forms without JavaScript, restyled blocks (user global styles), plugin styles off, every documented hook in use, debug log on; variant `headless-no-js-token`. |

All twenty are frozen at the v1.0.0 tag. The full table is in the spec, section 5.

## Running

```bash
composer test:configs                          # all configs, then the global snapshots
bash bin/test-user-configs.sh 06               # only configs whose slug starts with 06 (no global snapshots)
PHP=php8.2 composer test:configs               # another PHP binary
WP_CORE_VERSION=6.8 composer test:configs      # another core (installed into .cache/wp-core/)
SF_UPDATE_SNAPSHOTS=1 composer test:configs    # regenerate snapshots (refused when CI or GITHUB_ACTIONS is set)
SF_FULL_REPORT=1 composer test:configs         # list every mismatch, not the first eight
```

The browser scenarios run separately (`npm run test:e2e:configs`, see `tests/README.md`).

The script prints `PASS` or `FAIL` per config and exits non-zero on any failure. A run that executed nothing, or skipped or marked a test risky, counts as a failure. The group runs against a throwaway core in `.cache/wp-core/<version>/` and the `swiftforms_tests` database (prefix `sftests_`), never the real site: `tests/phpunit/bootstrap-user-configs.php` refuses to start without `WP_TESTS_ABSPATH`, or when it points at a WordPress root that contains this plugin.

## Files

```
NN-slug.php              the configs (shape: spec section 3)
loader.php               SF_User_Config_Loader: list(), load(), apply(), reset()
normaliser.php           SF_Normaliser: html(), json(), text() (rules documented in the class, tested in NormaliserTest.php)
apply-cli.php            WP-CLI entry for the e2e runner: wp eval-file ... apply-cli.php <slug>
fixtures/blocks.php      SF_FX( name ): named block-markup fragments (nowdoc, exact bytes)
fixtures/values.php      SF_VALUES( name ): named submission payloads (field values, named after the config that uses them)
themes/sf-test-block/    the default fixture theme (block theme, theme.json v3)
themes/sf-test-block-files/  sf-test-block plus one theme-file template that holds a form (config 14); a separate theme so the other configs do not index that form
themes/sf-test-classic/  classic theme with one sidebar "sf-sidebar" (config 15)
fixtures/builder.php     SF_BLOCK(), SF_FIELD(), SF_FORM()...: block markup written the way the editor writes it, for configs that generate their content
fixtures/languages/      the French .po and .mo of config 13
mu/                      mu-plugin snippets a config lists in "mu_plugins"
snapshots/<slug>/        apply.txt, S1.guest.txt, S1.admin.txt, S2.txt ... S11.txt (one per scenario), check-<name>.txt, _skipped.txt
snapshots/_global/       hooks.txt, rest-routes.txt, db-schema.txt, blocks.txt
```

`apply()` writes in this order: site settings (locale, timezone, permalinks, fixture theme), users, posts, options, table rows, mu-plugin snippets, then fires `swiftforms_user_config_applied` (test-only) and runs `SwiftForms\Form_Index::rebuild()`, exactly what the plugin does after a version change. Notes:

- Before anything is written the stored state is reset: every `swiftforms_*` option is removed and every SwiftForms table is created if missing and emptied (rows deleted, not truncated, so the PHPUnit transaction still rolls back). The plugin installs itself on `init`, so a bare test database already holds `swiftforms_db_version`, the install marker and a migration log row; a config describes the whole stored state and must set what it wants (for example `swiftforms_db_version`). On the next web request after `apply-cli.php`, the plugin's lazy install fills in what the config left out.
- Options go through `update_option()`, but the loader throws, naming the filter, if a `sanitize_option_swiftforms_*` filter is attached (this covers every per-key settings option), so a write is never silently normalised.
- Posts are inserted with `wp_insert_post( wp_slash( ... ) )`, kses off and SwiftForms' `wp_insert_post_data` callbacks detached for the insert only. The stored `post_content` is read back from the database and must equal the fixture byte for byte.
- `{{post:key}}` and `{{user:key}}` in post content, option values and table rows become the IDs created earlier in the same call (in an option value that is exactly one placeholder, an integer: `swiftforms_email_logo_id`). Double braces, so they never collide with SwiftForms' own `{post:id}` merge tags.
- IDs are pinned: the posts of a config get IDs 900001, 900002... in the order the config lists them (`import_id`), its users 800001... (the primary key is updated after the insert). Values that contain an ID (a hidden field's `{post:id}`, `{user:id}`) are then the same on every run. Entry IDs are not pinned: the runner labels them `{E1}`, `{E2}`... in the order it meets them (entries a config inserts itself keep their IDs), and writes post references as `post:<key>` and derived formIds as `{FORM:<key>}`.
- A post may carry `meta` (post meta written raw), `terms` (taxonomy => terms, for templates and parts: `wp_theme`) and `mime` (attachments).
- A user role `admin` means `administrator`.
- The test environment (`pre_wp_mail` capture, `pre_http_request` blocked unless the config has `http_fixtures`, a temporary uploads dir, `Clock` frozen at 1795082400 = 2026-11-19T10:00:00Z, `mt_srand` and `Random::seed` 20261119, home and siteurl `http://example.org`) is installed by `apply()` and undone by `reset()`. `apply-cli.php` skips it: wp-env is a real site that a browser talks to.

## Scenarios

`apply` (harness self-check: site settings, raw `swiftforms_*` option bytes, post content), S1 (front-end render of every location), S2 to S9, S10 (schema and registry) and S11 (migrations) are implemented. S14-S16 must be listed in a config's `skip` (`'S14' => 'pending: R02'`) until their release implements them, so every `_skipped.txt` lists exactly those three. S12 and S13 run only in Playwright (`tests/e2e/user-configs.spec.js`, for every config but 19) and are never listed: the loader refuses them in `skip`. A scenario that is neither implemented nor skipped fails the run. The skip list is itself snapshotted (`_skipped.txt`), so removing a skip without an implementation is visible.

The scenarios run in order on one database state, frozen at 2026-11-19T10:00:00Z with `mt_srand(20261119)`, from the address 203.0.113.7 and the user agent `SwiftForms-Regression/1.0`. Request counters (coarse limiter, per-form limiter, duplicate guard) are cleared before every request; the visitor-mail counters at the start of every scenario.

| ID | What it does | Snapshot |
|---|---|---|
| S1 | Renders every page, post, synced pattern, template part and template of the config as a guest and as an administrator; checks that every render token and signed time verifies and that a location without a form enqueues no SwiftForms asset | `S1.guest.txt`, `S1.admin.txt`: HTML, the SwiftForms assets, the client state of the view module |
| S2 | Submits each payload the config lists under `submissions.<form>.valid` over REST, built as the view module builds it (a token issued for the form's current schema, `_sf_t` `js.10000`, empty honeypot, `_sf_v`, `_sf_i`); then the first form once more as an administrator (the only caller that gets an `entryId`) | request, response, rows written (shape asserted), mails, log rows |
| S3 | Each required field missing, then the payloads under `invalid` | the 422 payloads |
| S4 | The first valid and invalid payload and an expired token, posted without JavaScript to `Nojs_Handler` (the 303 is captured, not followed); renders the result page of the key (asserted valid, normalised to `{RESULT}`) | `Location`, rows, mails, the result page |
| S5 | Honeypot, too fast, forged and missing token, missing browser time, the blocked words of the config (`submissions.<form>.spam`), the per-form limit, the coarse limiter (61st request), locations that are not live. Each spam answer must equal the answer to a clean submission and send no mail | responses and what was stored |
| S6 | As an administrator: list, per form and per status, one entry, PATCH read, starred, spam, trash; guests are refused | JSON |
| S7 | Asserts that no SwiftForms route with "export" in its name exists (R02 turns this into CSV and JSON bytes) | `S7.txt` |
| S8 | GET the cassette-cmf settings route, POST the same `values` and a partial payload; no `add_option`, `update_option` or `delete_option` of a `swiftforms_{section}_*` option, the stored options unchanged | JSON, write count |
| S9 | The registered exporter and eraser for the config's `privacy_email` and for an unknown address; rows left behind | JSON |
| S11 | `Migrations::maybe_run()` twice on the site as stored, then twice with the version row removed (the state just before an update migrates) | state before and after |

**Variants.** A config's `variants` (name => option overrides, `null` leaves an option absent) are the same stored data with other options. The runner applies the config again for each variant and runs every scenario; the snapshots sit next to the base ones as `S2.<variant>.txt`. Variant names are lowercase letters, digits and dashes.

**Checks.** A config lists config-specific checks under `checks`; each writes `check-<name>.txt`:

| Check | What it does | Used by |
|---|---|---|
| `retention` | the daily job deletes exactly the entries older than `retention_days` | 09 |
| `patterns` | a registered, unplaced SwiftForms pattern is not a live location: a submission is `closed` (C-3.11) | 01 |
| `resave` | every stored post, pattern, template and part with a form is saved again without an edit: the content comes back byte for byte (config key `resave_writes` allows the C-2.7 write of an effective `formId`) | 03, 14, 18, 20 |
| `entry_details` | the detail view of every entry the config inserted answers (no fatal), even for a missing snapshot or a deleted form | 18 |
| `spam_off` | a form with `spamProtection: off` skips honeypot, time trap, JS token and blocked words, but a forged token is still spam | 12 |
| `heavy_history` | the list queries run at most four SwiftForms queries a page and EXPLAIN shows `form_status_date`; one entry per schema snapshot | 11 |
| `source_states` | the same valid submission to every kind of source (config key `source_states`: form key => `accepted`, `closed` or `password`) | 16 |
| `roles` | per user: the capabilities, the REST routes, whether a submission gets an `entryId`; `wp_user_roles` and user capabilities unchanged | 17 |
| `global_styles` | the owner's styles for SwiftForms blocks reach the merged global styles | 20 |
| `hooks` | the mu-plugin's callbacks for the 11 documented v1 hooks all ran; `swiftforms_client_ip` and `swiftforms_throttle_limit` change what they should | 20 |
| `multisite` | the network: sites created before and after activation, lazy install, isolation, deleting a site drops its tables (config key `subsites`) | 19 |

More config keys: `renders` (extra places S1 draws: `template`, `template_part`, `widget_area`, `blog_index`), `expect_closed` (form keys left out of the S2-S5 matrix because they answer `closed`, config 16), `roles` (custom roles written to `wp_user_roles`), `site.network_active`, `site.date_format` / `time_format` / `show_on_front`, post keys `date` (GMT), `password`, `meta`, `terms`. A post of type `wp_global_styles` is the user layer of theme.json. Snippets in `mu/` are loaded once per process.

Config keys the scenarios read besides the ones in the spec: `submissions` (form key => `valid`, `invalid`, `spam` lists of `SF_VALUES()` names; a form key is `<post key>#<position of the form block in that post>`, for a database template or part `<post key>#1`, otherwise the source reference), `privacy_email`, `mail_capture` (`wp_mail`, the default: the arguments of `wp_mail()` are captured with `pre_wp_mail`; `phpmailer`: `wp_mail()` runs for real and the final PHPMailer state is recorded after `phpmailer_init`, so a config's `wp_mail_from` and `phpmailer_init` hooks act), and `checks` (config-specific checks run after S11; `retention` deletes with the daily job and compares with the entries older than `retention_days`, snapshot `check-retention.txt`).

Rows that SwiftForms writes are checked against the formats of C-4 and, when the config inserted rows of its own into the same table, against the kind of value in each of its columns (spec principle 1).

The global snapshots are identical on every PHP version (7.4 to 8.5). `blocks.txt` is read from SwiftForms' own block configs, `hooks.txt` leaves out `SwiftForms\Vendor\`, and `rest-routes.txt` lists only `swiftforms/v1`. Two assertions that are not snapshotted run next to them: the builder's `/config` route is never public, and every configured block is registered.

## Snapshot policy

- A missing snapshot fails. `SF_UPDATE_SNAPSHOTS=1` writes it (and deletes stale files); it is forbidden in CI.
- A mismatch fails with a unified diff. Regenerating is allowed only for a change listed under "Intended snapshot changes" in the release plan.
- Configs 01-20 are frozen at the v1.0.0 tag. Editing one needs a reason in `CHANGES.md`. New coverage is a new config (21+, append-only).

## Adding config 21+

1. Copy a config, give it the next number and a one-line "represents" and the contract clauses it guards.
2. Put raw data in `options`, `posts` (fixtures in `fixtures/blocks.php`), `tables`.
3. List every scenario not yet implemented in `skip`.
4. `SF_UPDATE_SNAPSHOTS=1 composer test:configs`, review the new files, run again without it.
5. Add a row to the table above and in the spec (section 6).

## Mutation run (R01)

The suite must go red for each deliberate breakage of the plugin (spec section 7: items 1 to 10, 12 and 14 to 17; 11 joins in R02 and 13 in R06). Each was applied to a scratch **copy** of the plugin (`rsync` of the tree, never the real one), `composer test:configs` (`bin/test-user-configs.sh`, PHP 8.4, WordPress 7.1.4) was run in the copy, and the copy was discarded. "Red" counts the 21 test runs (20 configs and the global snapshots).

| # | Breakage (how it was applied) | Red | Where |
|---|---|---|---|
| 1 | Rename the attribute `labelPosition` (the key in `Field_Types`) | 21 of 21 | every config and `_global/blocks.txt` |
| 2 | `general.store_entries` default `true` to `false` | 19 of 21 | every config except 10 (storage already off) |
| 3 | Notify before storing, and the mailer throws | 20 of 21 | every config (the exception; `mail/error` log rows); the global snapshots do not run submissions |
| 4 | Drop the `idx` column of `entry_values` | 20 of 21 | every config (inserts fail). The global schema snapshot stayed green in this run: the test database table was created by the real code and `dbDelta` never drops a column, so it would be red only on a fresh database |
| 5 | Class `swiftforms-error` renamed `sf-error` | 20 of 21 | S1 and S4 of every config |
| 6 | The option check always passes | 5 of 21 | S3 of 03, 04, 06, 11, 13 (the option-not-in-list payloads) |
| 7 | Remove `do_action( 'swiftforms_entry_saved' )` | 1 of 21 | config 20, check `hooks` (the hook never ran) |
| 8 | From = the visitor's address (the Reply-To value) | 3 of 21 | mail headers in S2/S4 of 06, 13, 20 |
| 9 | The save handler rewrites an owner's `formId` | 4 of 21 | check `resave` of 03, 14, 18, 20 |
| 10 | The no-JS handler answers 200 instead of a 303 | 20 of 21 | S4 of every config |
| 12 | `Settings::update()` writes even when nothing changed | 20 of 21 | S8 (`update_option` called, bytes changed) |
| 14 | The resolver skips unknown inner blocks | 1 of 21 | config 18 (the field inside `swiftforms/step` is gone) |
| 15 | A registered pattern placed nowhere counts as a live location | 1 of 21 | config 01, check `patterns` |
| 16 | `entryId` returned to a guest | 20 of 21 | S2 and S5 of every config |
| 17 | `is_link` true for any value with a colon (no http/https check) | 1 of 21 | config 05, S6 (entry detail) |

The checks `hooks` (config 20), `resave` (03, 14, 18, 20), `patterns` (01) and config 18's unknown-block form were written in this phase so that 7, 9, 14 and 15 are catchable; whether those mutations were already red before is not known. The browser specs were not re-run for 9 and 17 (the PHPUnit run is already red for both).
