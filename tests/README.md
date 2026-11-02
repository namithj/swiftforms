# Tests

| Command | What it runs |
|---|---|
| `composer test` | PHPUnit, everything except the `user-configs` group |
| `composer test:configs` | the user-config regression suite, one config per process (Phase C of R00) |
| `composer test:multisite` | the `multisite` group, run as a network (lazy per-site install, `wp_initialize_site`, `wpmu_drop_tables`); these tests skip on a single-site run |
| `composer i18n:pot` | extract the translatable strings with WP-CLI to `.cache/swiftforms.pot` and check them (not committed) |
| `composer test:upgrade` | upgrade-path scenarios S14-S16 (a stub until R02) |
| `composer test:all` | the three above |

## Where WordPress comes from

Tests never touch a real site. `bin/phpunit.sh` downloads a throwaway core into `.cache/wp-core/<version>/` (choose the version with `WP_CORE_VERSION`, default `latest`). Set `WP_TESTS_ABSPATH` to use a core you already have; the tests refuse to start without one.

## Database

| Variable | Default |
|---|---|
| `WP_TESTS_DB_NAME` | `swiftforms_tests` |
| `WP_TESTS_DB_HOST` | `db` |
| `WP_TESTS_DB_USER` | `root` |
| `WP_TESTS_DB_PASSWORD` | `root` |

Create it once: `mysql -h db -uroot -proot -e 'CREATE DATABASE IF NOT EXISTS swiftforms_tests'`. The table prefix is `sftests_`. The suite drops and recreates its tables, so never point it at a database you care about.

## PHP version

`PHP=php8.2 composer test` runs the suite on another PHP binary. Install dependencies with PHP 8.2 or newer (Strauss and the dev tools need it; `config.platform.php` is `8.2.0`), then run the suite with the older binary: SwiftForms and the bundled frameworks run on PHP 7.4.

## Layout

- `phpunit/bootstrap.php`, `phpunit/wp-tests-config.php`: WordPress test library wiring.
- `phpunit/tests/`: test cases. `security/BindingsSourceTest.php` is the permanent BB-18 leak test.
- `e2e/`: Playwright specs (below). `e2e/mu/sf-test-clock.php` is the test-only mu-plugin that stands the plugin's clock still.
- `user-configs/`: the 20 upgrade-safety configs, their fixtures, snapshots and the loader (see its README).

## Browser tests (Playwright)

| Command | What it runs |
|---|---|
| `npm run test:e2e` | the feature specs: `editor.spec.js` (P2), `front-end.spec.js` (P6), `admin.spec.js` (P7) |
| `npm run test:e2e:configs` | `bin/run-e2e-configs.mjs`: for each user config (not 19, the multisite one) reset the site, apply the config with `tests/user-configs/apply-cli.php`, then run `user-configs.spec.js`: **S12** (every stored post, pattern, template and part opens in the editor with no block that fails validation, and saving without an edit leaves `post_content` byte for byte) and **S13** (client validation and the focus on the error summary, the AJAX confirmation or the plain post or the redirect, two forms on one page, axe on the SwiftForms markup in three states) |

`npm run test:e2e` leaves `user-configs.spec.js` out unless `SF_CONFIG` is set. `bin/run-e2e-configs.mjs [01 12 ...]` takes slug prefixes.

The specs expect the admin user `admin` / `password` and the site at `WP_BASE_URL` (default `http://localhost:8889`). The front-end spec moves the plugin's clock to test old pages and expired tokens: set `SF_E2E_NOW_FILE` to `tests/e2e/.state/now` (the clock mu-plugin reads that file on every request; without the variable those two tests skip).

### With wp-env (CI)

`npx wp-env start`, then the two commands above. `.wp-env.json` mounts the plugin, the three fixture themes and the clock mu-plugin, and sets `SWIFTFORMS_TEST_NOW` (2026-11-19T10:00:00Z). CI does this in `.github/workflows/e2e.yml` on PHP 7.4 with WordPress 6.8 and on the latest of both.

### Without Docker (a local WordPress)

```bash
bin/setup-e2e-local-site.sh ~/sf-e2e-site           # WordPress + the plugin, three themes and the clock linked in; drops and creates the database named by DB_NAME
( cd ~/sf-e2e-site && wp server --host=localhost --port=8889 ) &
npx playwright install --with-deps chromium         # once; without root, unpack the libraries it needs and set LD_LIBRARY_PATH
SF_E2E_WP_PATH=~/sf-e2e-site npm run test:e2e:configs
SF_E2E_NOW_FILE=$PWD/tests/e2e/.state/now npm run test:e2e
```

`SF_E2E_WP_PATH` switches `bin/run-e2e-configs.mjs` from wp-env to the local WP-CLI (`wp --path=...`). The script resets that site's database for every config. Use `WP_VERSION=6.8` and `WP_BASE_URL` / `DB_NAME` to build a second site for the floor version or another port. Environment variables of the setup script: `WP_VERSION`, `WP_BASE_URL`, `DB_HOST`, `DB_USER`, `DB_PASSWORD`, `DB_NAME`.

Failures keep a screenshot and trace in `artifacts/test-results/`.
