# SwiftForms Testing

The test suite mirrors the plugin's code layout: every PHP class has one matching
test class, and end-to-end specs cover the editor, frontend, and admin flows.

```
tests/
├── bootstrap.php                              # PHPUnit bootstrap (loads WP + plugin)
├── php/                                       # PHP integration/unit suite (mirrors plugin root + includes/)
│   ├── test-swiftforms.php                    # swiftforms.php   → SwiftForms_Plugin_Test
│   └── includes/
│       ├── test-autoload.php                  # includes/autoload.php               → SwiftForms_Autoload_Test
│       ├── test-class-swiftforms-core.php     # includes/class-swiftforms-core.php  → SwiftForms_Core_Test
│       ├── test-class-swiftforms-cpts.php     # includes/class-swiftforms-cpts.php  → SwiftForms_CPTs_Test
│       ├── test-class-swiftforms-blocks.php   # includes/class-swiftforms-blocks.php → SwiftForms_Blocks_Test
│       └── test-class-swiftforms-submissions.php # …-submissions.php → SwiftForms_Submissions_Test
└── e2e/                                       # Playwright end-to-end specs
    ├── utils/forms.js                         # shared helpers (create form + page over REST)
    ├── editor/form-block.spec.js              # block registration / editor availability
    ├── frontend/form-submission.spec.js       # AJAX submit, required validation, math captcha
    └── admin/submissions.spec.js              # storage, detail metabox, CSV export bulk action
```

## PHP tests (PHPUnit + wp-phpunit)

The suite runs against a throwaway WordPress test database. Point the bundled
`wp-tests-config.php` at your MySQL server with the `SMARTLOGIX_SWIFTFORMS_TEST_DB_*`
environment variables and run:

```bash
export SMARTLOGIX_SWIFTFORMS_TEST_DB_NAME=swiftforms_tests
export SMARTLOGIX_SWIFTFORMS_TEST_DB_USER=root
export SMARTLOGIX_SWIFTFORMS_TEST_DB_PASSWORD=root
export SMARTLOGIX_SWIFTFORMS_TEST_DB_HOST=127.0.0.1:3306   # or "localhost:/path/to/mysqld.sock"

composer test          # or: npm run test:php
```

Create the database once beforehand:
`mysql -e "CREATE DATABASE IF NOT EXISTS swiftforms_tests"`.
The test bootstrap prefers the plugin's `wp-tests-config.php` (env-driven) over
the wp-phpunit sample, so no files need to be copied.

To use a manually installed test library instead, set `WP_TESTS_DIR`:

```bash
WP_TESTS_DIR=/tmp/wordpress-tests-lib composer test
```

## Supported platform matrix

SwiftForms 1.0 supports WordPress 6.6 through the latest stable release on PHP 8.2, 8.3, and 8.4. CI runs every combination in both single-site and multisite mode. A release job builds the production ZIP, checks its inventory and PHP syntax, and activates that ZIP in WordPress.

PHP 8.2 is a deliberate product minimum for the typed codebase. WordPress or PHP versions below the declared minimum are rejected before runtime classes load and receive a clear requirements message; older PHP support is not claimed for 1.0.

## End-to-end tests (Playwright + wp-env)

E2E specs run against a disposable Dockerized WordPress managed by
[`@wordpress/env`](https://www.npmjs.com/package/@wordpress/env). Docker must be
running.

```bash
npm install            # first time: pulls @wordpress/env
npm run env:start      # boot WordPress with the plugin mounted (downloads images on first run)
npm run test:e2e       # build assets, then run Playwright specs in tests/e2e
npm run env:stop       # stop the environment
```

Useful variants:

- `npm run test:e2e:headed` — watch the browser drive the tests.
- `npm run test:e2e -- tests/e2e/frontend` — run a single directory or spec.

The `pretest:e2e` hook rebuilds `dist/` so the mounted plugin serves current
block assets. Artifacts (traces, screenshots) are written to `artifacts/` and
are git-ignored.

## JavaScript unit tests

`npm run test:js` is wired for `@wordpress/scripts` Jest. The block sources are
currently covered end to end rather than with JS unit tests, so no `*.test.js`
files ship yet; add them under `tests/js/` mirroring `includes/blocks/` when
unit-level JS coverage is needed.
