# SwiftForms Testing

## PHP unit tests

Run the WordPress PHPUnit suite with:

```bash
WP_TESTS_DIR=/tmp/wordpress-tests-lib ./vendor/bin/phpunit --configuration phpunit.xml.dist
```

## JavaScript unit tests

Run block and frontend unit tests with:

```bash
npm run test:js -- --watch=false
```