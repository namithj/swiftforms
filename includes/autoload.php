<?php
/**
 * SwiftForms class autoloader.
 */

declare(strict_types=1);

$swiftforms_class_map = array(
    'SwiftForms_Blocks' => SWIFTFORMS_PATH . 'includes/class-swiftforms-blocks.php',
    'SwiftForms_CPTs' => SWIFTFORMS_PATH . 'includes/class-swiftforms-cpts.php',
    'SwiftForms_Core' => SWIFTFORMS_PATH . 'includes/class-swiftforms-core.php',
    'SwiftForms_Submissions' => SWIFTFORMS_PATH . 'includes/class-swiftforms-submissions.php',
);

spl_autoload_register(
    static function (string $class_name) use ($swiftforms_class_map): void {
        if (!isset($swiftforms_class_map[$class_name])) {
            return;
        }

        require_once $swiftforms_class_map[$class_name];
    }
);

foreach ($swiftforms_class_map as $swiftforms_class_file) {
    require_once $swiftforms_class_file;
}