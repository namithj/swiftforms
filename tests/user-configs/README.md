# User configurations: upgrade-safety suite

Specification: [`.ai/testing/user-configs.md`](../../.ai/testing/user-configs.md). This folder is the harness (R00) and, from R01, the 20 frozen configs.

A config is a different kind of real site, written to the database as **raw stored data** (options, post content, table rows), never through SwiftForms code. Each runs through the scenario matrix in its own PHPUnit process on a clean database, and every output is compared with a golden-master snapshot.

## What each config represents

| # | Slug | Represents |
|---|---|---|
| 00 | `00-harness-selftest` | Harness self-test (not one of the 20; deleted when config 01 lands). One page with a core paragraph, two options, the install marker. |

Configs 01-20 are written in the first cycle (R01) and frozen at the v1.0.0 tag. The table is in the spec, section 5.

## Running

```bash
composer test:configs                          # all configs, then the global snapshots
bash bin/test-user-configs.sh 00               # only configs whose slug starts with 00 (no global snapshots)
PHP=php8.2 composer test:configs               # another PHP binary
WP_CORE_VERSION=6.8 composer test:configs      # another core (installed into .cache/wp-core/)
SF_UPDATE_SNAPSHOTS=1 composer test:configs    # regenerate snapshots (refused when CI or GITHUB_ACTIONS is set)
SF_FULL_REPORT=1 composer test:configs         # list every mismatch, not the first eight
```

The script prints `PASS` or `FAIL` per config and exits non-zero on any failure. A run that executed nothing, or skipped or marked a test risky, counts as a failure. The group runs against a throwaway core in `.cache/wp-core/<version>/` and the `swiftforms_tests` database (prefix `sftests_`), never the real site: `tests/phpunit/bootstrap-user-configs.php` refuses to start without `WP_TESTS_ABSPATH`, or when it points at a WordPress root that contains this plugin.

## Files

```
NN-slug.php              the configs (shape: spec section 3)
loader.php               SF_User_Config_Loader: list(), load(), apply(), reset()
normaliser.php           SF_Normaliser: html(), json(), text() (rules documented in the class, tested in NormaliserTest.php)
apply-cli.php            WP-CLI entry for the e2e runner: wp eval-file ... apply-cli.php <slug>
fixtures/blocks.php      SF_FX( name ): named block-markup fragments (nowdoc, exact bytes)
fixtures/values.php      SF_VALUES( name ): named submission payloads
themes/sf-test-block/    the default fixture theme (block theme, theme.json v3)
themes/sf-test-classic/  classic theme with one sidebar "sf-sidebar" (config 15)
mu/                      mu-plugin snippets a config lists in "mu_plugins"
snapshots/<slug>/        apply.txt, S1.guest.txt, S1.admin.txt, S10.txt, _skipped.txt (and later S2... per scenario)
snapshots/_global/       hooks.txt, rest-routes.txt, db-schema.txt, blocks.txt
```

`apply()` writes in this order: site settings (locale, timezone, permalinks, fixture theme), users, posts, options, table rows, mu-plugin snippets, then fires `swiftforms_user_config_applied` (test-only; R01 hooks the form-index rebuild in there). Notes:

- Options go through `update_option()`, but the loader throws, naming the filter, if a `sanitize_option_swiftforms_*` filter is attached, so a write is never silently normalised.
- Posts are inserted with `wp_insert_post( wp_slash( ... ) )`, kses off and SwiftForms' `wp_insert_post_data` callbacks detached for the insert only. The stored `post_content` is read back from the database and must equal the fixture byte for byte.
- `{{post:key}}` and `{{user:key}}` in post content and option values become the IDs created earlier in the same call. Double braces, so they never collide with SwiftForms' own `{post:id}` merge tags.
- The test environment (`pre_wp_mail` capture, `pre_http_request` blocked unless the config has `http_fixtures`, a temporary uploads dir, `Clock` frozen at 1795082400 = 2026-11-19T10:00:00Z, `mt_srand` and `Random::seed` 20261119, home and siteurl `http://example.org`) is installed by `apply()` and undone by `reset()`. `apply-cli.php` skips it: wp-env is a real site that a browser talks to.

## Scenarios

`apply` (harness self-check: site settings, raw `swiftforms_*` option bytes, post content), S1 (front-end render as guest and admin), and S10 (schema and registry) are implemented in R00. S2-S9, S11, S14-S16 must be listed in a config's `skip` (`'S2' => 'pending: R01'`) until their release implements them; a scenario that is neither implemented nor skipped fails the run. The skip list is itself snapshotted (`_skipped.txt`), so removing a skip without an implementation is visible. S12 and S13 run only in Playwright (`tests/e2e/user-configs.spec.js`).

The global snapshots are identical on every PHP version. The block builder registers nothing below PHP 8.2, so `blocks.txt` is read from SwiftForms' own block configs, `hooks.txt` leaves out `SwiftForms\Vendor\`, and `rest-routes.txt` lists only `swiftforms/v1`. Two assertions that are not snapshotted run next to them: the builder's `/config` route is never public, and (PHP 8.2+) every configured block is registered.

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
