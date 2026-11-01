# Tests

| Command | What it runs |
|---|---|
| `composer test` | PHPUnit, everything except the `user-configs` group |
| `composer test:configs` | the user-config regression suite, one config per process (Phase C of R00) |
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

`PHP=php8.2 composer test` runs the suite on another PHP binary. On PHP below 8.2 install with `composer install --ignore-platform-req=php`; the block builder then registers nothing.

## Layout

- `phpunit/bootstrap.php`, `phpunit/wp-tests-config.php`: WordPress test library wiring.
- `phpunit/tests/`: test cases. `security/BindingsSourceTest.php` is the permanent BB-18 leak test.
