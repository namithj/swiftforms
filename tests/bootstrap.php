<?php
/**
 * PHPUnit bootstrap for SwiftForms.
 */

declare(strict_types=1);

$plugin_dir = dirname(__DIR__);

$autoload_file = $plugin_dir . '/vendor/autoload.php';
if (file_exists($autoload_file)) {
    require_once $autoload_file;
}

$tests_dir = getenv('WP_TESTS_DIR');

if (!$tests_dir && file_exists($plugin_dir . '/vendor/wp-phpunit/wp-phpunit/includes/functions.php')) {
    $tests_dir = $plugin_dir . '/vendor/wp-phpunit/wp-phpunit';
}

if (!$tests_dir && getenv('WP_DEVELOP_DIR')) {
    $tests_dir = rtrim((string) getenv('WP_DEVELOP_DIR'), '/\\') . '/tests/phpunit';
}

if (!$tests_dir) {
    $tests_dir = $plugin_dir . '/vendor/wp-phpunit/wp-phpunit';
}

if (!$tests_dir || !file_exists($tests_dir . '/includes/functions.php')) {
    fwrite(STDERR, "The WordPress PHPUnit test library is not available. Set WP_TESTS_DIR or install vendor dependencies.\n");
    exit(1);
}

$tests_config_file = $tests_dir . '/wp-tests-config.php';
if (!file_exists($tests_config_file)) {
    $tests_config_file = $plugin_dir . '/wp-tests-config.php';
}

if (file_exists($tests_config_file)) {
    require_once $tests_config_file;
}

if (!defined('WP_TESTS_CONFIG_FILE_PATH')) {
    define('WP_TESTS_CONFIG_FILE_PATH', $tests_config_file);
}

if (!defined('WP_TESTS_PHPUNIT_POLYFILLS_PATH')) {
    define('WP_TESTS_PHPUNIT_POLYFILLS_PATH', $plugin_dir . '/vendor/yoast/phpunit-polyfills');
}

require_once $tests_dir . '/includes/functions.php';

/**
 * Loads the SwiftForms plugin under test.
 */
function swiftforms_manually_load_plugin(): void {
    require dirname(__DIR__) . '/swiftforms.php';
}
tests_add_filter('muplugins_loaded', 'swiftforms_manually_load_plugin');

require $tests_dir . '/includes/bootstrap.php';